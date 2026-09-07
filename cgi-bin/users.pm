=head1 NAME

users - local account management (JWT-protected, admin-only)

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, inside the JWT group
    use users qw(ensure_users_table validate_user register_users);
    register_users($db_config);

=head1 DESCRIPTION

Owns the C<users> table: account creation, profile and permission
updates, password changes and deactivation. Every /users route
checks the admin flag on the caller's JWT, and the built-in admin
account can only be changed by itself.

New passwords must be at least eight characters with a letter and a
digit, stored as bcrypt hashes. The table is created at startup and
seeded with an initial admin whose password starts out as the
database password. validate_user is the shared local-account check
(bcrypt, lockout, active flag) that the login path in auth uses.

=cut

package users;
use strict;
use warnings;
use Exporter 'import';
use Crypt::Eksblowfish::Bcrypt qw(bcrypt);
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
        my $salt = Crypt::Eksblowfish::Bcrypt::en_base64(Crypt::Eksblowfish::Bcrypt::random_salt(16));
        my $hash = bcrypt($admin_pwd, '$2a$12$' . $salt);
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
    # is_locked is computed in SQL against NOW() so the DATETIME stored by
    # DATE_ADD(NOW(), ...) is compared inside the database's own timezone
    # instead of comparing the stored string against Perl's clock string.
    my $user = $dbh->selectrow_hashref(
        "SELECT id, password_hash, failed_attempts, locked_until, is_active, is_admin,
                (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
         FROM users WHERE username = ?",
        undef, $username
    );

    return (0, 0) unless $user;
    return (0, 0) unless $user->{is_active};

    # Check if account is locked (computed in SQL; see the SELECT above)
    if ($user->{is_locked}) {
        return (0, 0);
    }

    if (bcrypt($password, substr($user->{password_hash}, 0, 29)) eq $user->{password_hash}) {
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

    # @summary List all users
    # @description Returns a list of all users in the system. Requires admin privileges.
    # Passwords are never included in the response.
    # @tags Users
    # @security bearerAuth
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

    # @summary Create new user
    # @description Creates a new user account in the system. Requires admin privileges.
    # Password must meet complexity requirements.
    # @tags Users
    # @security bearerAuth
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
            my $salt = Crypt::Eksblowfish::Bcrypt::en_base64(Crypt::Eksblowfish::Bcrypt::random_salt(16));
            my $hash = bcrypt($data->{password}, '$2a$12$' . $salt);
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

    # @summary Get user details
    # @description Retrieves detailed information about a specific user. Requires admin privileges.
    # Includes account status, login history, and security information.
    # @tags Users
    # @security bearerAuth
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

    # @summary Update user
    # @description Updates an existing user's account information. Requires admin privileges.
    # Cannot modify admin user except by themselves.
    # @tags Users
    # @security bearerAuth
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
            my $salt = Crypt::Eksblowfish::Bcrypt::en_base64(Crypt::Eksblowfish::Bcrypt::random_salt(16));
            push @params, bcrypt($data->{password}, '$2a$12$' . $salt);
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

    # @summary Delete user
    # @description Removes a user account from the system. Requires admin privileges.
    # Cannot delete the admin user.
    # @tags Users
    # @security bearerAuth
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