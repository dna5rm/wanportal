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
        $dbh->do("UPDATE agents SET address=? WHERE id=?", undef, $ip, $agent->{id});
        $dbh->disconnect;
        return $agent->{id};
    }

    # GET: assignment for agent, expects password JSON
    main::get '/agent/:id/monitors' => sub {
        my $c = shift;
        my $request_body = $c->req->json;
        my $password = $request_body->{password} // '';
        my $agent_id = $c->param('id');
        my $db_id = _validate_agent($c, $agent_id, $password) or return;

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, { RaiseError=>1, AutoCommit=>1 });
        my $sql = q{
            SELECT m.id, t.address, m.protocol, m.port, m.dscp,
                   m.pollcount, m.pollinterval
            FROM monitors m
            JOIN targets t ON t.id = m.target_id
            WHERE m.agent_id=?
              AND m.is_active=1
              AND (m.last_update IS NULL OR
                   UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(m.last_update) >= m.pollinterval)
        };
        my $sth = $dbh->prepare($sql);
        $sth->execute($db_id);
        my @monitors;
        while (my $m = $sth->fetchrow_hashref) {
            push @monitors, $m;
        }
        $dbh->disconnect;
        $c->render(json => {status=>'success', monitors=>\@monitors});
    };

    # POST: results update from agent, expects password/results in JSON
    main::post '/agent/:id/monitors' => sub {
        my $c = shift;
        my $request_body = $c->req->json;
        my $password = $request_body->{password} // '';
        my $agent_id = $c->param('id');
        my $results = $request_body->{results} || [];
        my $db_id = _validate_agent($c, $agent_id, $password) or return;

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, {RaiseError=>1,AutoCommit=>1});

        # --- Debug/diagnostics ---
        unless (-d $datadir) {
            print STDERR "Creating RRD directory: $datadir\n";
        }
        make_path($datadir) unless -d $datadir;

        foreach my $r (@$results) {
            my $monitor_id   = $r->{monitor_id} or next;
            my $loss         = $r->{loss} // 0;
            my $median       = $r->{median} // 0;
            my $min          = $r->{min} // 0;
            my $max          = $r->{max} // 0;
            my $stddev       = $r->{stddev} // 0;
            my $prev_loss    = $loss;

            # Get current sample/averages for running average
            my $curr = $dbh->selectrow_hashref(
                "SELECT sample, avg_loss, avg_median, avg_min, avg_max, avg_stddev, prev_loss, total_down FROM monitors WHERE id=?", undef, $monitor_id
            );
            my $sample = ($curr->{sample} // 0) + 1;
            my $avg_loss   = defined $curr->{avg_loss}   ? ((($curr->{avg_loss}   * ($sample-1)) + $loss   ) / $sample) : $loss;
            my $avg_median = defined $curr->{avg_median} ? ((($curr->{avg_median}* ($sample-1)) + $median ) / $sample) : $median;
            my $avg_min    = defined $curr->{avg_min}    ? ((($curr->{avg_min}   * ($sample-1)) + $min    ) / $sample) : $min;
            my $avg_max    = defined $curr->{avg_max}    ? ((($curr->{avg_max}   * ($sample-1)) + $max    ) / $sample) : $max;
            my $avg_stddev = defined $curr->{avg_stddev} ? ((($curr->{avg_stddev}* ($sample-1)) + $stddev ) / $sample) : $stddev;

            # Downtime logic
            my $total_down = $curr->{total_down} || 0;
            my $set_last_down = '';
            if ($loss == 100 && (!$curr->{prev_loss} || $curr->{prev_loss} < 100)) {
                $total_down++;
                $set_last_down = "last_down = NOW(),";
            }

            # --- MySQL update
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
            my @params = (
                $sample, $loss, $median, $min, $max, $stddev,
                $avg_loss, $avg_median, $avg_min, $avg_max, $avg_stddev,
                $loss, $total_down, $monitor_id
            );
            $dbh->do($sql, undef, @params);

            ### --- RRD logic --- ###
            my $rrdfile = "$datadir/$monitor_id.rrd";
            print STDERR "RRD: attempt create/update $rrdfile (loss=$loss, rtt/median=$median)\n";
            unless (-e $rrdfile) {
                print STDERR "RRD: creating $rrdfile\n";
                RRDs::create(
                    $rrdfile, '-s', 60,
                    'DS:loss:GAUGE:180:U:U',
                    'DS:rtt:GAUGE:180:U:U',
                    'RRA:LAST:0.5:1:525600'
                );
                my $ERR = RRDs::error;
                if ($ERR) {
                    $c->app->log->error("RRD create $rrdfile: $ERR");
                    print STDERR "RRD error: $ERR\n";
                }
            }
            RRDs::update($rrdfile, '-t', 'loss:rtt', "N:$loss:$median");
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