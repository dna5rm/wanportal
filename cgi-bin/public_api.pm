=head1 NAME

public_api - unauthenticated, read-only dashboard data

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, OUTSIDE the JWT group
    use public_api qw(register_public_endpoints);
    register_public_endpoints($db_config);

=head1 DESCRIPTION

The deliberately public face of the API: the health check, the
agent / target / monitor listings with per-row latency flags, the
single-item detail lookups behind the display pages, the dashboard
rollup, and RRD graph rendering. The display pages read these
without logging in - the monitoring topology is meant to be visible
to anyone who can reach the portal, and agent passwords never
appear in any response.

The detail routes share the UUID allow-list the RRD endpoint uses:
the id is checked as a UUID before it reaches the database, so a
crafted id cannot smuggle in path separators or odd bytes.

The RRD endpoint turns a monitor's collected samples into a graph
image.

=cut

package public_api;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use JSON qw(encode_json);
use RRDs;
use File::Temp;
use Time::Local;
use Date::Parse qw(str2time);
use POSIX qw(strftime);
use Scalar::Util qw(looks_like_number);

our @EXPORT_OK = qw(register_public_endpoints add_latency_fields);
my $datadir = '/var/rrd';  # RRD storage directory

# DBI hands numeric columns back as strings, sometimes as undef;
# coerce once here so the threshold math below stays warning-free.
sub _num {
    my ($v) = @_;
    $v = 0 unless defined $v && looks_like_number($v);
    return $v + 0;
}

# Host uptime in whole seconds, read straight from /proc/uptime.
# server.php used to shell out for this; the API is the natural
# owner now that the pages pull their data through it.
sub _uptime_seconds {
    open my $fh, '<', '/proc/uptime' or return 0;
    my $line = <$fh>;
    close $fh;
    my ($up) = split ' ', ($line // '');
    return ($up && looks_like_number($up)) ? int($up) : 0;
}

# Perl twin of wanportal_is_latency_issue() in
# htdocs/classic/lib/monitor_metrics.php: one shared definition of "this
# monitor is spiking right now" so API consumers and the PHP pages
# cannot drift apart on the rules. Same gates, same order:
#
#   - effectively active (monitor, agent, and target all enabled)
#   - fresh: last_update parses and is newer than 3 * pollinterval
#     seconds ago (pollinterval falls back to the DB default of 60;
#     an unparseable last_update counts as stale, matching
#     strtotime() returning false in PHP)
#   - up: current_loss < 100 - down is not a latency problem
#   - meaningful: at least 2 samples so the median is real
#   - baseline: threshold (avg_median + 2*avg_stddev) > 0
#
# The flag itself is current_median > threshold. The threshold is
# stamped on every row (0.0 when there is no baseline) so callers
# can show the bar the flag was measured against.
sub add_latency_fields {
    my ($row, $now) = @_;
    $now //= time();

    my $threshold = _num($row->{avg_median}) + 2 * _num($row->{avg_stddev});
    my $flag = 0;

    if (_num($row->{is_active}) == 1
        && _num($row->{agent_is_active}) == 1
        && _num($row->{target_is_active}) == 1) {

        my $last_update = str2time($row->{last_update} // '');
        if (defined $last_update) {
            my $pollinterval = _num($row->{pollinterval});
            $pollinterval = 60 if $pollinterval <= 0; # monitors.pollinterval DB default
            if ($last_update >= $now - 3 * $pollinterval
                && _num($row->{current_loss}) < 100
                && _num($row->{sample}) >= 2
                && $threshold > 0
                && _num($row->{current_median}) > $threshold) {
                $flag = 1;
            }
        }
    }

    $row->{latency_flag}         = $flag;
    $row->{latency_threshold_ms} = $threshold;
    return $row;
}

sub register_public_endpoints {
    my ($db_config) = @_;

    # @summary Health check
    # @description Public endpoint to verify the API is running.
    # Reports host uptime in whole seconds, read from /proc/uptime.
    # @tags System
    main::get '/health' => sub {
        my $c = shift;
        return $c->render(json => {
            status         => 'ok',
            uptime_seconds => _uptime_seconds(),
        });
    };

    # @summary List all agents
    # @description Returns a list of all monitoring agents in the system without sensitive information.
    # Passwords are never included in the response.
    # @tags Public API
    main::get '/agents' => sub {
        my $c = shift;
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        my $sth = $dbh->prepare(q{
            SELECT 
                id, name, address, description, last_seen, is_active
            FROM agents 
            ORDER BY name
        });
        $sth->execute();
        my $agents = $sth->fetchall_arrayref({});
        $sth->finish;
        $dbh->disconnect;
        
        return $c->render(json => {
            status => 'success',
            agents => $agents
        });
    };

    # @summary Get agent details
    # @description Returns a single monitoring agent without sensitive
    # information. Passwords are never included in the response.
    # @tags Public API
    main::get '/agents/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');

        # Agent ids are char(36) UUIDs; allow-list the format before
        # the id reaches the lookup, same convention as /rrd.
        return $c->render(json => {
            status => 'error',
            message => 'Invalid agent ID format'
        }, status => 400) unless $id =~ /\A[0-9a-fA-F-]{36}\z/;

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        my $sth = $dbh->prepare(q{
            SELECT id, name, address, description, last_seen, is_active
            FROM agents
            WHERE id = ?
        });
        $sth->execute($id);
        my $agent = $sth->fetchrow_hashref;
        $sth->finish;
        $dbh->disconnect;

        unless ($agent) {
            return $c->render(json => {
                status => 'error',
                message => 'Agent not found'
            }, status => 404);
        }

        return $c->render(json => {
            status => 'success',
            agent => $agent
        });
    };

    # @summary List all targets
    # @description Returns a list of all monitoring targets in the system.
    # @tags Public API
    main::get '/targets' => sub {
        my $c = shift;
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        my $sth = $dbh->prepare(q{
            SELECT 
                id, address, description, is_active
            FROM targets 
            ORDER BY address
        });
        $sth->execute();
        my $targets = $sth->fetchall_arrayref({});
        $sth->finish;
        $dbh->disconnect;
        
        return $c->render(json => {
            status => 'success',
            targets => $targets
        });
    };

    # @summary Get target details
    # @description Returns a single monitoring target.
    # @tags Public API
    main::get '/targets/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');

        # Target ids are char(36) UUIDs; same allow-list as /rrd.
        return $c->render(json => {
            status => 'error',
            message => 'Invalid target ID format'
        }, status => 400) unless $id =~ /\A[0-9a-fA-F-]{36}\z/;

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        my $sth = $dbh->prepare(q{
            SELECT id, address, description, is_active
            FROM targets
            WHERE id = ?
        });
        $sth->execute($id);
        my $target = $sth->fetchrow_hashref;
        $sth->finish;
        $dbh->disconnect;

        unless ($target) {
            return $c->render(json => {
                status => 'error',
                message => 'Target not found'
            }, status => 404);
        }

        return $c->render(json => {
            status => 'success',
            target => $target
        });
    };

    # @summary List all monitors
    # @description Returns a list of all monitors with their current status and configuration.
    # Supports filtering by status and activity state.
    # @tags Public API
    main::get '/monitors' => sub {
        my $c = shift;
        
        # Get query parameters
        my $current_loss = $c->param('current_loss');
        my $is_active = $c->param('is_active');
        my $agent_id  = $c->param('agent_id');
        my $target_id = $c->param('target_id');
        my $q         = $c->param('q');
        
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

        # Start building the SQL query
        my $sql = q{
            SELECT
                m.id, m.description, m.agent_id, m.target_id,
                m.protocol, m.port, m.dscp, m.pollcount, m.pollinterval,
                CASE
                    WHEN m.is_active = 0 OR a.is_active = 0 OR t.is_active = 0 THEN 0
                    ELSE 1
                END as is_active,
                m.is_active as monitor_is_active,
                m.sample, m.current_loss, m.current_median,
                m.current_min, m.current_max, m.current_stddev,
                m.avg_loss, m.avg_median, m.avg_min, m.avg_max,
                m.avg_stddev, m.prev_loss, m.last_clear, m.last_down,
                m.last_update, m.total_down,
                a.name as agent_name,
                a.is_active as agent_is_active,
                t.address as target_address,
                t.is_active as target_is_active
            FROM monitors m
            JOIN agents a ON m.agent_id = a.id
            JOIN targets t ON m.target_id = t.id
            WHERE 1=1
        };

        my @params;

        # Add query conditions if parameters are provided
        if (defined $current_loss) {
            $sql .= " AND m.current_loss = ?";
            push @params, $current_loss;
        }
        
        if (defined $is_active) {
            $sql .= " AND (CASE 
                WHEN m.is_active = 0 OR a.is_active = 0 OR t.is_active = 0 THEN 0 
                ELSE 1 
            END) = ?";
            push @params, $is_active;
        }

        # Filter by owning agent / target. Both are char(36) uuid
        # columns bound as parameters, so junk ids just match nothing.
        if (defined $agent_id && length $agent_id) {
            $sql .= " AND m.agent_id = ?";
            push @params, $agent_id;
        }

        if (defined $target_id && length $target_id) {
            $sql .= " AND m.target_id = ?";
            push @params, $target_id;
        }

        # Free-text q: same LIKE sweep the old search.php ran, across
        # monitor description, agent name/address, and target
        # address/description. % and _ inside $q keep acting as SQL
        # wildcards, exactly as they did there.
        if (defined $q && length $q) {
            $sql .= " AND (m.description LIKE ? OR a.name LIKE ? OR a.address LIKE ?"
                  . " OR t.address LIKE ? OR t.description LIKE ?)";
            my $like = "%$q%";
            push @params, $like, $like, $like, $like, $like;
        }

        $sql .= " ORDER BY m.description";

        my $sth = $dbh->prepare($sql);
        $sth->execute(@params);
        my $monitors = $sth->fetchall_arrayref({});
        $sth->finish;
        $dbh->disconnect;

        # Per-row latency verdict, computed with the same rules as
        # wanportal_is_latency_issue() in lib/monitor_metrics.php so
        # consumers get the flag without re-deriving it.
        add_latency_fields($_) for @$monitors;

        return $c->render(json => {
            status => 'success',
            monitors => $monitors
        });
    };

    # @summary Get monitor details
    # @description Returns a single monitor with its current status,
    # configuration, and joined agent/target fields, plus the computed
    # latency_flag / latency_threshold_ms pair also carried by /monitors
    # rows. The threshold is avg_median + 2*avg_stddev; the flag is only
    # raised for effectively-active monitors with fresh data, loss below
    # 100%, at least two samples, and a current median above the
    # threshold.
    # @tags Public API
    main::get '/monitors/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');

        # Monitor ids are char(36) UUIDs; same allow-list as /rrd.
        return $c->render(json => {
            status => 'error',
            message => 'Invalid monitor ID format'
        }, status => 400) unless $id =~ /\A[0-9a-fA-F-]{36}\z/;

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        my $sth = $dbh->prepare(q{
            SELECT
                m.id, m.description, m.agent_id, m.target_id,
                m.protocol, m.port, m.dscp, m.pollcount, m.pollinterval,
                CASE
                    WHEN m.is_active = 0 OR a.is_active = 0 OR t.is_active = 0 THEN 0
                    ELSE 1
                END as is_active,
                m.is_active as monitor_is_active,
                m.sample, m.current_loss, m.current_median,
                m.current_min, m.current_max, m.current_stddev,
                m.avg_loss, m.avg_median, m.avg_min, m.avg_max,
                m.avg_stddev, m.prev_loss, m.last_clear, m.last_down,
                m.last_update, m.total_down,
                a.name as agent_name,
                a.address as agent_address,
                a.description as agent_description,
                a.is_active as agent_is_active,
                t.address as target_address,
                t.description as target_description,
                t.is_active as target_is_active
            FROM monitors m
            JOIN agents a ON m.agent_id = a.id
            JOIN targets t ON m.target_id = t.id
            WHERE m.id = ?
        });
        $sth->execute($id);
        my $monitor = $sth->fetchrow_hashref;
        $sth->finish;
        $dbh->disconnect;

        unless ($monitor) {
            return $c->render(json => {
                status => 'error',
                message => 'Monitor not found'
            }, status => 404);
        }

        add_latency_fields($monitor);

        return $c->render(json => {
            status => 'success',
            monitor => $monitor
        });
    };

    # @summary Dashboard rollup
    # @description Aggregated health over effectively-active monitors:
    # up (loss below 1%), degraded (1% to 99%), and down (loss at 100%)
    # counts with percents rounded half-up, plus the five slowest links
    # by current median. Down monitors sort last so the slowest slots
    # go to live links first - they already get their own table on the
    # console page.
    # @tags Public API
    main::get '/dashboard' => sub {
        my $c = shift;
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

        my $sth = $dbh->prepare(q{
            SELECT
                m.id, m.description, m.agent_id, m.target_id,
                m.current_median, m.current_loss,
                a.name as agent_name,
                t.address as target_address
            FROM monitors m
            JOIN agents a ON m.agent_id = a.id
            JOIN targets t ON m.target_id = t.id
            WHERE (CASE
                WHEN m.is_active = 0 OR a.is_active = 0 OR t.is_active = 0 THEN 0
                ELSE 1
            END) = 1
        });
        $sth->execute();
        my $monitors = $sth->fetchall_arrayref({});
        $sth->finish;
        $dbh->disconnect;

        my ($up, $degraded, $down) = (0, 0, 0);
        for my $m (@$monitors) {
            my $loss = _num($m->{current_loss});
            if    ($loss >= 100) { $down++ }
            elsif ($loss >= 1)   { $degraded++ }
            else                 { $up++ }
        }
        my $total = scalar @$monitors;

        # PHP's round() breaks halves upward, and the console page used
        # to compute these percentages that way; int(x + 0.5) matches
        # it for these positive values.
        my $pct = sub {
            my ($n) = @_;
            return $total > 0 ? int(100 * $n / $total + 0.5) : 0;
        };

        # Slowest first, down monitors pushed to the end (they would
        # only reach the top five when fewer than five live links exist,
        # matching the old page-side sort).
        my @sorted = sort {
            (my $a_down = _num($a->{current_loss}) >= 100 ? 1 : 0)
                <=> (my $b_down = _num($b->{current_loss}) >= 100 ? 1 : 0)
                or _num($b->{current_median}) <=> _num($a->{current_median})
        } @$monitors;

        my @top_five = @sorted > 5 ? @sorted[0 .. 4] : @sorted;
        my @top_slow = map {
            {
                id             => $_->{id},
                description    => $_->{description},
                agent_id       => $_->{agent_id},
                target_id      => $_->{target_id},
                agent_name     => $_->{agent_name},
                target_address => $_->{target_address},
                current_median => _num($_->{current_median}),
                current_loss   => _num($_->{current_loss}),
            }
        } @top_five;

        return $c->render(json => {
            status => 'success',
            dashboard => {
                total            => $total,
                up               => $up,
                degraded         => $degraded,
                down             => $down,
                percent_up       => $pct->($up),
                percent_degraded => $pct->($degraded),
                percent_down     => $pct->($down),
                top_slow         => \@top_slow,
            },
        });
    };

    # @summary Get RRD data
    # @description Retrieves monitoring data from RRD files. Can return raw data or generate graphs.
    # Supports both RTT and loss metrics with customizable time ranges.
    # @tags Public API
    main::get '/rrd' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $cmd = $c->param('cmd') // '';
        my $ds = $c->param('ds') // 'rtt';
        my $start = $c->param('start');
        my $end = $c->param('end');
        
        # Validate monitor ID
        return $c->render(json => {
            status => 'error',
            message => 'Monitor ID required'
        }, status => 400) unless $id;

        # Monitor IDs are UUIDs (char(36) columns, Data::UUID create_str).
        # Allowlist the format before the id is used in any DB lookup or
        # RRD filename concat, so it can never introduce path separators
        # or traversal segments. Non-matching ids get a 400.
        return $c->render(json => {
            status => 'error',
            message => 'Invalid monitor ID format'
        }, status => 400) unless $id =~ /\A[0-9a-fA-F-]{36}\z/;

        # Get monitor description from database
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        my $sth = $dbh->prepare("SELECT description FROM monitors WHERE id = ?");
        $sth->execute($id);
        my $monitor = $sth->fetchrow_hashref();
        $dbh->disconnect;
        
        my $metric_type = $ds eq 'rtt' ? 'Response Time: ' : 'Packet Loss: ';
        my $monitor_name = ($monitor && $monitor->{description}) ? $monitor->{description} : $id;
        my $title = $metric_type . $monitor_name;
        # $id passed the UUID allowlist above, so this concat cannot
        # escape $datadir.
        my $rrdfile = $datadir . '/' . $id . '.rrd';
        
        # Check if RRD file exists
        return $c->render(json => {
            status => 'error',
            message => 'RRD file not found'
        }, status => 404) unless -f $rrdfile;

        # Convert datetime parameters to epoch
        if ($start) {
            $start =~ s/T/ /;
            $start = str2time($start);
        } else {
            $start = time() - (3 * 3600); # Default 3 hours ago
        }
        
        if ($end) {
            $end =~ s/T/ /;
            $end = str2time($end);
        } else {
            $end = time(); # Default to now
        }

        # If cmd=graph, generate and return PNG
        if ($cmd eq 'graph') {
            my ($tmpfh, $tmpfile) = File::Temp::tempfile(
                'rrdgraph_XXXXXX',
                DIR => '/tmp',
                SUFFIX => '.png',
                UNLINK => 1
            );

            # Use UTC for all operations
            my $hours = int(($end - $start) / 3600);
            
            # Format time in UTC
            my $display_time = strftime("%Y/%m/%d %H\\:%M UTC", gmtime($start));

            my @graph_opts = (
                $tmpfile,
                '--start', $start,
                '--end', $end,
                '--width', '600',
                '--height', '105',
                '--color', 'BACK#F3F3F3',
                '--color', 'CANVAS#FDFDFD',
                '--color', 'SHADEA#CBCBCB',
                '--color', 'SHADEB#999999',
                '--color', 'FONT#000000',
                '--color', 'AXIS#2C4D43',
                '--color', 'ARROW#2C4D43',
                '--color', 'FRAME#2C4D43',
                '--font', 'TITLE:10:Arial',
                '--font', 'AXIS:8:Arial',
                '--font', 'LEGEND:9:Courier',
                '--font', 'UNIT:8:Arial',
                '--font', 'WATERMARK:7:Arial',
                '--border', '1',
                '--title', $title,
                '--vertical-label', ($ds eq 'rtt' ? 'milliseconds' : 'percent'),
                '--slope-mode',
                '--alt-autoscale',
                '--rigid',
                '--lower-limit', '0'
            );

            # Add upper limit for loss graphs
            push @graph_opts, '--upper-limit', '100' if $ds eq 'loss';

            push @graph_opts, (
                'DEF:data=' . $rrdfile . ':' . $ds . ':LAST',
                'LINE3:data#FF0000:' . ($ds eq 'rtt' ? 'Latency' : 'Loss'),
                'GPRINT:data:AVERAGE:Avg\\: %6.2lf',
                'GPRINT:data:MIN:Min\\: %6.2lf',
                'GPRINT:data:MAX:Max\\: %6.2lf',
                'GPRINT:data:LAST:Last\\: %6.2lf\\j',
                'COMMENT:' . $display_time . ' (+' . $hours . ' hours)\\r'
            );

            RRDs::graph(@graph_opts);
            if (my $err = RRDs::error()) {
                return $c->render(json => {
                    status => 'error',
                    message => "Failed to generate graph: $err"
                }, status => 500);
            }

            # Read the generated PNG
            open my $fh, '<', $tmpfile or die "Cannot open temp file: $!";
            binmode $fh;
            my $png_data = do { local $/; <$fh> };
            close $fh;

            # Return PNG image
            return $c->render(
                data => $png_data,
                format => 'png'
            );
        }
        
        # If no cmd specified, dump RRD data as JSON
        my ($fetch_start, $step, $names, $data) = RRDs::fetch(
            $rrdfile,
            'LAST',
            '--start', $start,
            '--end', $end
        );
        
        if (my $err = RRDs::error()) {
            return $c->render(json => {
                status => 'error',
                message => "Failed to fetch RRD data: $err"
            }, status => 500);
        }

        # Format data for JSON response
        my @formatted_data;
        my $time = $fetch_start;
        foreach my $line (@$data) {
            push @formatted_data, {
                timestamp => $time,
                datetime => strftime("%Y-%m-%d %H:%M:%S", localtime($time)),
                loss => $line->[0],
                rtt => $line->[1]
            };
            $time += $step;
        }

        return $c->render(json => {
            status => 'success',
            start_time => $fetch_start,
            end_time => $time - $step,
            step => $step,
            data => \@formatted_data
        });
    };
}

# Helper function to validate datetime format
sub is_valid_datetime {
    my $dt = shift;
    return $dt =~ /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
}

1;
