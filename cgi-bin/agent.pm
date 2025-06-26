package agent;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Data::UUID;
use JSON qw(encode_json decode_json);
use Try::Tiny;
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
        my $uuid = Data::UUID->new->create_str;
        $dbh->do(
            "INSERT INTO agents (id, name, address, description, is_active, password) 
             VALUES (?,?,?,?,?,?)",
            undef, $uuid, 'LOCAL', '127.0.0.1', 'Local Agent', 1, 'LOCAL'
        );
    }
}

sub register_agent {
    my ($db_config) = @_;

    # Initialize table
    my $dbh = DBI->connect(
        $db_config->{dsn},
        $db_config->{username},
        $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    ensure_agents_table($dbh);
    $dbh->disconnect;

    # @summary List all agents
    # @description Returns a list of all monitoring agents in the system, including their status and last seen time.
    # @tags Agents
    # @security bearerAuth
    main::get '/agent/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        # Include password only if authenticated
        my $fields = 'id, name, address, description, last_seen, is_active';
        $fields .= ', password' if $c->stash('jwt_payload');
        
        my $sth = $dbh->prepare("SELECT $fields FROM agents WHERE id = ?");
        $sth->execute($id);
        my $agent = $sth->fetchrow_hashref;
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

    # @summary Create new agent
    # @description Creates a new monitoring agent in the system.
    # @tags Agents
    # @security bearerAuth
    main::post '/agent' => sub {
        my $c = shift;
        my $data = $c->req->json;
        my $dbh;
        
        unless ($data && $data->{name}) {
            return $c->render(json => {
                status => 'error',
                message => 'Missing required field: name'
            }, status => 400);
        }

        # Validate IP address if provided
        if ($data->{address} && $data->{address} ne '') {
            unless ($data->{address} =~ /^$RE{net}{IPv4}$/ || 
                    $data->{address} =~ /^$RE{net}{IPv6}$/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Invalid IP address'
                }, status => 400);
            }
        }

        my $uuid = Data::UUID->new->create_str;
        
        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
            
            $dbh->do(q{
                INSERT INTO agents (
                    id, name, address, description, is_active, password
                ) VALUES (?, ?, ?, ?, ?, ?)
            }, undef,
                $uuid,
                $data->{name},
                $data->{address},
                $data->{description} // '',
                $data->{is_active} // 1,
                $data->{password} // 'CHANGE_ME'
            );
            
            $dbh->disconnect if $dbh;
            
            return $c->render(json => {
                status => 'success',
                message => 'Agent created successfully',
                id => $uuid
            });
        } catch {
            $dbh->disconnect if $dbh;
            
            # Handle duplicate name error specifically
            if ($_ =~ /Duplicate entry.*for key 'name'/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Agent name already exists'
                }, status => 400);
            }
            
            return $c->render(json => {
                status => 'error',
                message => "Failed to create agent: $_"
            }, status => 500);
        };
    };

    # @summary Update agent
    # @description Updates an existing agent's configuration.
    # @tags Agents
    # @security bearerAuth
    main::put '/agent/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $data = $c->req->json;
        
        unless ($data) {
            return $c->render(json => {
                status => 'error',
                message => 'No update data provided'
            }, status => 400);
        }

        # Validate IP address if provided
        if ($data->{address} && $data->{address} ne '') {
            unless ($data->{address} =~ /^$RE{net}{IPv4}$/ || 
                    $data->{address} =~ /^$RE{net}{IPv6}$/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Invalid IP address'
                }, status => 400);
            }
        }

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        # Build update query
        my @updates;
        my @params;
        
        foreach my $field (qw(name address description password is_active)) {
            if (exists $data->{$field}) {
                push @updates, "$field = ?";
                push @params, $data->{$field};
            }
        }
        
        push @params, $id;

        try {
            my $sql = "UPDATE agents SET " . join(", ", @updates) . " WHERE id = ?";
            my $rows = $dbh->do($sql, undef, @params);
            
            unless ($rows) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Agent not found'
                }, status => 404);
            }
        } catch {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => "Failed to update agent: $_"
            }, status => 500);
        };

        $dbh->disconnect;
        return $c->render(json => {
            status => 'success',
            message => 'Agent updated successfully',
            id => $id
        });
    };

    # @summary Delete agent
    # @description Removes an agent and all its associated monitors from the system.
    # Cannot delete the LOCAL agent.
    # @tags Agents
    # @security bearerAuth
    main::del '/agent/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $dbh;
        
        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            # Check if agent exists and is not LOCAL
            my $agent = $dbh->selectrow_hashref(
                "SELECT name FROM agents WHERE id = ?",
                undef, $id
            );
            
            unless ($agent) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Agent not found'
                }, status => 404);
            }

            if ($agent->{name} eq 'LOCAL') {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Cannot delete LOCAL agent'
                }, status => 403);
            }

            # Get associated monitor IDs before deletion for RRD cleanup
            my $sth = $dbh->prepare("SELECT id FROM monitors WHERE agent_id = ?");
            $sth->execute($id);
            my @monitor_ids = map { $_->[0] } @{ $sth->fetchall_arrayref([]) };

            # Delete agent (this will cascade delete monitors)
            my $rows = $dbh->do("DELETE FROM agents WHERE id = ?", undef, $id);
            $dbh->disconnect;

            # Clean up RRD files for deleted monitors
            foreach my $monitor_id (@monitor_ids) {
                my $rrd_file = "/var/rrd/$monitor_id.rrd";
                if (-e $rrd_file) {
                    unlink $rrd_file or $c->app->log->warn("Could not delete RRD file $rrd_file: $!");
                }
            }

            return $c->render(json => {
                status => 'success',
                message => 'Agent and associated monitors deleted successfully',
                id => $id,
                deleted_monitors => \@monitor_ids
            });
        } catch {
            $dbh->disconnect if $dbh;
            return $c->render(json => {
                status => 'error',
                message => "Failed to delete agent: $_"
            }, status => 500);
        };
    };
}

1;