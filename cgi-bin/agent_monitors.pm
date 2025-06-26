package agent_monitors;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use File::Path qw(make_path);
use RRDs;
use List::Util qw(min max sum);

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
            $c->render(json => {status=>'error', message=>'Invalid agent id'}, status=>401);
            return;
        }
        unless (defined $agent->{password} && $agent->{password} eq $password) {
            $dbh->disconnect;
            $c->render(json => {status=>'error', message=>'Invalid password'}, status=>401);
            return;
        }
        # Update agent address
        my $ip = $c->req->headers->header('X-Forwarded-For');
        $ip = $c->tx->remote_address unless defined $ip && $ip ne '';
        $dbh->do("UPDATE agents SET address=?, last_seen=NOW() WHERE id=?", undef, $ip, $agent->{id});
        $dbh->disconnect;
        return $agent->{id};
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

            # Get monitor configuration for RRD step
            my $monitor_config = $dbh->selectrow_hashref(
                "SELECT pollinterval FROM monitors WHERE id=?", 
                undef, 
                $r->{id}
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
                "SELECT sample, avg_loss, avg_median, avg_min, avg_max, avg_stddev, prev_loss, total_down FROM monitors WHERE id=?", 
                undef, 
                $r->{id}
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
                WHERE id = ?
            };
            $sql =~ s/,\s+,/,/g;
            $sql =~ s/,$//g;
            
            $dbh->do($sql, undef,
                $sample, 
                $r->{loss}, $r->{median}, $r->{min}, $r->{max}, $r->{stddev},
                $avg_loss, $avg_median, $avg_min, $avg_max, $avg_stddev,
                $r->{loss}, $total_down, $r->{id}
            );

            # RRD handling
            my $rrdfile = "$datadir/$r->{id}.rrd";
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