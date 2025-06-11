package monitor;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Data::UUID;
use File::Path qw(make_path);
use File::Basename qw(dirname);

our @EXPORT_OK = qw(register_monitor);

sub ensure_monitors_table {
    my ($dbh) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS monitors (
            id char(36) NOT NULL,
            description varchar(255) DEFAULT '',
            agent_id char(36) NOT NULL,
            target_id char(36) NOT NULL,
            protocol varchar(10) DEFAULT 'icmp',
            port int(11) DEFAULT 0,
            dscp varchar(10) DEFAULT 'BE',
            pollcount int(11) DEFAULT 5,
            pollinterval int(11) DEFAULT 60,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            sample bigint(20) DEFAULT 0,
            current_loss int(11) DEFAULT 0,
            current_median float DEFAULT 0,
            current_min float DEFAULT 0,
            current_max float DEFAULT 0,
            current_stddev float DEFAULT 0,
            avg_loss int(11) DEFAULT 0,
            avg_median float DEFAULT 0,
            avg_min float DEFAULT 0,
            avg_max float DEFAULT 0,
            avg_stddev float DEFAULT 0,
            prev_loss int(11) DEFAULT 0,
            last_clear datetime DEFAULT current_timestamp(),
            last_down datetime DEFAULT current_timestamp(),
            last_update datetime DEFAULT current_timestamp(),
            total_down int(11) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY monitor_uniqueness (agent_id, target_id, protocol, port, dscp),
            KEY monitors_ibfk_2 (target_id)
            -- Foreign keys may be added as appropriate
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    });
}

sub register_monitor {
    my ($db_config, $valid_protocols, $valid_dscp) = @_;

    # On registration, ensure the monitors table exists
    my $dbh = DBI->connect(
        $db_config->{dsn}, $db_config->{username}, $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    ensure_monitors_table($dbh);
    $dbh->disconnect if $dbh;

    # RRD base directory
    my $datadir = '/var/rrd';

    # --- CREATE ---
    main::post '/monitor' => sub {
        my $c    = shift;
        my $data = $c->req->json;

        # Require agent_id and target_id only
        foreach my $field (qw/agent_id target_id/) {
            unless (defined $data->{$field} && $data->{$field} ne '') {
                return $c->render(json => { status => 'error', message => "Missing required field: $field" }, status => 400);
            }
        }

        # Provide defaults
        $data->{protocol} = defined $data->{protocol} && $data->{protocol} ne '' ? $data->{protocol} : 'ICMP';
        $data->{dscp}     = defined $data->{dscp}     && $data->{dscp}     ne '' ? $data->{dscp}     : 'BE';

        # Validate protocol/dscp
        my $protocol = uc($data->{protocol});
        return $c->render(json => { status => 'error', message => 'Invalid protocol' }, status => 400)
            unless $valid_protocols->{$protocol};

        my $dscp = uc($data->{dscp});
        return $c->render(json => { status => 'error', message => 'Invalid DSCP value' }, status => 400)
            unless $valid_dscp->{$dscp};

        # Connect and create monitor row
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError=>1, AutoCommit=>1 });
        my $uuid = Data::UUID->new->create_str;

        my $sth = $dbh->prepare(
            "INSERT INTO monitors (id, description, agent_id, target_id, protocol, port, dscp, pollcount, pollinterval, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        eval {
            $sth->execute(
                $uuid,
                $data->{description} // '',
                $data->{agent_id},
                $data->{target_id},
                $protocol,
                $data->{port} // 0,
                $dscp,
                $data->{pollcount} // 5,
                $data->{pollinterval} // 60,
                defined $data->{is_active} ? $data->{is_active} : 1,
            );
        };
        $dbh->disconnect;

        if ($@) {
            return $c->render(json => { status => 'error', message => 'Failed to add monitor' }, status => 500);
        } else {
            return $c->render(json => { status => 'success', id => $uuid });
        }
    };

    # --- READ ---
    main::get '/monitor' => sub {
        my $c = shift;
        my $query = $c->req->json || {};
        my @filters;
        my @params;
        if ($query->{id})         { push @filters, "id = ?";        push @params, $query->{id}; }
        if ($query->{agent_id})   { push @filters, "agent_id = ?";  push @params, $query->{agent_id}; }
        if ($query->{target_id})  { push @filters, "target_id = ?"; push @params, $query->{target_id}; }
        if ($query->{protocol})   { push @filters, "protocol = ?";  push @params, $query->{protocol}; }
        if ($query->{is_active})  { push @filters, "is_active = ?"; push @params, $query->{is_active} ? 1 : 0; }

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError=>1, AutoCommit=>1 });
        my $sql = "SELECT * FROM monitors";
        $sql .= " WHERE " . join(" AND ", @filters) if @filters;
        my $sth = $dbh->prepare($sql);
        $sth->execute(@params);
        my $monitors = $sth->fetchall_arrayref({});
        $dbh->disconnect;
        $c->render(json => {status=>'success', monitors=>$monitors});
    };

    # --- UPDATE ---
    main::put '/monitor' => sub {
        my $c = shift;
        my $data = $c->req->json;
        unless ($data && $data->{id}) {
            return $c->render(json => { status => 'error', message => 'Missing monitor id' }, status => 400);
        }
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError=>1, AutoCommit=>1 });
        my @set; my @params;
        foreach my $field (qw/description agent_id target_id protocol port dscp pollcount pollinterval is_active/) {
            if (defined $data->{$field}) {
                push @set, "$field=?";
                push @params, $data->{$field};
            }
        }
        unless (@set) {
            $dbh->disconnect;
            return $c->render(json => { status => 'error', message => 'No fields to update' }, status => 400);
        }
        push @params, $data->{id};
        my $sql = "UPDATE monitors SET " . join(", ", @set) . " WHERE id = ?";
        eval {
            my $sth = $dbh->prepare($sql);
            $sth->execute(@params);
        };
        $dbh->disconnect;
        return $@ ? $c->render(json => { status => 'error', message => 'Failed to update monitor' }, status => 500)
                  : $c->render(json => { status => 'success', message => 'Monitor updated' });
    };

    # --- DELETE (also delete RRD) ---
    main::del '/monitor' => sub {
        my $c = shift;
        my $data = $c->req->json;
        my $id = $data->{id} or do {
            $c->app->log->error("[monitor DELETE] Missing id field!");
            return $c->render(json => {status=>'error', message=>'Missing id'}, status=>400);
        };
        $c->app->log->debug("[monitor DELETE] Called with id=$id");

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, { RaiseError => 1, AutoCommit => 1 });

        my $sth = $dbh->prepare("DELETE FROM monitors WHERE id=?");
        my $rows = $sth->execute($id);
        $dbh->disconnect;

        my $rrdfile = "/var/rrd/$id.rrd";
        if ($rows && -e $rrdfile) {
            if (unlink $rrdfile) {
                $c->app->log->debug("[monitor DELETE] Deleted RRD file $rrdfile");
                print STDERR "[monitor DELETE] Deleted RRD file $rrdfile\n";
            } else {
                $c->app->log->error("[monitor DELETE] Could not delete RRD file $rrdfile: $!");
                print STDERR "[monitor DELETE] Could not delete RRD file $rrdfile: $!\n";
            }
        } elsif ($rows) {
            $c->app->log->debug("[monitor DELETE] No RRD file $rrdfile for monitor $id (nothing to delete)");
        }

        return $rows
            ? $c->render(json => {status=>'success', message=>'Monitor deleted and RRD removed if existed.'})
            : $c->render(json => {status=>'error', message=>'Monitor not found'}, status=>404);
    };
}

1;