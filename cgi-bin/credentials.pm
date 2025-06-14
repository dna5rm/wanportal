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

    # GET /credentials - List credentials
    main::get '/credentials' => sub {
        my $c = shift;
        my $query = $c->req->json || {};
        
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        # Build query conditions
        my @where;
        my @params;
        
        push @where, "is_active = 1" unless $query->{show_inactive};
        
        if ($query->{site}) {
            push @where, "site = ?";
            push @params, $query->{site};
        }
        if ($query->{type} && $VALID_TYPES{$query->{type}}) {
            push @where, "type = ?";
            push @params, $query->{type};
        }
        
        my $where_clause = @where ? "WHERE " . join(" AND ", @where) : "";
        
        my $sql = "SELECT * FROM credentials $where_clause ORDER BY name";
        
        my $sth = $dbh->prepare($sql);
        $sth->execute(@params);
        
        my $credentials = $sth->fetchall_arrayref({});
        
        # Update last accessed for each credential
        my $username = $c->stash('jwt_payload')->{username};
        my $update = $dbh->prepare(
            "UPDATE credentials SET last_accessed_at = NOW(), last_accessed_by = ? WHERE id = ?"
        );
        
        for my $cred (@$credentials) {
            $update->execute($username, $cred->{id});
            # Clean sensitive data from response
            delete $cred->{password} unless $query->{include_password};
        }
        
        $dbh->disconnect;
        return $c->render(json => {
            status => 'success',
            credentials => $credentials
        });
    };

    # GET /credentials/:id - Get single credential
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

    # POST /credentials - Create new credential
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
                    metadata, created_by, created_at
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
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
                $username
            );
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
            id => $uuid
        });
    };

    # PUT /credentials/:id - Update credential
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

    # DELETE /credentials/:id - Soft delete credential
    main::del '/credentials/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        
        my $username = $c->stash('jwt_payload')->{username};
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        try {
            my $sth = $dbh->prepare(q{
                UPDATE credentials 
                SET is_active = 0, 
                    updated_at = NOW(), 
                    updated_by = ? 
                WHERE id = ?
            });
            
            my $rows = $sth->execute($username, $id);
            
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
            message => 'Credential deleted'
        });
    };
}

1;