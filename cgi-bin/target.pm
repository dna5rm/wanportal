package target;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Data::UUID;
use JSON qw(encode_json decode_json);
use Try::Tiny;
use Regexp::Common qw(net);

our @EXPORT_OK = qw(register_target);

# Helper function for address validation
sub validate_address {
    my ($address) = @_;
    
    # IPv4 validation
    if ($address =~ /^(\d{1,3}\.){3}\d{1,3}$/) {
        my @octets = split(/\./, $address);
        foreach my $octet (@octets) {
            return 0 if $octet > 255;
        }
        return 1;
    }
    # IPv6 validation
    elsif ($address =~ /^$RE{net}{IPv6}$/) {
        return 1;
    }
    # Hostname validation
    elsif ($address =~ /^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/) {
        return 1;
    }
    return 0;
}

sub ensure_targets_table {
    my ($dbh) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS targets (
            id char(36) NOT NULL,
            address varchar(255) NOT NULL,
            description varchar(255) DEFAULT '',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY address (address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    });
}

sub register_target {
    my ($db_config) = @_;

    # Initialize table
    my $dbh = DBI->connect(
        $db_config->{dsn},
        $db_config->{username},
        $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    ensure_targets_table($dbh);
    $dbh->disconnect;

    # @summary Get target details
    # @description Retrieves detailed information about a specific monitoring target.
    # @tags Targets
    # @security bearerAuth
    # @param {string} id - Target UUID
    # @response 200 {object} Target details
    # @response 200 {string} target.id - Target UUID
    # @response 200 {string} target.address - Target address (IPv4, IPv6, or hostname)
    # @response 200 {string} target.description - Target description
    # @response 200 {boolean} target.is_active - Target status
    # @response 404 {Error} Target not found
    main::get '/target/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $dbh;
        
        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
            
            my $sth = $dbh->prepare(q{
                SELECT id, address, description, is_active
                FROM targets 
                WHERE id = ?
            });
            $sth->execute($id);
            my $target = $sth->fetchrow_hashref;
            
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
        } catch {
            $dbh->disconnect if $dbh;
            return $c->render(json => {
                status => 'error',
                message => "Database error: $_"
            }, status => 500);
        };
    };

    # @summary Create new target
    # @description Creates a new monitoring target in the system.
    # Address can be IPv4, IPv6, or a valid hostname.
    # @tags Targets
    # @security bearerAuth
    # @param {object} requestBody
    # @param {string} requestBody.address - Target address (IPv4, IPv6, or hostname)
    # @param {string} [requestBody.description] - Target description
    # @param {boolean} [requestBody.is_active=true] - Target status
    # @response 200 {Success} Target created successfully
    # @response 200 {string} id - New target UUID
    # @response 400 {Error} Missing required field: address
    # @response 400 {Error} Invalid address format
    # @response 400 {Error} Target address already exists
    main::post '/target' => sub {
        my $c = shift;
        my $data = $c->req->json;
        my $dbh;
        
        unless ($data && $data->{address}) {
            return $c->render(json => {
                status => 'error',
                message => 'Missing required field: address'
            }, status => 400);
        }

        # Address validation
        unless (validate_address($data->{address})) {
            return $c->render(json => {
                status => 'error',
                message => 'Invalid address format: must be valid IPv4, IPv6, or hostname'
            }, status => 400);
        }

        my $uuid = Data::UUID->new->create_str;
        
        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
            
            $dbh->do(q{
                INSERT INTO targets (
                    id, address, description, is_active
                ) VALUES (?, ?, ?, ?)
            }, undef,
                $uuid,
                $data->{address},
                $data->{description} // '',
                $data->{is_active} // 1
            );
            
            $dbh->disconnect;
            
            return $c->render(json => {
                status => 'success',
                message => 'Target created successfully',
                id => $uuid
            });
        } catch {
            $dbh->disconnect if $dbh;
            
            # Handle duplicate address error specifically
            if ($_ =~ /Duplicate entry.*for key 'address'/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Target address already exists'
                }, status => 400);
            }
            
            return $c->render(json => {
                status => 'error',
                message => "Failed to create target: $_"
            }, status => 500);
        };
    };

    # @summary Update target
    # @description Updates an existing target's configuration.
    # Address validation applies when updating.
    # @tags Targets
    # @security bearerAuth
    # @param {string} id - Target UUID
    # @param {object} requestBody
    # @param {string} [requestBody.address] - Target address (IPv4, IPv6, or hostname)
    # @param {string} [requestBody.description] - Target description
    # @param {boolean} [requestBody.is_active] - Target status
    # @response 200 {Success} Target updated successfully
    # @response 400 {Error} Invalid address format
    # @response 400 {Error} Target address already exists
    # @response 404 {Error} Target not found
    main::put '/target/:id' => sub {
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

        # Address validation if provided
        if ($data->{address} && !validate_address($data->{address})) {
            return $c->render(json => {
                status => 'error',
                message => 'Invalid address format: must be valid IPv4, IPv6, or hostname'
            }, status => 400);
        }

        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
            
            # Build update query
            my @updates;
            my @params;
            
            foreach my $field (qw(address description is_active)) {
                if (exists $data->{$field}) {
                    push @updates, "$field = ?";
                    push @params, $data->{$field};
                }
            }
            
            push @params, $id;

            my $sql = "UPDATE targets SET " . join(", ", @updates) . " WHERE id = ?";
            my $rows = $dbh->do($sql, undef, @params);
            
            $dbh->disconnect;
            
            unless ($rows) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Target not found'
                }, status => 404);
            }
            
            return $c->render(json => {
                status => 'success',
                message => 'Target updated successfully',
                id => $id
            });
        } catch {
            $dbh->disconnect if $dbh;
            
            # Handle duplicate address error
            if ($_ =~ /Duplicate entry.*for key 'address'/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Target address already exists'
                }, status => 400);
            }
            
            return $c->render(json => {
                status => 'error',
                message => "Failed to update target: $_"
            }, status => 500);
        };
    };

    # @summary Delete target
    # @description Removes a target and all its associated monitors from the system.
    # Also removes associated RRD files for all deleted monitors.
    # @tags Targets
    # @security bearerAuth
    # @param {string} id - Target UUID
    # @response 200 {Success} Target and associated monitors deleted successfully
    # @response 200 {array} deleted_monitors - Array of deleted monitor IDs
    # @response 404 {Error} Target not found
    main::del '/target/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $dbh;
        
        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            # First check if target exists
            my $exists = $dbh->selectrow_array(
                "SELECT 1 FROM targets WHERE id = ?",
                undef, $id
            );

            unless ($exists) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Target not found'
                }, status => 404);
            }

            # Get associated monitor IDs before deletion for RRD cleanup
            my $sth = $dbh->prepare("SELECT id FROM monitors WHERE target_id = ?");
            $sth->execute($id);
            my @monitor_ids = map { $_->[0] } @{ $sth->fetchall_arrayref([]) };

            # Delete target (this will cascade delete monitors)
            my $rows = $dbh->do("DELETE FROM targets WHERE id = ?", undef, $id);
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
                message => 'Target and associated monitors deleted successfully',
                id => $id,
                deleted_monitors => \@monitor_ids
            });
        } catch {
            $dbh->disconnect if $dbh;
            return $c->render(json => {
                status => 'error',
                message => "Failed to delete target: $_"
            }, status => 500);
        };
    };
}

1;