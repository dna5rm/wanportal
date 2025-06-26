package credentials;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Data::UUID;
use JSON qw(encode_json decode_json);
use Try::Tiny;

our @EXPORT_OK = qw(register_credentials);

# Valid credential types
my %VALID_TYPES = map { $_ => 1 } qw(ACCOUNT CERTIFICATE API PSK CODE);
my %VALID_SENSITIVITY = map { $_ => 1 } qw(LOW MEDIUM HIGH CRITICAL);

# Ensure credentials table exists
sub ensure_credentials_table {
    my ($dbh) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS credentials (
            id char(36) NOT NULL,
            site varchar(255) DEFAULT NULL,
            name varchar(255) NOT NULL,
            type ENUM('ACCOUNT','CERTIFICATE','API','PSK','CODE') NOT NULL,
            username varchar(255) DEFAULT NULL,
            password text,
            url text,
            owner varchar(255),
            comment text,
            expiry_date timestamp NULL DEFAULT NULL,
            is_active boolean DEFAULT true,
            sensitivity ENUM('LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'MEDIUM',
            metadata json,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by varchar(255) NOT NULL,
            last_accessed_at timestamp NULL DEFAULT NULL,
            last_accessed_by varchar(255),
            updated_at timestamp NULL DEFAULT NULL,
            updated_by varchar(255),
            PRIMARY KEY (id),
            KEY idx_credentials_name (name),
            KEY idx_credentials_type (type),
            KEY idx_credentials_site (site)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    });
}

sub register_credentials {
    my ($db_config) = @_;

    # Initialize table
    my $dbh = DBI->connect(
        $db_config->{dsn},
        $db_config->{username},
        $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    ensure_credentials_table($dbh);
    $dbh->disconnect;

    # @summary List credentials
    # @description Returns a list of all stored credentials with optional filtering.
    # Passwords are not included in the response unless specifically requested.
    # @tags Credentials
    # @security bearerAuth
    main::get '/credentials' => sub {
        my $c = shift;
        
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        # Define valid columns for filtering
        my %valid_columns = (
            id => 1,
            site => 1,
            name => 1,
            type => 1,
            username => 1,
            url => 1,
            owner => 1,
            comment => 1,
            expiry_date => 1,
            is_active => 1,
            sensitivity => 1,
            metadata => 1,
            created_at => 1,
            created_by => 1,
            last_accessed_at => 1,
            last_accessed_by => 1,
            updated_at => 1,
            updated_by => 1
        );

        # Process query parameters for filtering
        my @where;
        my @params;
        
        # Handle is_active parameter specially
        if (defined $c->param('is_active')) {
            my $is_active = $c->param('is_active');
            if ($is_active =~ /^[01]$/) {  # Only accept 0 or 1
                push @where, "is_active = ?";
                push @params, $is_active;
            }
        } else {
            # Default to showing only active entries
            push @where, "is_active = 1";
        }
        
        for my $param ($c->req->params->names) {
            next if $param eq 'is_active';  # Skip since we handled it above
            if ($valid_columns{$param}) {
                my $value = $c->param($param);
                
                # Handle different types of comparisons
                if ($value =~ /^(<=|>=|!=|<|>|=)(.+)$/) {
                    push @where, "$param $1 ?";
                    push @params, $2;
                }
                # Handle LIKE queries
                elsif ($value =~ /^%.*%$/) {
                    push @where, "$param LIKE ?";
                    push @params, $value;
                }
                # Handle NULL checks
                elsif ($value eq 'NULL') {
                    push @where, "$param IS NULL";
                }
                elsif ($value eq 'NOT NULL') {
                    push @where, "$param IS NOT NULL";
                }
                # Default exact match
                else {
                    push @where, "$param = ?";
                    push @params, $value;
                }
            }
        }

        # Build and execute query
        my $where_clause = @where ? "WHERE " . join(" AND ", @where) : "";
        my $sql = "SELECT * FROM credentials $where_clause ORDER BY name";
        
        my $sth = $dbh->prepare($sql);
        $sth->execute(@params);
        
        my $credentials = $sth->fetchall_arrayref({});
        
        # Don't send passwords unless specifically requested
        for my $cred (@$credentials) {
            delete $cred->{password} unless $c->param('include_password');
        }
        
        $dbh->disconnect;
        return $c->render(json => {
            status => 'success',
            credentials => $credentials
        });
    };

    # @summary Get credential details
    # @description Retrieves detailed information about a specific credential.
    # Updates last_accessed timestamp when credential is viewed.
    # @tags Credentials
    # @security bearerAuth
    main::get '/credentials/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        my $sth = $dbh->prepare("SELECT * FROM credentials WHERE id = ?");
        $sth->execute($id);
        my $credential = $sth->fetchrow_hashref;
        
        unless ($credential) {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => 'Credential not found'
            }, status => 404);
        }
        
        # Update last accessed
        my $username = $c->stash('jwt_payload')->{username};
        $dbh->do(
            "UPDATE credentials SET last_accessed_at = NOW(), last_accessed_by = ? WHERE id = ?",
            undef, $username, $id
        );
        
        $dbh->disconnect;
        return $c->render(json => {
            status => 'success',
            credential => $credential
        });
    };

    # @summary Create new credential
    # @description Creates a new credential entry in the system.
    # @tags Credentials
    # @security bearerAuth
    main::post '/credentials' => sub {
        my $c = shift;
        my $data = $c->req->json;
        
        # Validate required fields
        unless ($data && $data->{name} && $data->{type}) {
            return $c->render(json => {
                status => 'error',
                message => 'Missing required fields: name and type'
            }, status => 400);
        }
        
        # Validate type
        unless ($VALID_TYPES{$data->{type}}) {
            return $c->render(json => {
                status => 'error',
                message => 'Invalid credential type'
            }, status => 400);
        }
        
        # Validate sensitivity if provided
        if ($data->{sensitivity} && !$VALID_SENSITIVITY{$data->{sensitivity}}) {
            return $c->render(json => {
                status => 'error',
                message => 'Invalid sensitivity level'
            }, status => 400);
        }
        
        # Validate metadata is valid JSON if provided
        if ($data->{metadata}) {
            try {
                $data->{metadata} = encode_json($data->{metadata});
            } catch {
                return $c->render(json => {
                    status => 'error',
                    message => 'Invalid metadata format'
                }, status => 400);
            };
        }
        
        my $uuid = Data::UUID->new->create_str;
        my $username = $c->stash('jwt_payload')->{username};
        
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        try {
            my $sth = $dbh->prepare(q{
                INSERT INTO credentials (
                    id, site, name, type, username, password,
                    url, owner, comment, expiry_date, sensitivity,
                    metadata, created_by, created_at,
                    updated_by, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, NOW(),
                    ?, NOW()
                )
            });
            
            $sth->execute(
                $uuid,
                $data->{site},
                $data->{name},
                $data->{type},
                $data->{username},
                $data->{password},
                $data->{url},
                $data->{owner},
                $data->{comment},
                $data->{expiry_date},
                $data->{sensitivity} || 'MEDIUM',
                $data->{metadata},
                $username,
                $username  # for updated_by
            );
        } catch {
            my $error = $_;
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => "Database error: $error"
            }, status => 500);
        };
        
        $dbh->disconnect;
        return $c->render(json => {
            status => 'success',
            id => $uuid
        });
    };

    # @summary Update credential
    # @description Updates an existing credential's information.
    # Tracks update history with timestamp and user.
    # @tags Credentials
    # @security bearerAuth
    main::put '/credentials/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $data = $c->req->json;
        
        unless ($data) {
            return $c->render(json => {
                status => 'error',
                message => 'No update data provided'
            }, status => 400);
        }
        
        # Validate type if provided
        if ($data->{type} && !$VALID_TYPES{$data->{type}}) {
            return $c->render(json => {
                status => 'error',
                message => 'Invalid credential type'
            }, status => 400);
        }
        
        # Validate sensitivity if provided
        if ($data->{sensitivity} && !$VALID_SENSITIVITY{$data->{sensitivity}}) {
            return $c->render(json => {
                status => 'error',
                message => 'Invalid sensitivity level'
            }, status => 400);
        }
        
        # Validate metadata is valid JSON if provided
        if ($data->{metadata}) {
            try {
                $data->{metadata} = encode_json($data->{metadata});
            } catch {
                return $c->render(json => {
                    status => 'error',
                    message => 'Invalid metadata format'
                }, status => 400);
            };
        }
        
        my $username = $c->stash('jwt_payload')->{username};
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        # Build update query
        my @updates;
        my @params;
        
        for my $field (qw(site name type username password url owner comment expiry_date sensitivity metadata is_active)) {
            if (exists $data->{$field}) {
                push @updates, "$field = ?";
                push @params, $data->{$field};
            }
        }
        
        push @updates, "updated_at = NOW()";
        push @updates, "updated_by = ?";
        push @params, $username;
        push @params, $id;
        
        my $sql = "UPDATE credentials SET " . join(", ", @updates) . " WHERE id = ?";
        
        try {
            my $sth = $dbh->prepare($sql);
            my $rows = $sth->execute(@params);
            
            unless ($rows) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Credential not found'
                }, status => 404);
            }
        } catch {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => "Database error: $_"
            }, status => 500);
        };
        
        $dbh->disconnect;
        return $c->render(json => {
            status => 'success',
            message => 'Credential updated'
        });
    };

    # @summary Delete credential
    # @description Soft deletes a credential by marking it inactive.
    # If already inactive, performs hard delete.
    # @tags Credentials
    # @security bearerAuth
    main::del '/credentials/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        
        my $username = $c->stash('jwt_payload')->{username};
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        try {
            # First check if the credential exists and is already inactive
            my $check_sth = $dbh->prepare("SELECT is_active FROM credentials WHERE id = ?");
            $check_sth->execute($id);
            my ($is_active) = $check_sth->fetchrow_array();
            
            unless (defined $is_active) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Credential not found'
                }, status => 404);
            }
            
            my $rows;
            if ($is_active) {
                # If active, perform soft delete
                my $update_sth = $dbh->prepare(q{
                    UPDATE credentials 
                    SET is_active = 0, 
                        updated_at = NOW(), 
                        updated_by = ? 
                    WHERE id = ?
                });
                $rows = $update_sth->execute($username, $id);
                
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'success',
                    message => 'Credential soft deleted'
                });
            } else {
                # If already inactive, perform hard delete
                my $delete_sth = $dbh->prepare("DELETE FROM credentials WHERE id = ?");
                $rows = $delete_sth->execute($id);
                
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'success',
                    message => 'Credential permanently deleted'
                });
            }
        } catch {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => "Database error: $_"
            }, status => 500);
        };
    };
}

1;