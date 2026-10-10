=head1 NAME

runtime - host and monitoring runtime stats (JWT-protected)

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, inside the JWT group
    use runtime qw(register_runtime);
    register_runtime($db_config);

=head1 DESCRIPTION

One authenticated GET /runtime-stats route: the system readout the
deliberately minimal public /health (see public_api) must NOT expose.
The public health check stays at "am I up"; this route is for the
signed-in console and describes how the box is holding up.

Two halves, each designed to fail soft - a status readout must always
answer, so any source that cannot be read degrades that field to
C<null> instead of letting the route 500:

    host        kernel figures from /proc plus one fixed-path
                C<df -kP>. The CGI process runs in a container that
                shares the host kernel and mounts, so these numbers
                describe the HOST; the host block labels them so.

    monitoring  freshness from the database: agent check-in counts
                (last_seen within the last hour vs stale), the newest
                monitor collection (MAX(last_update)) and service
                check (MAX(last_check)), and the agent version
                census (agent_version -> count).

Admin is deliberately NOT required: any valid bearer JWT may read it,
the same policy as GET /session. Nothing secret is returned - no
passwords, tokens, keys or credential material, only capacity and
freshness numbers. Every file path and shell command below is a fixed
literal, so no request data ever reaches a file name, a command line
or SQL text (the monitoring queries take no parameters at all).

=cut

package runtime;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Scalar::Util qw(looks_like_number);

our @EXPORT_OK = qw(register_runtime);

# ---------------------------------------------------------------------------
# Host metric helpers. Each returns undef (rendered as JSON null) whenever
# its source cannot be read or parsed, so the route answers through
# degradation instead of dying. No file-level lexicals: these are plain
# named subs, like public_api's helpers.
# ---------------------------------------------------------------------------

# Host uptime in whole seconds from /proc/uptime. The reading logic is
# copied from public_api's _uptime_seconds (same kernel view, same
# int-coercion) but this copy fails to undef, not 0, so the JSON can
# distinguish "unreadable" from "just booted".
sub _uptime_seconds {
    open my $fh, '<', '/proc/uptime' or return undef;
    my $line = <$fh>;
    close $fh;
    my ($up) = split ' ', ($line // '');
    return ($up && looks_like_number($up)) ? int($up) : undef;
}

# 1/5/15-minute load averages from /proc/loadavg. The trailing fields
# (running/total processes, last pid) are deliberately not surfaced.
sub _loadavg {
    open my $fh, '<', '/proc/loadavg' or return undef;
    my $line = <$fh>;
    close $fh;
    my @f = split ' ', ($line // '');
    return undef unless @f >= 3
        && looks_like_number($f[0])
        && looks_like_number($f[1])
        && looks_like_number($f[2]);
    return {
        one_min     => $f[0] + 0,
        five_min    => $f[1] + 0,
        fifteen_min => $f[2] + 0,
    };
}

# Memory in kB from /proc/meminfo: MemTotal and MemAvailable. Available
# (not free) is the honest number - it counts reclaimable caches. On an
# old kernel without the MemAvailable row only total_kb is reported.
sub _memory_kb {
    open my $fh, '<', '/proc/meminfo' or return undef;
    my %info;
    while (my $line = <$fh>) {
        next unless $line =~ /^(MemTotal|MemAvailable):\s+(\d+)\s+kB/;
        $info{$1} = $2 + 0;
    }
    close $fh;
    return undef unless %info;
    return {
        total_kb     => $info{MemTotal},      # undef -> null when absent
        available_kb => $info{MemAvailable},  # undef on ancient kernels
    };
}

# Disk usage via df on a fixed, literal path. The command string is a
# constant with nothing interpolated into it, so there is nothing to
# inject; -kP is POSIX mode (1024-byte blocks, guaranteed single-line
# output even for long device names). Only the row whose mount point is
# /srv is accepted - guards against a stray footer or header line.
sub _disk_usage {
    my $out = `df -kP /srv 2>/dev/null`;
    for my $line (split /\n/, $out) {
        my @f = split ' ', $line;
        next unless @f >= 6
            && $f[5] eq '/srv'
            && looks_like_number($f[1]);
        my ($pct) = $f[4] =~ /(\d+)/;
        return {
            path     => '/srv',
            total_kb => $f[1] + 0,
            used_kb  => $f[2] + 0,
            avail_kb => $f[3] + 0,
            use_pct  => defined $pct ? $pct + 0 : undef,
        };
    }
    return undef;
}

sub register_runtime {
    my ($db_config) = @_;

    # @summary Runtime stats (host + monitoring health)
    # @description JWT-protected system readout the public /health must not
    # expose: host uptime/load/memory/disk (host figures - the container
    # shares the host kernel and mounts) and monitoring freshness (agents
    # reporting within the last hour vs stale, last monitor collection,
    # last service check, agent version census). Any valid token may read
    # it; admin is not required. Every field degrades to null when its
    # source, DB or /proc, cannot be read - the route itself never 500s.
    # @tags System
    # @security bearerAuth
    main::get '/runtime-stats' => sub {
        my $c = shift;

        # Auth (any valid bearer JWT) is already enforced for everything
        # registered inside the api dispatcher's JWT group. Admin is not
        # required - deliberate, matching GET /session. No request data
        # is used anywhere in this handler.
        my $host = {
            # Label: this container shares the host kernel and mounts,
            # so everything in this block describes the HOST.
            scope          => 'host (container shares the host kernel and mounts)',
            uptime_seconds => _uptime_seconds(),
            loadavg        => _loadavg(),
            memory         => _memory_kb(),
            disk           => _disk_usage(),
        };

        # Monitoring freshness + agent versions. The connection and every
        # query fail separately and quietly: whatever cannot be read
        # stays null (or drops out of the JSON's array only when the
        # whole version census fails), the rest still answers.
        my $dbh = eval {
            DBI->connect(@{$db_config}{qw/dsn username password/},
                { RaiseError => 1, AutoCommit => 1 });
        };

        my ($agents_total, $agents_reporting, $agents_stale) = (undef) x 3;
        my ($last_collection, $last_service_check);
        my $agent_versions;

        if ($dbh) {
            # Check-in freshness against the database's own NOW() - the
            # same clock that wrote last_seen - rather than the CGI
            # process's wall clock. Reporting = last_seen within the
            # last hour; stale is everything else (never-seen agents
            # included). SUM(conditions) counts matching rows; the
            # COALESCE keeps an empty agents table reporting 0/0/0
            # instead of null sums.
            my @freshness = eval {
                $dbh->selectrow_array(q{
                    SELECT COUNT(*),
                           COALESCE(SUM(last_seen IS NOT NULL
                                        AND last_seen >= NOW() - INTERVAL 1 HOUR), 0),
                           COALESCE(SUM(last_seen IS NULL
                                        OR last_seen < NOW() - INTERVAL 1 HOUR), 0)
                    FROM agents
                });
            };
            if (@freshness) {
                ($agents_total, $agents_reporting, $agents_stale) = @freshness;
            }

            # Newest successful collection across all monitors, and
            # newest service check across all services. NULL (no rows,
            # nothing ever checked) renders as JSON null by design: an
            # empty fleet has no freshness.
            ($last_collection) = eval {
                $dbh->selectrow_array('SELECT MAX(last_update) FROM monitors');
            };
            ($last_service_check) = eval {
                $dbh->selectrow_array('SELECT MAX(last_check) FROM services');
            };

            # Agent version census: the self-reported agent_version each
            # upgraded agent stamps at check-in, grouped with counts. Rows
            # that have never reported a version group under a null
            # agent_version key and sort last, so consumers can spot a
            # fleet that has not been upgraded yet.
            my $rows = eval {
                $dbh->selectall_arrayref(
                    'SELECT agent_version, COUNT(*) FROM agents GROUP BY agent_version'
                );
            };
            if (ref $rows eq 'ARRAY') {
                my @census = map {
                    { agent_version => $_->[0], count => $_->[1] + 0 }
                } @$rows;
                $agent_versions = [ sort {
                    (!defined $a->{agent_version}) <=> (!defined $b->{agent_version})
                        || (($a->{agent_version} // '') cmp ($b->{agent_version} // ''))
                } @census ];
            }

            $dbh->disconnect;
        }

        my $num = sub { defined $_[0] ? $_[0] + 0 : undef };

        return $c->render(json => {
            status => 'success',
            runtime => {
                host => $host,
                monitoring => {
                    agents_total       => $num->($agents_total),
                    agents_reporting   => $num->($agents_reporting),
                    agents_stale       => $num->($agents_stale),
                    reporting_window   => '1h',
                    last_collection    => $last_collection,
                    last_service_check => $last_service_check,
                },
                agent_versions => $agent_versions,
            },
        });
    };
}

1;