=head1 NAME

auth - login, token issuing, and the JWT middleware

=head1 SYNOPSIS

    # /login is public; the middleware guards the JWT group in the
    # cgi-bin/api dispatcher
    use auth qw(auth_middleware register_login register_session);
    register_login($db_config);
    group { under auth_middleware(app->defaults); ... };

=head1 DESCRIPTION

Login checks the local users table first (bcrypt) and falls back to
LDAP when it is enabled in the environment. Success issues a
one-hour signed JWT carrying the username and an is_admin flag;
repeated local failures lock the account for half an hour.

An optional group allowlist can gate LDAP logins further:
C<AUTH_LDAP_REQUIRE_GROUPS> takes pipe-separated group DNs (a DN
contains commas, so the pipe is the separator, not the comma).
When it is non-empty, a user bind only counts as a login if the
authenticated DN matches at least one listed group. The gate first
asks with the Active Directory transitive-membership matching rule
(nested groups count on AD); a server that rejects the rule - LLDAP
answers code 53 to any extensible match - falls back to a plain
C<(memberOf=DN)> equality search on the same connection. The login
is denied only when both searches fail or the successful one
matches nothing; empty or unset leaves the gate off, and any user
able to bind is accepted. Either way a valid LDAP login is still
treated as an admin (is_admin=1).

The signed claims (username, is_admin, exp) are also returned in
the /login response so web clients never decode the JWT
themselves, and C<GET /session> (JWT-protected) echoes the
caller's claims back.

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

our @EXPORT_OK = qw(auth_middleware register_login register_session);

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

        # Optional group allowlist: pipe-separated group DNs. A DN contains
        # commas, so the pipe is the separator. Empty/unset = no gate.
        require_groups => [ grep { length } split /\|/, ($ENV{AUTH_LDAP_REQUIRE_GROUPS} // '') ],
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
# Build the any-of filter for the optional group allowlist. Each group DN is
# escaped per RFC 4515 before it touches the filter, so raw environment text
# never reaches the LDAP server. Matching rule 1.2.840.113556.1.4.1941
# (LDAP_MATCHING_RULE_IN_CHAIN) makes nested/transitive group membership
# count, not just direct memberOf links. Returns '' when the list is empty
# (the caller skips the gate entirely). Servers that reject the rule get
# _ldap_group_filter_plain as the fallback.
# ---------------------------------------------------------------------------
sub _ldap_group_filter {
    my ($groups) = @_;
    return '' unless ref($groups) eq 'ARRAY' && @$groups;
    return '(|'
        . join('',
            map { '(memberOf:1.2.840.113556.1.4.1941:=' . _ldap_filter_escape($_) . ')' } @$groups)
        . ')';
}

# ---------------------------------------------------------------------------
# Plain-equality variant of _ldap_group_filter for servers that reject the
# AD transitive-membership matching rule (LLDAP answers code 53 to any
# extensible match). Same RFC 4515 escaping and any-of shape, but direct
# memberOf only - which is all such servers model anyway.
# ---------------------------------------------------------------------------
sub _ldap_group_filter_plain {
    my ($groups) = @_;
    return '' unless ref($groups) eq 'ARRAY' && @$groups;
    return '(|'
        . join('',
            map { '(memberOf=' . _ldap_filter_escape($_) . ')' } @$groups)
        . ')';
}

# Open an LDAP connection for login. Never onerror=>'die': AD referrals and
# unsupported matching rules must become a failed login, not HTTP 500.
# Protocol v3 + referrals off matches typical AD client config.
sub _ldap_connect {
    my ($cfg) = @_;
    my $ldap = Net::LDAP->new(
        $cfg->{server_uri},
        version => 3,
        verify  => $cfg->{ignore_cert} ? 'none' : 'require',
        onerror => undef,
    );
    return unless $ldap;
    eval {
        require Net::LDAP::Constant;
        $ldap->set_option(Net::LDAP::Constant::LDAP_OPT_REFERRALS(), 0);
    };
    return $ldap;
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

    my $ldap = _ldap_connect($cfg);
    if (!$ldap) {
        return (0, 'LDAP connection failed');
    }

    # Step 1: Bind with service account to search for the user DN
    my $mesg = eval { $ldap->bind($cfg->{bind_dn}, password => $cfg->{bind_pass}) };
    if ($@ || !$mesg || $mesg->code) {
        my $err = $@ || ($mesg ? $mesg->error : 'no-result');
        warn "[auth.pm] LDAP service bind failed: $err";
        eval { $ldap->unbind };
        return (0, 'LDAP service bind failed');
    }

    # Step 2: Search for the user by their uid/search attribute.
    # The username is escaped per RFC 4515 so a submitted value containing
    # filter metacharacters (* ( ) \ NUL) cannot alter the search filter.
    my $filter = '(' . $cfg->{search_attr} . '=' . _ldap_filter_escape($username) . ')';
    my $search = eval {
        $ldap->search(
            base   => $cfg->{base_dn},
            scope  => 'sub',
            filter => $filter,
            attrs  => ['dn', 'cn', 'givenName', 'sn'],
        );
    };
    if ($@ || !$search || $search->code || $search->count == 0) {
        warn "[auth.pm] LDAP user search failed: " . ($@ || ($search ? $search->error : 'no-result'));
        eval { $ldap->unbind };
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
    my $user_ldap = _ldap_connect($cfg);
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

    # Step 4: optional group allowlist. AUTH_LDAP_REQUIRE_GROUPS carries
    # pipe-separated group DNs (a DN contains commas, so the pipe is the
    # separator); an empty list leaves the gate off. The check reuses the
    # still-open service-bind connection and is scoped to the user's own
    # DN, so it answers "is this DN in any allowed group" in one query.
    # The nested AD rule is tried first (nested groups count).
    # Any nonzero code - LLDAP rejects the extensible match with code 53 -
    # triggers exactly one retry with the plain memberOf filter on the
    # SAME connection. Fail-closed: deny when both searches fail, or when
    # the successful search matches nothing. DNs reach either filter only
    # through _ldap_group_filter(plain) (escaped).
    my $require_groups = $cfg->{require_groups} || [];
    if (@$require_groups) {
        # $ldap was created with onerror => 'die'. An unsupported matching
        # rule (LLDAP code 53) therefore raises instead of returning a
        # Message object — that was the HTTP 500 on /login. Eval both
        # searches so a reject becomes a retry, not a 500.
        my $group_search = eval {
            $ldap->search(
                base   => $user_dn,
                scope  => 'base',
                filter => _ldap_group_filter($require_groups),
                attrs  => ['dn'],
            );
        };
        if ($@ || !$group_search || $group_search->code) {
            my $why = $@ ? $@ : ($group_search ? $group_search->code : 'no-result');
            warn "[auth.pm] LDAP group gate: nested memberOf rule rejected "
               . "($why); retrying with plain memberOf";
            $group_search = eval {
                $ldap->search(
                    base   => $user_dn,
                    scope  => 'base',
                    filter => _ldap_group_filter_plain($require_groups),
                    attrs  => ['dn'],
                );
            };
        }
        if ($@ || !$group_search || $group_search->code || $group_search->count == 0) {
            my $code  = $group_search ? $group_search->code  : 'undef';
            my $count = $group_search ? $group_search->count : 'undef';
            warn "[auth.pm] LDAP group gate denied $user_dn: "
               . "code=$code count=$count err=" . ($@ // '');
            $ldap->unbind;
            return (0, 'Invalid LDAP credentials');
        }
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
# Local password check. Returns 1 only on a real match.
# A non-bcrypt hash (legacy hex digests, empty, $2y$/$2b$ that we
# normalize) must not die: Crypt::Eksblowfish::Bcrypt::bcrypt croaks
# unless the settings are $2a$ or $2$, and an uncaught croak on this
# path is HTTP 500 before LDAP is ever tried.
# $2y$/$2b$ are the same algorithm; compare after rewriting the prefix
# so a PHP password_hash() row can still match.
# ---------------------------------------------------------------------------
sub _local_password_matches {
    my ($password, $hash) = @_;
    return 0 unless defined $password && defined $hash && length($hash) >= 29;
    my $settings = substr($hash, 0, 29);
    $settings =~ s/\A\$2[yb]\$/\$2a\$/;
    my $computed = eval { bcrypt($password, $settings) };
    return 0 if $@ || !defined $computed;
    my $stored = $hash;
    $stored =~ s/\A\$2[yb]\$/\$2a\$/;
    return $computed eq $stored ? 1 : 0;
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

            # Verify local password. A legacy non-bcrypt hash is a miss,
            # not an exception, so LDAP (if enabled) still runs.
            if (_local_password_matches($password, $local_user->{password_hash})) {
                $dbh->do(
                    "UPDATE users SET last_login = CURRENT_TIMESTAMP,
                                      failed_attempts = 0,
                                      locked_until = NULL
                     WHERE id = ?",
                    undef, $local_user->{id}
                );
                $dbh->disconnect;

                my ($token, $claims) = _issue_token($c, $username, $local_user->{is_admin} ? 1 : 0);
                return $c->render(json => { status => 'success', token => $token, %$claims });
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

                    # all valid LDAP authentications are admins
                    my ($token, $claims) = _issue_token($c, $username, 1);
                    return $c->render(json => { status => 'success', token => $token, %$claims });
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
            my ($token, $claims) = _issue_token($c, $username, 1);
            return $c->render(json => { status => 'success', token => $token, %$claims });
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
# Session endpoint - echoes the caller's JWT claims
# ---------------------------------------------------------------------------
sub register_session {

    # @summary Get current session claims
    # @description Returns the username, is_admin flag and expiry timestamp
    # carried by the caller's bearer token. Clients use it to inspect their
    # session without decoding the JWT themselves.
    # @tags Authentication
    # @security bearerAuth
    main::get '/session' => sub {
        my $c = shift;
        my $payload = $c->stash('jwt_payload');
        return $c->render(json => {
            status   => 'success',
            username => $payload->{username},
            is_admin => $payload->{is_admin},
            exp      => $payload->{exp},
        });
    };
}

# ---------------------------------------------------------------------------
# Internal: build and sign a JWT
# ---------------------------------------------------------------------------
sub _issue_token {
    my ($c, $username, $is_admin) = @_;

    my $jwt_secret = $c->app->defaults('jwt_secret');

    my %payload = (
        username => $username,
        is_admin => $is_admin ? JSON::true : JSON::false,
        exp      => time + 3600,
    );

    my $token = encode_jwt(
        payload => \%payload,
        key     => $jwt_secret,
        alg     => 'HS256',
    );

    # Hand the claims back alongside the signed token so the /login
    # response can carry them and PHP consumers never decode the JWT
    # themselves.
    return ($token, \%payload);
}

1;

