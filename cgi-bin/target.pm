package target;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Data::UUID;
use Regexp::Common qw(net);

our @EXPORT_OK = qw(register_target);

sub ensure_targets_table {
    my ($dbh) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS targets (
            id char(36) NOT NULL,
            address varchar(255) NOT NULL,
            description varchar(255) DEFAULT '',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY address (address),
            CONSTRAINT chk_valid_address CHECK (
                address regexp '^([0-9]{1,3}\\.){3}[0-9]{1,3}$' or
                address regexp '^[0-9a-fA-F:]+$' or
                address regexp '^[a-zA-Z0-9.-]+$'
            )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    });
}

sub register_target {
    my ($db_config) = @_;
    my $dbh = DBI->connect(
        $db_config->{dsn}, $db_config->{username}, $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    ensure_targets_table($dbh);
    $dbh->disconnect if $dbh;

    main::get '/target' => sub {
        my $c = shift;
        $c->app->log->debug("Received request for /target GET");
        my $db_config = $c->app->defaults->{db};
        my $dbh = DBI->connect($db_config->{dsn}, $db_config->{username}, $db_config->{password}, { RaiseError => 1, AutoCommit => 1 });
        unless ($dbh) {
            $c->app->log->error("Failed to connect to the database: $DBI::errstr");
            return $c->render(json => { status => 'error', message => 'Database connection failed' }, status => 500);
        }
        my $query = $c->req->json || {};
        my @filters;
        my @params;
        if ($query->{id})       { push @filters, "id = ?"; push @params, $query->{id}; }
        if ($query->{address})  { push @filters, "address = ?";  push @params, $query->{address}; }
        if (defined $query->{is_active}) { push @filters, "is_active = ?"; push @params, $query->{is_active} ? 1 : 0; }
        my $sql = "SELECT id, address, description, is_active FROM targets";
        $sql .= " WHERE " . join(" AND ", @filters) if @filters;
        my $sth = $dbh->prepare($sql);
        $sth->execute(@params);
        my $targets = $sth->fetchall_arrayref({});
        return $c->render(json => { status => 'success', data => $targets });
    };

    main::post '/target' => sub {
        my $c = shift;
        $c->app->log->debug("Received request for /target POST");
        my $db_config = $c->app->defaults->{db};
        my $dbh = DBI->connect($db_config->{dsn}, $db_config->{username}, $db_config->{password}, { RaiseError => 1, AutoCommit => 1 });
        unless ($dbh) {
            $c->app->log->error("Failed to connect to the database: $DBI::errstr");
            return $c->render(json => { status => 'error', message => 'Database connection failed' }, status => 500);
        }
        my $data = $c->req->json;
        unless ($data) {
            return $c->render(json => { status => 'error', message => 'Invalid JSON input' }, status => 400);
        }
        foreach my $field (qw/address/) {
            unless (defined $data->{$field} && $data->{$field} ne '') {
                return $c->render(json => { status => 'error', message => "Missing required field: $field" }, status => 400);
            }
        }
        unless (
            $data->{address} =~ /^$RE{net}{IPv4}$/ ||
            $data->{address} =~ /^$RE{net}{IPv6}$/ ||
            $data->{address} =~ /^[a-zA-Z0-9.-]+$/
        ) {
            return $c->render(json => { status => 'error', message => 'Invalid address' }, status => 400);
        }
        my $sql = "INSERT INTO targets (id, address, description, is_active) VALUES (?, ?, ?, ?)";
        my $sth = $dbh->prepare($sql);
        eval {
            $sth->execute(
                Data::UUID->new->create_str,
                $data->{address},
                $data->{description} // '',
                defined $data->{is_active} ? $data->{is_active} : 1
            );
        };
        if ($@) {
            $c->app->log->error("Failed to insert target: $@");
            return $c->render(json => { status => 'error', message => 'Failed to add target' }, status => 500);
        }
        return $c->render(json => { status => 'success', message => 'Target added successfully' });
    };

    main::put '/target' => sub {
        my $c = shift;
        $c->app->log->debug("Received request for /target PUT");
        my $db_config = $c->app->defaults->{db};
        my $dbh = DBI->connect($db_config->{dsn}, $db_config->{username}, $db_config->{password}, { RaiseError => 1, AutoCommit => 1 });
        unless ($dbh) {
            $c->app->log->error("Failed to connect to the database: $DBI::errstr");
            return $c->render(json => { status => 'error', message => 'Database connection failed' }, status => 500);
        }
        my $data = $c->req->json;
        unless ($data) {
            return $c->render(json => { status => 'error', message => 'Invalid JSON input' }, status => 400);
        }
        my $id = $data->{id};
        unless (defined $id && $id ne '') {
            return $c->render(json => { status => 'error', message => 'Missing required field: id' }, status => 400);
        }
        my @set;
        my @params;
        foreach my $field (qw/address description is_active/) {
            if (defined $data->{$field}) {
                push @set, "$field = ?";
                push @params, $data->{$field};
            }
        }
        unless (@set) {
            return $c->render(json => { status => 'error', message => 'No fields to update' }, status => 400);
        }
        push @params, $id;
        my $sql = "UPDATE targets SET " . join(", ", @set) . " WHERE id = ?";
        $c->app->log->debug("Executing SQL: $sql with params: " . join(", ", @params));
        my $rows_affected;
        eval {
            my $sth = $dbh->prepare($sql);
            $rows_affected = $sth->execute(@params);
        };
        if ($@) {
            $c->app->log->error("Failed to update target: $@");
            return $c->render(json => { status => 'error', message => 'Failed to update target' }, status => 500);
        }
        unless ($rows_affected) {
            return $c->render(json => { status => 'error', message => 'Target not found' }, status => 404);
        }
        return $c->render(json => { status => 'success', message => 'Target updated successfully' });
    };

    main::del '/target' => sub {
        my $c = shift;
        my $data = $c->req->json;
        my $id = $data->{id} or do {
            $c->app->log->error("[target DELETE] Missing id field!");
            return $c->render(json => {status=>'error', message=>'Missing id'}, status=>400);
        };
        $c->app->log->debug("[target DELETE] Called with id=$id");

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, { RaiseError => 1, AutoCommit => 1 });

        # Fetch all monitor ids that will be deleted
        my $sth = $dbh->prepare("SELECT id FROM monitors WHERE target_id=?");
        $sth->execute($id);
        my @monitor_ids = map { $_->[0] } @{ $sth->fetchall_arrayref([]) };
        $c->app->log->debug("[target DELETE] Monitors to delete: " . join(',', @monitor_ids));

        # Delete the target row (cascade deletes monitors)
        my $rows = $dbh->do("DELETE FROM targets WHERE id=?", undef, $id);
        $dbh->disconnect;

        # Attempt to delete all associated .rrd files
        foreach my $mid (@monitor_ids) {
            my $rrd = "/var/rrd/$mid.rrd";
            if (-e $rrd) {
                if (unlink $rrd) {
                    $c->app->log->debug("[target DELETE] Deleted RRD file $rrd");
                    print STDERR "[target DELETE] Deleted RRD file $rrd\n";
                } else {
                    $c->app->log->error("[target DELETE] Could not delete RRD file $rrd: $!");
                    print STDERR "[target DELETE] Could not delete RRD file $rrd: $!\n";
                }
            } else {
                $c->app->log->debug("[target DELETE] RRD file $rrd not found (nothing to delete)");
            }
        }

        return $rows
            ? $c->render(json => {status=>'success', message=>'Target and associated monitors/rrds deleted.'})
            : $c->render(json => {status=>'error', message=>'Target not found'}, status=>404);
    };
}

1;