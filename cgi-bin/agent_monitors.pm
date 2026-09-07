=head1 NAME

agent_monitors - the agent-facing polling endpoints (agent password auth)

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, OUTSIDE the JWT group
    use agent_monitors qw(register_agent_monitors);
    register_agent_monitors($db_config);

=head1 DESCRIPTION

The contract between the portal and its pollers. The GET hands an
agent the active monitor assignments that are due for polling; the
POST accepts a batch of results and files them into each monitor's
RRD file.

These routes deliberately sit outside the JWT group. A running
agent knows only its own id and its agent password - it never holds
a user JWT. Every call is checked against the password stored in
the agents table with a constant-time comparison, and the agent's
address and last-seen time are refreshed along the way. Submitted
results are only filed against monitors that belong to the
authenticated agent, and a stored address is always the validated
first X-Forwarded-For hop or the direct peer address.

=cut

package agent_monitors;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use File::Path qw(make_path);
use RRDs;
use List::Util qw(min max sum);
use Socket qw(AF_INET AF_INET6 inet_pton);
use Mojo::Util qw(secure_compare);

our @EXPORT_OK = qw(register_agent_monitors);

sub register_agent_monitors {
    my ($db_config) = @_;

    my $datadir = '/var/rrd';

    # Utility: check agent credentials and update address each call
    sub _validate_agent {
        my ($c, $agent_id, $password) = @_;
        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, {RaiseError=>1,AutoCommit=>1});
        my $agent = $dbh->selectrow_hashref("SELECT id, password FROM agents WHERE id=? OR name=?", undef, $agent_id, $agent_id);
        unless ($agent) {
            $dbh->disconnect;
            $c->render(json => {status=>'error', message=>'Invalid agent credentials'}, status=>401);
            return;
        }
        # Constant-time comparison so response timing cannot be used to
        # recover the stored agent password byte by byte.
        unless (defined $agent->{password} && defined $password
            && secure_compare($agent->{password}, $password)) {
            $dbh->disconnect;
            $c->render(json => {status=>'error', message=>'Invalid agent credentials'}, status=>401);
            return;
        }
        # Update agent address: only the first X-Forwarded-For hop (or the
        # direct peer address when the header is absent or invalid), and
        # only if it validates as IPv4/IPv6 before it is stored. With no
        # valid address, only last_seen is refreshed.
        my $xff = $c->req->headers->header('X-Forwarded-For') // '';
        my ($ip) = split /\s*,\s*/, $xff, 2;
        $ip = '' unless defined $ip;
        $ip =~ s/^\s+|\s+$//g;
        $ip = $c->tx->remote_address // '' unless _is_valid_ip($ip);
        $ip = ''                           unless _is_valid_ip($ip);
        if ($ip eq '') {
            $dbh->do("UPDATE agents SET last_seen=NOW() WHERE id=?", undef, $agent->{id});
        }
        else {
            $dbh->do("UPDATE agents SET address=?, last_seen=NOW() WHERE id=?", undef, $ip, $agent->{id});
        }
        $dbh->disconnect;
        return $agent->{id};
    }

    # Utility: strict IPv4/IPv6 validation for stored agent addresses
    sub _is_valid_ip {
        my ($ip) = @_;
        return 0 unless defined $ip && length $ip;
        return 1 if inet_pton(AF_INET, $ip);
        return 1 if inet_pton(AF_INET6, $ip);
        return 0;
    }

    # @summary Get agent's monitor assignments
    # @description Returns a list of active monitors assigned to the specified agent.
    # Only returns monitors that are due for polling based on their interval.
    # @tags Agent Monitors
    main::get '/agent/:id/monitors' => sub {
        my $c = shift;
        my $request_body = $c->req->json;
        my $password = $request_body->{password} // '';
        my $agent_id = $c->param('id');
        # Authenticate and update address
        my $db_id = _validate_agent($c, $agent_id, $password) or return;

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, { RaiseError=>1, AutoCommit=>1 });

        # Check agent is active
        my ($agent_active) = $dbh->selectrow_array("SELECT is_active FROM agents WHERE id = ?", undef, $db_id);
        unless ($agent_active) {
            $dbh->disconnect;
            return $c->render(json => {status=>'success', monitors=>[]});
        }

        # Only select necessary fields for active monitors that are due
        my $sql = q{
            SELECT 
                m.id,
                t.address,
                m.protocol,
                m.port,
                m.dscp,
                m.pollcount,
                m.pollinterval
            FROM monitors m
            JOIN targets t ON t.id = m.target_id
            WHERE m.agent_id = ?
                AND m.is_active = 1
                AND t.is_active = 1
                AND
                    (
                    m.sample = 0
                    OR m.last_update IS NULL
                    OR UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(m.last_update) >= m.pollinterval
                    )
        };
        
        my $sth = $dbh->prepare($sql);
        $sth->execute($db_id);
        my @monitors;
        while (my $m = $sth->fetchrow_hashref) {
            # Ensure default values are set
            $m->{protocol} //= 'ICMP';
            $m->{port} //= 0;
            $m->{dscp} //= 'BE';
            $m->{pollcount} //= 20;
            $m->{pollinterval} //= 60;
            
            push @monitors, $m;
        }
        $dbh->disconnect;
        $c->render(json => {status=>'success', monitors=>\@monitors});
    };

    # @summary Submit monitor results
    # @description Accepts monitoring results from an agent and updates the monitor statistics.
    # Creates or updates RRD files for data storage.
    # @tags Agent Monitors
    main::post '/agent/:id/monitors' => sub {
        my $c = shift;
        my $request_body = $c->req->json;
        my $password = $request_body->{password} // '';
        my $agent_id = $c->param('id');
        my $results = $request_body->{results} || [];
        my $db_id = _validate_agent($c, $agent_id, $password) or return;

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, {RaiseError=>1,AutoCommit=>1});

        # Ensure RRD directory exists
        unless (-d $datadir) {
            print STDERR "Creating RRD directory: $datadir\n";
            make_path($datadir);
        }

        foreach my $r (@$results) {
            # Validate required fields
            unless (defined $r->{id} && 
                defined $r->{min} && 
                defined $r->{max} && 
                defined $r->{median} && 
                defined $r->{loss} && 
                defined $r->{stddev}) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Missing required fields (id, min, max, median, loss, stddev)'
                }, status => 400);
            }

            # Get monitor configuration for RRD step, scoped to the
            # authenticated agent so results can only be filed against
            # monitors that belong to this agent
            my $monitor_config = $dbh->selectrow_hashref(
                "SELECT pollinterval, id FROM monitors WHERE id=? AND agent_id=?",
                undef,
                $r->{id}, $db_id
            );

            unless ($monitor_config) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => "Invalid monitor ID: $r->{id}"
                }, status => 400);
            }

            my $step = $monitor_config->{pollinterval} // 60;

            # Check if host is down (100% loss and 0 RTT)
            my $is_down = ($r->{loss} == 100 && $r->{median} == 0);

            # Get current stats for running averages
            my $curr = $dbh->selectrow_hashref(
                "SELECT sample, avg_loss, avg_median, avg_min, avg_max, avg_stddev, prev_loss, total_down FROM monitors WHERE id=? AND agent_id=?",
                undef,
                $r->{id}, $db_id
            );

            # Only update averages if the host is not down
            my ($sample, $avg_loss, $avg_median, $avg_min, $avg_max, $avg_stddev);
            if (!$is_down) {
                $sample = ($curr->{sample} // 0) + 1;  # Increment sample count
                $avg_loss   = defined $curr->{avg_loss}   ? ((($curr->{avg_loss}   * ($sample-1)) + $r->{loss})   / $sample) : $r->{loss};
                $avg_median = defined $curr->{avg_median} ? ((($curr->{avg_median} * ($sample-1)) + $r->{median}) / $sample) : $r->{median};
                $avg_min    = defined $curr->{avg_min}    ? ((($curr->{avg_min}    * ($sample-1)) + $r->{min})    / $sample) : $r->{min};
                $avg_max    = defined $curr->{avg_max}    ? ((($curr->{avg_max}    * ($sample-1)) + $r->{max})    / $sample) : $r->{max};
                $avg_stddev = defined $curr->{avg_stddev} ? ((($curr->{avg_stddev} * ($sample-1)) + $r->{stddev}) / $sample) : $r->{stddev};
            } else {
                $sample = $curr->{sample} // 0;  # Keep existing sample count
                # If host is down, keep existing averages
                $avg_loss   = $curr->{avg_loss};
                $avg_median = $curr->{avg_median};
                $avg_min    = $curr->{avg_min};
                $avg_max    = $curr->{avg_max};
                $avg_stddev = $curr->{avg_stddev};
            }

            # Downtime tracking
            my $total_down = $curr->{total_down} || 0;
            my $set_last_down = '';

            # Update last_down only when transitioning from up (0) to down (100)
            if ($r->{loss} == 100 && defined $curr->{prev_loss} && $curr->{prev_loss} == 0) {
                $set_last_down = "last_down = NOW(),";
            }

            # Increment total_down whenever loss is 100%
            if ($r->{loss} == 100) {
                $total_down++;
            }

            # Update database
            my $sql = qq{
                UPDATE monitors SET
                    sample         = ?,
                    current_loss   = ?,
                    current_median = ?,
                    current_min    = ?,
                    current_max    = ?,
                    current_stddev = ?,
                    avg_loss       = ?,
                    avg_median     = ?,
                    avg_min        = ?,
                    avg_max        = ?,
                    avg_stddev     = ?,
                    prev_loss      = ?,
                    last_update    = NOW(),
                    $set_last_down
                    total_down     = ?
                WHERE id = ? AND agent_id = ?
            };
            $sql =~ s/,\s+,/,/g;
            $sql =~ s/,$//g;
            
            $dbh->do($sql, undef,
                $sample, 
                $r->{loss}, $r->{median}, $r->{min}, $r->{max}, $r->{stddev},
                $avg_loss, $avg_median, $avg_min, $avg_max, $avg_stddev,
                $r->{loss}, $total_down, $r->{id}, $db_id
            );

            # RRD handling: the filename uses the monitor id as stored in
            # the database (canonical form), never the raw posted value
            my $rrdfile = "$datadir/$monitor_config->{id}.rrd";
            print STDERR "RRD: attempt create/update $rrdfile (loss=$r->{loss}, rtt=" . 
                ($is_down ? "U" : $r->{median}) . ")\n";
            
            unless (-e $rrdfile) {
                print STDERR "RRD: creating $rrdfile with step $step\n";
                RRDs::create(
                    $rrdfile, 
                    '--step', $step,
                    'DS:loss:GAUGE:'.($step*3).':0:100',
                    'DS:rtt:GAUGE:'.($step*3).':0:U',
                    'RRA:LAST:0.5:1:525600'
                );
                my $ERR = RRDs::error;
                if ($ERR) {
                    $c->app->log->error("RRD create $rrdfile: $ERR");
                    print STDERR "RRD error: $ERR\n";
                }
            }

            # Update RRD with current timestamp, using 'U' for RTT when host is down
            my $now = time();
            my $rtt_value = $is_down ? 'U' : $r->{median};
            RRDs::update($rrdfile, '--template', 'loss:rtt', "$now:$r->{loss}:$rtt_value");
            my $ERR = RRDs::error;
            if ($ERR) {
                $c->app->log->error("RRD update $rrdfile: $ERR");
                print STDERR "RRD error: $ERR\n";
            }
        }

        $dbh->disconnect;
        $c->render(json => {status=>'success'});
    };
}

1;