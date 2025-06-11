package agent;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Data::UUID;
use Regexp::Common qw(net);

our @EXPORT_OK = qw(register_agent);

sub ensure_agents_table {
    my ($dbh) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS agents (
            id char(36) NOT NULL,
            name varchar(255) NOT NULL,
            address varchar(255) DEFAULT NULL,
            description varchar(255) DEFAULT '',
            last_seen datetime DEFAULT current_timestamp(),
            is_active tinyint(1) NOT NULL DEFAULT 1,
            password varchar(255) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY name (name),
            CONSTRAINT chk_valid_ip_address CHECK (
                address is null or
                address regexp '^([0-9]{1,3}\\.){3}[0-9]{1,3}$' or
                address regexp '^[0-9a-fA-F:]+$'
            )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    });

    # Ensure a LOCAL agent for 127.0.0.1 exists
    my ($exists) = $dbh->selectrow_array(
        "SELECT COUNT(*) FROM agents WHERE name=?",
        undef, 'LOCAL'
    );
    if (!$exists) {
        require Data::UUID;
        my $agent_id = Data::UUID->new->create_str;
        $dbh->do(
            "INSERT INTO agents (id, name, address, description, is_active, password) VALUES (?,?,?,?,?,?)",
            undef, $agent_id, 'LOCAL', '127.0.0.1', 'Server-local agent', 1, 'local_password' # password can be changed as needed
        );
    }
}

sub register_agent {
    my ($db_config) = @_;
    my $dbh = DBI->connect(
        $db_config->{dsn}, $db_config->{username}, $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    ensure_agents_table($dbh);
    $dbh->disconnect if $dbh;

    main::get '/agent' => sub {
        my $c = shift;
        $c->app->log->debug("Received request for /agent GET");

        my $db_config = $c->app->defaults->{db};
        my $dbh = DBI->connect($db_config->{dsn}, $db_config->{username}, $db_config->{password}, { RaiseError => 1, AutoCommit => 1 });
        unless ($dbh) {
            $c->app->log->error("Failed to connect to the database: $DBI::errstr");
            return $c->render(json => { status => 'error', message => 'Database connection failed' }, status => 500);
        }
        my $query = $c->req->json || {};
        my @filters;
        my @params;
        if ($query->{id})       { push @filters, "id = ?";       push @params, $query->{id}; }
        if ($query->{name})     { push @filters, "name = ?";     push @params, $query->{name}; }
        if ($query->{address})  { push @filters, "address = ?";  push @params, $query->{address}; }
        if (defined $query->{is_active}) { push @filters, "is_active = ?"; push @params, $query->{is_active} ? 1 : 0; }
        my $sql = "SELECT id, name, address, description, last_seen, is_active FROM agents";
        $sql .= " WHERE " . join(" AND ", @filters) if @filters;
        my $sth = $dbh->prepare($sql);
        $sth->execute(@params);
        my $agents = $sth->fetchall_arrayref({});
        return $c->render(json => {status => 'success', data => $agents});
    };

    main::get '/agent/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $db_config = $c->app->defaults->{db};
        my $dbh = DBI->connect(
            $db_config->{dsn}, $db_config->{username}, $db_config->{password}, { RaiseError => 1, AutoCommit => 1 }
        );
        my $sth = $dbh->prepare("SELECT id, name, address, description, last_seen, is_active, password FROM agents WHERE id=?");
        $sth->execute($id);
        my $agent = $sth->fetchrow_hashref;
        $dbh->disconnect;
        $c->render(json => { status => 'success', data => $agent });
    };

    main::post '/agent' => sub {
        my $c = shift;
        $c->app->log->debug("Received request for /agent POST");

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
        foreach my $field (qw/name password/) {
            unless (defined $data->{$field} && $data->{$field} ne '') {
                return $c->render(json => { status => 'error', message => "Missing required field: $field" }, status => 400);
            }
        }
        my $uuid = Data::UUID->new->create_str;

        my $sql = "INSERT INTO agents (id, name, address, description, is_active, password)
                   VALUES (?, ?, ?, ?, ?, ?)";
        my $sth = $dbh->prepare($sql);
        my $address     = $data->{address} // undef;
        my $description = $data->{description} // '';
        my $is_active   = defined $data->{is_active} ? $data->{is_active} : 1;
        my $password    = $data->{password};
        eval {
            $sth->execute($uuid, $data->{name}, $address, $description, $is_active, $password);
        };
        if ($@) {
            $c->app->log->error("Failed to insert agent: $@");
            return $c->render(json => { status => 'error', message => 'Failed to add agent' }, status => 500);
        }
        return $c->render(json => { status => 'success', id => $uuid });
    };

    main::put '/agent' => sub {
        my $c = shift;
        $c->app->log->debug("Received request for /agent PUT");

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
        if (defined $data->{address} && $data->{address} ne '') {
            unless ($data->{address} =~ /^$RE{net}{IPv4}$/ || $data->{address} =~ /^$RE{net}{IPv6}$/) {
                return $c->render(json => { status => 'error', message => 'Invalid IP address' }, status => 400);
            }
        }
        my @set;
        my @params;
        foreach my $field (qw/name address description is_active password/) {
            if (defined $data->{$field}) {
                push @set, "$field = ?";
                push @params, $data->{$field};
            }
        }
        unless (@set) {
            return $c->render(json => { status => 'error', message => 'No fields to update' }, status => 400);
        }
        push @params, $id;
        my $sql = "UPDATE agents SET " . join(", ", @set) . " WHERE id = ?";
        $c->app->log->debug("Executing SQL: $sql with params: " . join(", ", @params));
        my $rows_affected;
        eval {
            my $sth = $dbh->prepare($sql);
            $rows_affected = $sth->execute(@params);
        };
        if ($@) {
            $c->app->log->error("Failed to update agent: $@");
            return $c->render(json => { status => 'error', message => 'Failed to update agent' }, status => 500);
        }
        unless ($rows_affected) {
            return $c->render(json => { status => 'error', message => 'Agent not found' }, status => 404);
        }
        return $c->render(json => { status => 'success', message => 'Agent updated successfully' });
    };

    main::del '/agent' => sub {
        my $c = shift;
        my $data = $c->req->json;
        my $id = $data->{id} or do {
            $c->app->log->error("[agent DELETE] Missing id field!");
            return $c->render(json => {status=>'error', message=>'Missing id'}, status=>400);
        };
        $c->app->log->debug("[agent DELETE] Called with id=$id");

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, { RaiseError => 1, AutoCommit => 1 });

        # Fetch all monitor ids that will be deleted
        my $sth = $dbh->prepare("SELECT id FROM monitors WHERE agent_id=?");
        $sth->execute($id);
        my @monitor_ids = map { $_->[0] } @{ $sth->fetchall_arrayref([]) };
        $c->app->log->debug("[agent DELETE] Monitors to delete: " . join(',', @monitor_ids));

        # Delete the agent row (cascade deletes monitors)
        my $rows = $dbh->do("DELETE FROM agents WHERE id=?", undef, $id);
        $dbh->disconnect;

        # Attempt to delete all associated .rrd files
        foreach my $mid (@monitor_ids) {
            my $rrd = "/var/rrd/$mid.rrd";
            if (-e $rrd) {
                if (unlink $rrd) {
                    $c->app->log->debug("[agent DELETE] Deleted RRD file $rrd");
                    print STDERR "[agent DELETE] Deleted RRD file $rrd\n";
                } else {
                    $c->app->log->error("[agent DELETE] Could not delete RRD file $rrd: $!");
                    print STDERR "[agent DELETE] Could not delete RRD file $rrd: $!\n";
                }
            } else {
                $c->app->log->debug("[agent DELETE] RRD file $rrd not found (nothing to delete)");
            }
        }

        return $rows
            ? $c->render(json => {status=>'success', message=>'Agent and associated monitors/rrds deleted.'})
            : $c->render(json => {status=>'error', message=>'Agent not found'}, status=>404);
    };
}

1;