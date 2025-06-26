package monitor;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Data::UUID;
use JSON qw(encode_json decode_json);
use Try::Tiny;

our @EXPORT_OK = qw(register_monitor);

# Valid protocol and DSCP values
my %VALID_PROTOCOLS = map { $_ => 1 } qw(ICMP ICMPV6 TCP);
my %VALID_DSCP = map { $_ => 1 } qw(BE EF CS0 CS1 CS2 CS3 CS4 CS5 CS6 CS7 
                                   AF11 AF12 AF13 AF21 AF22 AF23 
                                   AF31 AF32 AF33 AF41 AF42 AF43);

sub ensure_monitors_table {
    my ($dbh) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS monitors (
            id char(36) NOT NULL,
            description varchar(255) DEFAULT '',
            agent_id char(36) NOT NULL,
            target_id char(36) NOT NULL,
            protocol varchar(10) DEFAULT 'ICMP',
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
            KEY monitors_agent_idx (agent_id),
            KEY monitors_target_idx (target_id),
            CONSTRAINT monitors_agent_fk FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE,
            CONSTRAINT monitors_target_fk FOREIGN KEY (target_id) REFERENCES targets (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    });
}

# Helper function to validate monitor data
sub validate_monitor_data {
    my ($data, $is_update) = @_;  # Add $is_update parameter
    my @errors;

    # Check required fields only for new monitors
    unless ($is_update) {
        push @errors, "Missing agent_id" unless $data->{agent_id};
        push @errors, "Missing target_id" unless $data->{target_id};
    }

    # Validate protocol if provided
    if ($data->{protocol}) {
        push @errors, "Invalid protocol" 
            unless $VALID_PROTOCOLS{uc($data->{protocol})};
    }

    # Validate DSCP if provided
    if ($data->{dscp}) {
        push @errors, "Invalid DSCP value" 
            unless $VALID_DSCP{uc($data->{dscp})};
    }

    # Validate numeric fields if provided
    if (defined $data->{port}) {
        push @errors, "Port must be between 0 and 65535" 
            unless $data->{port} =~ /^\d+$/ && $data->{port} >= 0 && $data->{port} <= 65535;
    }
    if (defined $data->{pollcount}) {
        push @errors, "Pollcount must be between 1 and 100" 
            unless $data->{pollcount} =~ /^\d+$/ && $data->{pollcount} >= 1 && $data->{pollcount} <= 100;
    }
    if (defined $data->{pollinterval}) {
        push @errors, "Pollinterval must be between 10 and 3600" 
            unless $data->{pollinterval} =~ /^\d+$/ && $data->{pollinterval} >= 10 && $data->{pollinterval} <= 3600;
    }

    return @errors;
}

sub register_monitor {
    my ($db_config) = @_;

    # Initialize table
    my $dbh = DBI->connect(
        $db_config->{dsn},
        $db_config->{username},
        $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    ensure_monitors_table($dbh);
    $dbh->disconnect;

    # @summary Get monitor details
    # @description Retrieves detailed information about a specific monitor, including its
    # current status, configuration, and associated agent and target details.
    # @tags Monitors
    # @security bearerAuth
    main::get '/monitor/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $dbh;
        
        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
            
            my $sth = $dbh->prepare(q{
                SELECT m.*, 
                       a.name as agent_name, 
                       t.address as target_address
                FROM monitors m
                JOIN agents a ON m.agent_id = a.id
                JOIN targets t ON m.target_id = t.id
                WHERE m.id = ?
            });
            $sth->execute($id);
            my $monitor = $sth->fetchrow_hashref;
            
            $dbh->disconnect;
            
            unless ($monitor) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Monitor not found'
                }, status => 404);
            }
            
            return $c->render(json => {
                status => 'success',
                monitor => $monitor
            });
        } catch {
            $dbh->disconnect if $dbh;
            return $c->render(json => {
                status => 'error',
                message => "Database error: $_"
            }, status => 500);
        };
    };

    # @summary Create new monitor
    # @description Creates a new monitoring configuration that associates an agent with a target.
    # The monitor defines how the agent should test connectivity to the target.
    # @tags Monitors
    # @security bearerAuth
    main::post '/monitor' => sub {
        my $c = shift;
        my $data = $c->req->json;
        my $dbh;
        
        # Validate input data
        my @validation_errors = validate_monitor_data($data, 0);  # 0 = not an update
        if (@validation_errors) {
            return $c->render(json => {
                status => 'error',
                message => 'Validation failed: ' . join(', ', @validation_errors)
            }, status => 400);
        }

        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
            
            # Verify agent and target exist
            foreach my $check (
                ['agents', $data->{agent_id}, 'Agent'],
                ['targets', $data->{target_id}, 'Target']
            ) {
                my ($table, $id, $type) = @$check;
                my ($exists) = $dbh->selectrow_array(
                    "SELECT 1 FROM $table WHERE id = ?",
                    undef, $id
                );
                unless ($exists) {
                    $dbh->disconnect;
                    return $c->render(json => {
                        status => 'error',
                        message => "$type not found"
                    }, status => 404);
                }
            }

            my $uuid = Data::UUID->new->create_str;
            
            $dbh->do(q{
                INSERT INTO monitors (
                    id, description, agent_id, target_id, protocol,
                    port, dscp, pollcount, pollinterval, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            }, undef,
                $uuid,
                $data->{description} // '',
                $data->{agent_id},
                $data->{target_id},
                uc($data->{protocol} // 'ICMP'),
                $data->{port} // 0,
                uc($data->{dscp} // 'BE'),
                $data->{pollcount} // 5,
                $data->{pollinterval} // 60,
                $data->{is_active} // 1
            );
            
            $dbh->disconnect;
            
            return $c->render(json => {
                status => 'success',
                message => 'Monitor created successfully',
                id => $uuid
            });
        } catch {
            $dbh->disconnect if $dbh;
            
            if ($_ =~ /Duplicate entry.*for key 'monitor_uniqueness'/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Monitor with these parameters already exists'
                }, status => 400);
            }
            
            return $c->render(json => {
                status => 'error',
                message => "Failed to create monitor: $_"
            }, status => 500);
        };
    };

    # @summary Update monitor configuration
    # @description Updates an existing monitor's configuration. Note that polling parameters
    # (pollcount and pollinterval) cannot be modified after creation.
    # @tags Monitors
    # @security bearerAuth
    main::put '/monitor/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $data = $c->req->json;
        my $dbh;
        
        unless ($data) {
            return $c->render(json => {
                status => 'error',
                message => 'No update data provided'
            }, status => 400);
        }

        # Check for forbidden update fields
        if (exists $data->{pollcount} || exists $data->{pollinterval}) {
            return $c->render(json => {
                status => 'error',
                message => 'Cannot modify polling parameters after monitor creation. Delete and recreate the monitor to change these values.'
            }, status => 400);
        }

        # Validate input data if provided
        my @validation_errors = validate_monitor_data($data, 1);
        if (@validation_errors) {
            return $c->render(json => {
                status => 'error',
                message => 'Validation failed: ' . join(', ', @validation_errors)
            }, status => 400);
        }

        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            # Check if monitor exists
            my $exists = $dbh->selectrow_array(
                "SELECT 1 FROM monitors WHERE id = ?",
                undef, $id
            );

            unless ($exists) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Monitor not found'
                }, status => 404);
            }
            
            # Verify agent and target if changing
            foreach my $check (
                ['agents', $data->{agent_id}, 'Agent'],
                ['targets', $data->{target_id}, 'Target']
            ) {
                my ($table, $id, $type) = @$check;
                if ($id) {
                    my ($exists) = $dbh->selectrow_array(
                        "SELECT 1 FROM $table WHERE id = ?",
                        undef, $id
                    );
                    unless ($exists) {
                        $dbh->disconnect;
                        return $c->render(json => {
                            status => 'error',
                            message => "$type not found"
                        }, status => 404);
                    }
                }
            }

            # Build update query (only allowed fields)
            my @updates;
            my @params;
            
            foreach my $field (qw(description agent_id target_id protocol port dscp is_active)) {
                if (exists $data->{$field}) {
                    my $value = $data->{$field};
                    $value = uc($value) if $field =~ /^(protocol|dscp)$/;
                    push @updates, "$field = ?";
                    push @params, $value;
                }
            }
            
            push @params, $id;

            my $sql = "UPDATE monitors SET " . join(", ", @updates) . " WHERE id = ?";
            my $rows = $dbh->do($sql, undef, @params);
            
            $dbh->disconnect;
            
            return $c->render(json => {
                status => 'success',
                message => 'Monitor updated successfully',
                id => $id
            });
        } catch {
            $dbh->disconnect if $dbh;
            
            if ($_ =~ /Duplicate entry.*for key 'monitor_uniqueness'/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Monitor with these parameters already exists'
                }, status => 400);
            }
            
            return $c->render(json => {
                status => 'error',
                message => "Failed to update monitor: $_"
            }, status => 500);
        };
    };

    # @summary Delete monitor
    # @description Removes a monitor configuration and its associated RRD data files.
    # This operation cannot be undone.
    # @tags Monitors
    # @security bearerAuth
    main::del '/monitor/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $dbh;
        
        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            # Check if monitor exists
            my $exists = $dbh->selectrow_array(
                "SELECT 1 FROM monitors WHERE id = ?",
                undef, $id
            );

            unless ($exists) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Monitor not found'
                }, status => 404);
            }

            my $rows = $dbh->do("DELETE FROM monitors WHERE id = ?", undef, $id);
            $dbh->disconnect;

            return $c->render(json => {
                status => 'success',
                message => 'Monitor deleted successfully',
                id => $id
            });
        } catch {
            $dbh->disconnect if $dbh;
            return $c->render(json => {
                status => 'error',
                message => "Failed to delete monitor: $_"
            }, status => 500);
        };
    };

    # @summary Delete monitor
    # @description Removes a monitor configuration and its associated RRD data files.
    # This operation cannot be undone.
    # @tags Monitors
    # @security bearerAuth
    main::post '/monitor/:id/reset' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $dbh;
        
        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            # Check if monitor exists
            my $exists = $dbh->selectrow_array(
                "SELECT 1 FROM monitors WHERE id = ?",
                undef, $id
            );

            unless ($exists) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Monitor not found'
                }, status => 404);
            }

            # Reset statistics
            my $sql = q{
                UPDATE monitors 
                SET sample = 0,
                    current_loss = 0,
                    current_median = 0,
                    current_min = 0,
                    current_max = 0,
                    current_stddev = 0,
                    avg_loss = 0,
                    avg_median = 0,
                    avg_min = 0,
                    avg_max = 0,
                    avg_stddev = 0,
                    prev_loss = 0,
                    total_down = 0,
                    last_clear = NOW()
                WHERE id = ?
            };

            my $rows = $dbh->do($sql, undef, $id);
            $dbh->disconnect;

            return $c->render(json => {
                status => 'success',
                message => 'Monitor statistics reset successfully',
                id => $id
            });
        } catch {
            $dbh->disconnect if $dbh;
            return $c->render(json => {
                status => 'error',
                message => "Failed to reset monitor statistics: $_"
            }, status => 500);
        };
    };
}

1;