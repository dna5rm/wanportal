package users;
use strict;
use warnings;
use Exporter 'import';
use Digest::SHA qw(sha256_hex);
use DBI;
use JSON qw(encode_json);
use Try::Tiny;

our @EXPORT_OK = qw(
    ensure_users_table
    validate_user
    register_users
);

# Password complexity requirements
my $MIN_PASSWORD_LENGTH = 8;
# my $PASSWORD_REGEX = qr/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*#?&])[A-Za-z\d@$!%*#?&]{8,}$/;
my $PASSWORD_REGEX = qr/^(?=.*[A-Za-z])(?=.*\d).{8,}$/;

sub ensure_users_table {
    my ($dbh, $admin_pwd) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS users (
            id char(36) NOT NULL,
            username varchar(255) NOT NULL UNIQUE,
            password_hash varchar(255) NOT NULL,
            full_name varchar(255),
            email varchar(255),
            is_admin boolean DEFAULT FALSE,
            is_active boolean DEFAULT TRUE,
            last_login datetime DEFAULT NULL,
            failed_attempts int DEFAULT 0,
            locked_until datetime DEFAULT NULL,
            password_changed datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_by varchar(255),
            updated_at datetime DEFAULT NULL,
            updated_by varchar(255),
            PRIMARY KEY (id),
            KEY idx_username (username),
            KEY idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    });

    # Ensure admin user exists
    my ($exists) = $dbh->selectrow_array(
        "SELECT COUNT(*) FROM users WHERE username='admin'"
    );
    if (!$exists) {
        my $uuid = Data::UUID->new->create_str;
        my $hash = sha256_hex($admin_pwd);
        $dbh->do(
            "INSERT INTO users (id, username, password_hash, is_admin, full_name, created_by) 
             VALUES (?, 'admin', ?, 1, 'System Administrator', 'system')",
            undef, $uuid, $hash
        );
    }
}

# Enhanced user validation with rate limiting
sub validate_user {
    my ($dbh, $username, $password) = @_;
    
    # Check if account exists and is active
    my $user = $dbh->selectrow_hashref(
        "SELECT id, password_hash, failed_attempts, locked_until, is_active, is_admin 
         FROM users WHERE username = ?",
        undef, $username
    );

    return (0, 0) unless $user;
    return (0, 0) unless $user->{is_active};

    # Check if account is locked
    if ($user->{locked_until} && $user->{locked_until} gt scalar localtime) {
        return (0, 0);
    }

    my $hash = sha256_hex($password);
    if ($hash eq $user->{password_hash}) {
        # Reset failed attempts on successful login
        $dbh->do(
            "UPDATE users SET 
                failed_attempts = 0, 
                locked_until = NULL, 
                last_login = CURRENT_TIMESTAMP 
             WHERE id = ?",
            undef, $user->{id}
        );
        return ($user->{id}, $user->{is_admin});
    }

    # Increment failed attempts
    my $attempts = $user->{failed_attempts} + 1;
    my $lock_sql = '';
    if ($attempts >= 5) {  # Lock after 5 failed attempts
        $lock_sql = ", locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE)";
    }
    
    $dbh->do(
        "UPDATE users SET failed_attempts = ?$lock_sql WHERE id = ?",
        undef, $attempts, $user->{id}
    );
    
    return (0, 0);
}

# Validate password complexity
sub is_valid_password {
    my ($password) = @_;
    return 0 unless $password;
    return 0 if length($password) < $MIN_PASSWORD_LENGTH;
    return $password =~ $PASSWORD_REGEX;
}

sub register_users {
    my ($db_config) = @_;

    # GET /users - List users
    main::get '/users' => sub {
        my $c = shift;
        
        # Check if requester is admin
        unless ($c->stash('jwt_payload')->{is_admin}) {
            return $c->render(json => {
                status => 'error',
                message => 'Unauthorized'
            }, status => 403);
        }

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        my $sth = $dbh->prepare(q{
            SELECT 
                id, username, full_name, email, is_admin, is_active, 
                last_login, created_at, updated_at
            FROM users 
            ORDER BY username
        });
        $sth->execute();
        
        my $users = $sth->fetchall_arrayref({});
        $dbh->disconnect;
        
        return $c->render(json => {
            status => 'success',
            users => $users
        });
    };

    # POST /users - Create new user
    main::post '/users' => sub {
        my $c = shift;
        my $data = $c->req->json;
        
        # Check if requester is admin
        unless ($c->stash('jwt_payload')->{is_admin}) {
            return $c->render(json => {
                status => 'error',
                message => 'Unauthorized'
            }, status => 403);
        }

        # Validate required fields
        unless ($data && $data->{username} && $data->{password}) {
            return $c->render(json => {
                status => 'error',
                message => 'Missing required fields'
            }, status => 400);
        }

        # Validate password complexity
        unless (is_valid_password($data->{password})) {
            return $c->render(json => {
                status => 'error',
                message => 'Password does not meet complexity requirements'
            }, status => 400);
        }

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        # Check if username exists
        my ($exists) = $dbh->selectrow_array(
            "SELECT 1 FROM users WHERE username = ?",
            undef, $data->{username}
        );
        
        if ($exists) {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => 'Username already exists'
            }, status => 400);
        }

        my $uuid;
        try {
            $uuid = Data::UUID->new->create_str;
            my $hash = sha256_hex($data->{password});
            my $creator = $c->stash('jwt_payload')->{username};
            
            $dbh->do(q{
                INSERT INTO users (
                    id, username, password_hash, full_name, email, 
                    is_admin, is_active, created_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?
                )
            }, undef,
                $uuid,
                $data->{username},
                $hash,
                $data->{full_name} // '',
                $data->{email} // '',
                $data->{is_admin} ? 1 : 0,
                $data->{is_active} // 1,
                $creator
            );
        } catch {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => "Failed to create user: $_"
            }, status => 500);
        };

        $dbh->disconnect;
        return $c->render(json => {
            status => 'success',
            message => 'User created successfully',
            id => $uuid
        });
    };

    # GET /users/:id - Get single user
    main::get '/users/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        
        # Check if requester is admin
        unless ($c->stash('jwt_payload')->{is_admin}) {
            return $c->render(json => {
                status => 'error',
                message => 'Unauthorized'
            }, status => 403);
        }

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        my $sth = $dbh->prepare(q{
            SELECT 
                id, username, full_name, email, is_admin, is_active,
                last_login, created_at, created_by, updated_at, updated_by,
                failed_attempts, locked_until
            FROM users 
            WHERE id = ?
        });
        $sth->execute($id);
        
        my $user = $sth->fetchrow_hashref;
        $dbh->disconnect;
        
        unless ($user) {
            return $c->render(json => {
                status => 'error',
                message => 'User not found'
            }, status => 404);
        }

        return $c->render(json => {
            status => 'success',
            user => $user
        });
    };

    # PUT /users/:id - Update user
    main::put '/users/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $data = $c->req->json;
        
        # Check if requester is admin
        unless ($c->stash('jwt_payload')->{is_admin}) {
            return $c->render(json => {
                status => 'error',
                message => 'Unauthorized'
            }, status => 403);
        }

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

        # Check if user exists
        my $user = $dbh->selectrow_hashref(
            "SELECT username FROM users WHERE id = ?",
            undef, $id
        );
        
        unless ($user) {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => 'User not found'
            }, status => 404);
        }

        # Prevent modification of admin user except by themselves
        if ($user->{username} eq 'admin' && 
            $c->stash('jwt_payload')->{username} ne 'admin') {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => 'Cannot modify admin user'
            }, status => 403);
        }

        # Build update query
        my @updates;
        my @params;
        
        if ($data->{password}) {
            unless (is_valid_password($data->{password})) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Password does not meet complexity requirements'
                }, status => 400);
            }
            push @updates, "password_hash = ?, password_changed = CURRENT_TIMESTAMP";
            push @params, sha256_hex($data->{password});
        }
        
        foreach my $field (qw(full_name email is_admin is_active)) {
            if (exists $data->{$field}) {
                push @updates, "$field = ?";
                push @params, $data->{$field};
            }
        }
        
        push @updates, "updated_at = CURRENT_TIMESTAMP, updated_by = ?";
        push @params, $c->stash('jwt_payload')->{username};
        push @params, $id;

        try {
            my $sql = "UPDATE users SET " . join(", ", @updates) . " WHERE id = ?";
            $dbh->do($sql, undef, @params);
        } catch {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => "Failed to update user: $_"
            }, status => 500);
        };

        $dbh->disconnect;
        return $c->render(json => {
            status => 'success',
            message => 'User updated successfully',
            id => $id
        });
    };

    # DELETE /users/:id - Delete user
    main::del '/users/:id' => sub {
        my $c = shift;
        my $id = $c->param('id');
        
        # Check if requester is admin
        unless ($c->stash('jwt_payload')->{is_admin}) {
            return $c->render(json => {
                status => 'error',
                message => 'Unauthorized'
            }, status => 403);
        }

        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

        # Prevent deletion of admin user
        my $user = $dbh->selectrow_hashref(
            "SELECT username FROM users WHERE id = ?",
            undef, $id
        );
        
        if ($user && $user->{username} eq 'admin') {
            $dbh->disconnect;
            return $c->render(json => {
                status => 'error',
                message => 'Cannot delete admin user'
            }, status => 403);
        }

        my $rows = $dbh->do("DELETE FROM users WHERE id = ?", undef, $id);
        $dbh->disconnect;

        unless ($rows) {
            return $c->render(json => {
                status => 'error',
                message => 'User not found'
            }, status => 404);
        }

        return $c->render(json => {
            status => 'success',
            message => 'User deleted successfully',
            id => $id
        });
    };
}

1;