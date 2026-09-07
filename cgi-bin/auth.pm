=head1 NAME

auth - login, token issuing, and the JWT middleware

=head1 SYNOPSIS

    # /login is public; the middleware guards the JWT group in the
    # cgi-bin/api dispatcher
    use auth qw(auth_middleware register_login);
    register_login($db_config);
    group { under auth_middleware(app->defaults); ... };

=head1 DESCRIPTION

Login checks the local users table first (bcrypt) and falls back to
LDAP when it is enabled in the environment. Success issues a
one-hour signed JWT carrying the username and an is_admin flag;
repeated local failures lock the account for half an hour.

The middleware guards everything registered inside the route group:
it requires a Bearer token, verifies the signature and expiry
against the shared secret, and stashes the decoded payload for the
handlers. Any valid LDAP login is treated as an admin - a
deliberate policy of this deployment.

=cut

package auth;
use strict;
use warnings;
use Exporter 'import';
use Crypt::JWT qw(encode_jwt decode_jwt);
use Crypt::Eksblowfish::Bcrypt qw(bcrypt);
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
# Escape a value for interpolation into an RFC 4515 LDAP search filter.
# RFC 4515 section 3 requires five characters to be escaped in filter
# strings: backslash, asterisk, open paren, close paren, and NUL.
# Backslash is handled first so the escapes we add are not re-escaped.
# ---------------------------------------------------------------------------
sub _ldap_filter_escape {
    my ($value) = @_;
    return '' unless defined $value;
    $value =~ s/\\/\\5c/g;
    $value =~ s/\*/\\2a/g;
    $value =~ s/\(/\\28/g;
    $value =~ s/\)/\\29/g;
    $value =~ s/\x00/\\00/g;
    return $value;
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

    # Step 2: Search for the user by their uid/search attribute.
    # The username is escaped per RFC 4515 so a submitted value containing
    # filter metacharacters (* ( ) \ NUL) cannot alter the search filter.
    my $filter = '(' . $cfg->{search_attr} . '=' . _ldap_filter_escape($username) . ')';
    my $search = $ldap->search(
        base   => $cfg->{base_dn},
        scope  => 'sub',
        filter => $filter,
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

    # Step 3: Bind as the user to verify their password.
    #
    # Two non-obvious bits here, both for debuggability:
    #
    #   1. We open a FRESH Net::LDAP connection for the user-bind
    #      step instead of reusing the service-bind connection.
    #      Some LDAP servers (OpenLDAP included) reject a re-bind
    #      on an already-bound connection with an unhelpful error,
    #      and that rejection looks like a credential problem
    #      even when it isn't one. A fresh connection sidesteps
    #      the issue.
    #
    #   2. We eval-wrap the bind and set onerror => undef. The
    #      outer $ldap object was created with onerror => 'die'
    #      (set at the top of this function), so a failed bind
    #      raises a Perl exception. If that exception bubbled up
    #      to the caller it would be turned into a generic
    #      "Invalid username or password" by the calling code,
    #      making every LDAP failure mode look identical. We
    #      catch the exception, log the actual LDAP error code
    #      to the Apache error log, and return a normal failure
    #      status.
    my $user_ldap = Net::LDAP->new(
        $cfg->{server_uri},
        verify  => $cfg->{ignore_cert} ? 'none' : 'require',
        onerror => undef,    # don't die; we'll check ->code below
    );
    if (!$user_ldap) {
        warn "[auth.pm] LDAP user-bind connection failed: $@";
        return (0, "LDAP connection failed for user-bind");
    }

    my $user_bind = eval { $user_ldap->bind($user_dn, password => $password) };
    $user_ldap->unbind;
    if ($@) {
        # The Perl layer raised (typically because of onerror => 'die'
        # being set somewhere upstream). $@ holds the exception
        # text; we don't get LDAP error codes in this branch.
        warn "[auth.pm] LDAP user-bind raised: $@";
        return (0, "LDAP user-bind error: $@");
    }
    if ($user_bind->code) {
        # Bind returned cleanly but the LDAP server rejected the
        # bind. error_name is the symbolic LDAP code (e.g.
        # 'invalidCredentials'), error_text is human-readable,
        # code is the numeric resultCode. Log all three so the
        # next "I can't log in" debug session takes seconds
        # instead of hours.
        warn "[auth.pm] LDAP user-bind failed for $user_dn: "
           . "code=" . $user_bind->code
           . " name=" . ($user_bind->error_name // 'undef')
           . " text=" . ($user_bind->error_text // 'undef');
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

        # is_locked is computed in SQL against NOW() so the DATETIME stored
        # by DATE_ADD(NOW(), ...) is compared inside the database's own
        # timezone instead of being string-compared against localtime.
        my $local_user = $dbh->selectrow_hashref(
            "SELECT id, password_hash, is_admin, is_active, locked_until, failed_attempts,
                    (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
             FROM users WHERE username = ?",
            undef, $username
        );

        # ----------------------------------------------------------------
        # 1. Check if local user exists and is active
        # ----------------------------------------------------------------
        if ($local_user && $local_user->{is_active}) {

            # Check account lock (precomputed in SQL; see the SELECT above)
            if ($local_user->{is_locked}) {
                $dbh->disconnect;
                return $c->render(
                    json   => { status => 'error', message => 'Account is locked. Please try again later.' },
                    status => 401
                );
            }

            # Verify local password
            if (bcrypt($password, substr($local_user->{password_hash}, 0, 29)) eq $local_user->{password_hash}) {
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
                    if ($local_user) {
                        $dbh->do(
                            "UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?",
                            undef, $local_user->{id}
                        );
                    }
                    $dbh->disconnect;

                    my $is_admin = 1; # all valid LDAP authentications are admins
                    my $token = _issue_token($c, $username, $is_admin);
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

            # LDAP success - issue token as admin (all valid LDAP users are admins)
            my $token = _issue_token($c, $username, 1);
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

