package auth;
use strict;
use warnings;
use Exporter 'import';
use Crypt::JWT qw(encode_jwt decode_jwt);
use Digest::SHA qw(sha256_hex);
use DBI;
use users qw(validate_user);

# Optional LDAP support - gracefully handle missing module
my $LDAP_AVAILABLE = 0;
eval {
    require Net::LDAP;
    $LDAP_AVAILABLE = 1;
};

our @EXPORT_OK = qw(auth_middleware register_login);

# ---------------------------------------------------------------------------
# Read LDAP config from environment
# ---------------------------------------------------------------------------
sub _ldap_config {
    my $ldap_enabled = $ENV{AUTH_LDAP_ENABLED}       // 'false';
    my $ignore_cert  = $ENV{LDAP_IGNORE_CERT_ERRORS} // 'false';
    $ldap_enabled    =~ s/\s+//g;
    $ignore_cert     =~ s/\s+//g;

    return {
        enabled     => ($ldap_enabled =~ /^(true|1|yes)$/i) ? 1 : 0,
        server_uri  => $ENV{AUTH_LDAP_SERVER_URI}         // '',
        bind_dn     => $ENV{AUTH_LDAP_BIND_DN}            // '',
        bind_pass   => $ENV{AUTH_LDAP_BIND_PASSWORD}      // '',
        base_dn     => $ENV{AUTH_LDAP_USER_SEARCH_BASEDN} // '',
        search_attr => $ENV{AUTH_LDAP_USER_SEARCH_ATTR}   // 'uid',
        ignore_cert => ($ignore_cert =~ /^(true|1|yes)$/i) ? 1 : 0,
    };
}

# ---------------------------------------------------------------------------
# Attempt LDAP authentication
# Returns: (1, $full_name) on success, (0, $error_message) on failure
# ---------------------------------------------------------------------------
sub _ldap_authenticate {
    my ($username, $password, $cfg) = @_;

    unless ($LDAP_AVAILABLE) {
        return (0, 'Net::LDAP module not available');
    }

    unless ($cfg->{server_uri} && $cfg->{base_dn}) {
        return (0, 'LDAP configuration incomplete');
    }

    # Reject empty passwords - some LDAP servers allow anonymous bind
    # with an empty password which would be a security hole
    unless (defined $password && length($password) > 0) {
        return (0, 'Empty password not allowed');
    }

    my $ldap;
    eval {
        $ldap = Net::LDAP->new(
            $cfg->{server_uri},
            verify  => $cfg->{ignore_cert} ? 'none' : 'require',
            onerror => 'die',
        );
    };
    if ($@ || !$ldap) {
        return (0, "LDAP connection failed: $@");
    }

    # Step 1: Bind with service account to search for the user DN
    my $mesg = $ldap->bind($cfg->{bind_dn}, password => $cfg->{bind_pass});
    if ($mesg->code) {
        $ldap->unbind;
        return (0, 'LDAP service bind failed: ' . $mesg->error);
    }

    # Step 2: Search for the user by their uid/search attribute
    my $search = $ldap->search(
        base   => $cfg->{base_dn},
        scope  => 'sub',
        filter => "($cfg->{search_attr}=$username)",
        attrs  => ['dn', 'cn', 'givenName', 'sn'],
    );
    if ($search->code || $search->count == 0) {
        $ldap->unbind;
        return (0, 'User not found in LDAP');
    }

    my $entry     = $search->entry(0);
    my $user_dn   = $entry->dn;
    my $full_name = $entry->get_value('cn')
                 // join(' ',
                        grep { defined $_ }
                        $entry->get_value('givenName'),
                        $entry->get_value('sn')
                    )
                 // $username;

    # Step 3: Bind as the user to verify their password
    my $user_bind = $ldap->bind($user_dn, password => $password);
    $ldap->unbind;

    if ($user_bind->code) {
        return (0, 'Invalid LDAP credentials');
    }

    return (1, $full_name);
}

# ---------------------------------------------------------------------------
# Auth middleware - validates JWT on protected routes
# ---------------------------------------------------------------------------
sub auth_middleware {
    my ($config) = @_;
    my $jwt_secret = $config->{jwt_secret};

    return sub {
        my $c = shift;
        my $auth_header = $c->req->headers->authorization;

        unless ($auth_header && $auth_header =~ /^Bearer\s+(.+)/) {
            $c->render(
                json   => { status => 'error', message => 'Missing or invalid token' },
                status => 401
            );
            return undef;
        }

        my $token   = $1;
        my $payload;
        eval {
            $payload = decode_jwt(
                token => $token,
                key   => $jwt_secret,
                alg   => 'HS256'
            );
        };

        if ($@ || $payload->{exp} < time) {
            $c->render(
                json   => { status => 'error', message => 'Invalid or expired token' },
                status => 401
            );
            return undef;
        }

        $c->stash(jwt_payload => $payload);
        return 1;
    };
}

# ---------------------------------------------------------------------------
# Login endpoint
# ---------------------------------------------------------------------------
sub register_login {
    my ($db_config) = @_;

    # @summary User authentication
    # @description Authenticates a user via local database or LDAP (if enabled).
    # LDAP users are granted regular (non-admin) access.
    # Failed local login attempts are tracked; accounts may be locked after 5 failures.
    # @tags Authentication
    main::post '/login' => sub {
        my $c = shift;
        $c->app->log->debug("Received request for /login");

        my $data = $c->req->json;
        unless (defined $data && exists $data->{username} && exists $data->{password}) {
            return $c->render(
                json   => { status => 'error', message => 'Invalid input' },
                status => 400
            );
        }

        my ($username, $password) = ($data->{username}, $data->{password});
        my $ldap_cfg = _ldap_config();

        my $dbh = DBI->connect(
            $db_config->{dsn},
            $db_config->{username},
            $db_config->{password},
            { RaiseError => 1, AutoCommit => 1 }
        );

        my $local_user = $dbh->selectrow_hashref(
            "SELECT id, password_hash, is_admin, is_active, locked_until, failed_attempts
             FROM users WHERE username = ?",
            undef, $username
        );

        # ----------------------------------------------------------------
        # 1. Check if local user exists and is active
        # ----------------------------------------------------------------
        if ($local_user && $local_user->{is_active}) {

            # Check account lock
            if ($local_user->{locked_until} && $local_user->{locked_until} gt scalar localtime) {
                $dbh->disconnect;
                return $c->render(
                    json   => { status => 'error', message => 'Account is locked. Please try again later.' },
                    status => 401
                );
            }

            # Verify local password
            if (sha256_hex($password) eq $local_user->{password_hash}) {
                $dbh->do(
                    "UPDATE users SET last_login = CURRENT_TIMESTAMP,
                                      failed_attempts = 0,
                                      locked_until = NULL
                     WHERE id = ?",
                    undef, $local_user->{id}
                );
                $dbh->disconnect;

                my $token = _issue_token($c, $username, $local_user->{is_admin} ? 1 : 0);
                return $c->render(json => { status => 'success', token => $token });
            }

            # ----------------------------------------------------------------
            # Local password failed - if LDAP enabled, try LDAP before
            # incrementing failure counter. This handles the case where a
            # local user record exists but the user now authenticates via LDAP.
            # ----------------------------------------------------------------
            if ($ldap_cfg->{enabled}) {
                my ($ldap_ok, $ldap_info) = _ldap_authenticate($username, $password, $ldap_cfg);

                if ($ldap_ok) {
                    # LDAP success - reset any local failure counter
                    $dbh->do(
                        "UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?",
                        undef, $local_user->{id}
                    );
                    $dbh->disconnect;

                    # Issue token using local user's is_admin flag
                    my $token = _issue_token($c, $username, $local_user->{is_admin} ? 1 : 0);
                    return $c->render(json => { status => 'success', token => $token });
                }
            }

            # Both local and LDAP (if enabled) failed - increment failure counter
            my $attempts = ($local_user->{failed_attempts} // 0) + 1;
            my $lock_sql = $attempts >= 5
                ? ", locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE)"
                : "";

            $dbh->do(
                "UPDATE users SET failed_attempts = ? $lock_sql WHERE id = ?",
                undef, $attempts, $local_user->{id}
            );
            $dbh->disconnect;

            return $c->render(
                json   => { status => 'error', message => 'Invalid username or password' },
                status => 401
            );
        }

        $dbh->disconnect;

        # ----------------------------------------------------------------
        # 2. No matching local user - try LDAP if enabled
        # ----------------------------------------------------------------
        if ($ldap_cfg->{enabled}) {
            my ($ldap_ok, $ldap_info) = _ldap_authenticate($username, $password, $ldap_cfg);

            unless ($ldap_ok) {
                return $c->render(
                    json   => { status => 'error', message => 'Invalid username or password' },
                    status => 401
                );
            }

            # LDAP success - issue token as non-admin
            my $token = _issue_token($c, $username, 0);
            return $c->render(json => { status => 'success', token => $token });
        }

        # ----------------------------------------------------------------
        # 3. No local user and LDAP disabled
        # ----------------------------------------------------------------
        return $c->render(
            json   => { status => 'error', message => 'Invalid username or password' },
            status => 401
        );
    };
}

# ---------------------------------------------------------------------------
# Internal: build and sign a JWT
# ---------------------------------------------------------------------------
sub _issue_token {
    my ($c, $username, $is_admin) = @_;

    my $jwt_secret = $c->app->defaults('jwt_secret');

    return encode_jwt(
        payload => {
            username => $username,
            is_admin => $is_admin ? JSON::true : JSON::false,
            exp      => time + 3600,
        },
        key => $jwt_secret,
        alg => 'HS256',
    );
}

1;

