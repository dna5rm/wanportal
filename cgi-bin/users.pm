package users;
use strict;
use warnings;
use Exporter 'import';
use Digest::SHA qw(sha256_hex);
use DBI;

our @EXPORT_OK = qw(
    ensure_users_table
    validate_user
    create_user
    get_users
    register_users
);

# Called from main startup
sub ensure_users_table {
    my ($dbh, $admin_pwd) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0
        )
    });

    # Ensure admin user exists
    my ($exists) = $dbh->selectrow_array(
        "SELECT COUNT(*) FROM users WHERE username='admin'"
    );
    if (!$exists) {
        my $hash = sha256_hex($admin_pwd);
        $dbh->do(
            "INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, 1)",
            undef, 'admin', $hash
        );
    }
}

# Returns truthy if valid user/password
sub validate_user {
    my ($dbh, $username, $password) = @_;
    my $hash = sha256_hex($password);
    my ($id) = $dbh->selectrow_array(
        "SELECT id FROM users WHERE username=? AND password_hash=?",
        undef, $username, $hash
    );
    return $id;
}

# Create new user
sub create_user {
    my ($dbh, $username, $password, $is_admin) = @_;
    my $hash = sha256_hex($password);
    $dbh->do(
        "INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, ?)",
        undef, $username, $hash, ($is_admin ? 1 : 0)
    );
}

# Fetch users (optionally filter by username)
sub get_users {
    my ($dbh, $filter) = @_;
    my $sql = "SELECT id, username, is_admin FROM users";
    my @params;
    if ($filter && $filter->{username}) {
        $sql .= " WHERE username = ?";
        push @params, $filter->{username};
    }
    my $sth = $dbh->prepare($sql);
    $sth->execute(@params);
    return $sth->fetchall_arrayref({});
}

# Update user
sub update_user {
    my ($dbh, $id, $fields) = @_;
    my @set;
    my @params;

    if (defined $fields->{username}) {
        push @set, "username = ?";
        push @params, $fields->{username};
    }
    if (defined $fields->{password}) {
        my $hash = sha256_hex($fields->{password});
        push @set, "password_hash = ?";
        push @params, $hash;
    }
    if (defined $fields->{is_admin}) {
        push @set, "is_admin = ?";
        push @params, $fields->{is_admin} ? 1 : 0;
    }

    return 0 unless @set;

    push @params, $id;
    my $sql = "UPDATE users SET " . join(", ", @set) . " WHERE id = ?";
    my $rows = $dbh->do($sql, undef, @params);
    return $rows;
}

# Delete user
sub delete_user {
    my ($dbh, $id) = @_;
    # Don't allow deleting admin user!
    my ($username) = $dbh->selectrow_array("SELECT username FROM users WHERE id=?", undef, $id);
    return 0 if defined($username) && $username eq 'admin';
    my $rows = $dbh->do("DELETE FROM users WHERE id=?", undef, $id);
    return $rows;
}

# Register users CRUD endpoints (expand as needed)
sub register_users {
    my ($db_config) = @_;

    # List all users
    main::get '/users' => sub {
        my $c = shift;
        my $dbh = DBI->connect(
            $db_config->{dsn},
            $db_config->{username},
            $db_config->{password},
            { RaiseError => 1, AutoCommit => 1 }
        );
        my $users = get_users($dbh);
        $dbh->disconnect if $dbh;
        return $c->render(json => { status => 'success', users => $users });
    };

    # Create a new user (POST /users {username,password,is_admin})
    main::post '/users' => sub {
        my $c = shift;
        my $data = $c->req->json;
        unless ($data && $data->{username} && $data->{password}) {
            return $c->render(json => { status => 'error', message => 'Missing username or password' }, status => 400);
        }
        my $is_admin = $data->{is_admin} ? 1 : 0;
        my $dbh = DBI->connect(
            $db_config->{dsn},
            $db_config->{username},
            $db_config->{password},
            { RaiseError => 1, AutoCommit => 1 }
        );
        eval {
            create_user($dbh, $data->{username}, $data->{password}, $is_admin);
        };
        my $err = $@;
        $dbh->disconnect if $dbh;
        if ($err) {
            return $c->render(json => { status => 'error', message => 'Could not create user (maybe duplicate username?)' }, status => 400);
        }
        return $c->render(json => { status => 'success', message => 'User created.' });
    };

    # Update user (PUT /users {id,...fields...})
    main::put '/users' => sub {
        my $c = shift;
        my $data = $c->req->json;
        unless ($data && $data->{id}) {
            return $c->render(json => { status => 'error', message => 'Missing user id' }, status => 400);
        }
        my $fields = { };
        $fields->{username} = $data->{username}   if defined $data->{username};
        $fields->{password} = $data->{password}   if defined $data->{password} && $data->{password} ne "";
        $fields->{is_admin} = $data->{is_admin}   if defined $data->{is_admin};
        unless (%$fields) {
            return $c->render(json => { status => 'error', message => 'No fields to update' }, status => 400);
        }
        my $dbh = DBI->connect(
            $db_config->{dsn},
            $db_config->{username},
            $db_config->{password},
            { RaiseError => 1, AutoCommit => 1 }
        );
        my $updated = update_user($dbh, $data->{id}, $fields);
        $dbh->disconnect if $dbh;
        if ($updated) {
            return $c->render(json => { status => 'success', message => 'User updated.' });
        } else {
            return $c->render(json => { status => 'error', message => 'User not found or not updated.' }, status => 404);
        }
    };

    # Delete user (DELETE /users {id: ...})
    main::del '/users' => sub {
        my $c = shift;
        my $data = $c->req->json;
        unless ($data && $data->{id}) {
            return $c->render(json => { status => 'error', message => 'Missing user id' }, status => 400);
        }
        my $dbh = DBI->connect(
            $db_config->{dsn},
            $db_config->{username},
            $db_config->{password},
            { RaiseError => 1, AutoCommit => 1 }
        );
        my $deleted = delete_user($dbh, $data->{id});
        $dbh->disconnect if $dbh;
        if ($deleted) {
            return $c->render(json => { status => 'success', message => 'User deleted.' });
        } else {
            return $c->render(json => { status => 'error', message => 'User not found or cannot delete admin.' }, status => 400);
        }
    };
}

1;