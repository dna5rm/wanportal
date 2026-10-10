=head1 NAME

services - HTTP(S) service check writes (JWT-protected; reads live in public_api)

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, inside the JWT group
    use services qw(register_services);
    register_services($db_config);

=head1 DESCRIPTION

Owns the C<services> table, C<validate_service_data>, and the service
write routes: create, update, delete, plus the admin-only counter
reset. A service is agent + target + URI (scheme, port, path, query)
+ an F5-style send/receive/disable assertion. The URI lives on the
service, not on C<targets>, so one host row backs many checks (the
proxy / LLM case).

The read half - the listing (C<GET /services>) and the single detail
(C<GET /service/:id>) - is PUBLIC and lives in public_api.pm beside
C</monitors>: the console pages show service status to anonymous
visitors, so the read routes answer a display-safe field set there
(no auth reference, no request body/header map, no assertion
strings, no HTTP method). A caller presenting a valid Bearer JWT
still receives the full configuration row on the public detail,
which is what the SPA editor prefetches before a PUT on the routes
below.

Two things this module deliberately does NOT do:

    - it never touches the network. All target-facing I/O belongs to
      the agent (spec I4); the portal only stores and serves config.
    - it never stores or returns a secret. Auth is reference-only
      (spec 5.4): a check that needs authentication carries
      C<auth_credential_id> into the existing C<credentials> table,
      and every response exposes only that id - and only to JWT
      callers, never on the public read routes.

Every route here requires a valid user JWT; the writes and the reset
are admin-only. Attaching a HIGH or CRITICAL sensitivity credential
requires an admin (403) on top of the route gate. The agent-facing
half of the contract (the polling pair C</agent/:id/services>) is
owned by agent_services.pm, not here.

=cut

package services;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use Data::UUID;
use JSON qw(encode_json decode_json);
use Try::Tiny;

our @EXPORT_OK = qw(register_services);

sub ensure_services_table {
    my ($dbh) = @_;
    $dbh->do(q{
        CREATE TABLE IF NOT EXISTS services (
            id               char(36)     NOT NULL,
            description      varchar(255) DEFAULT '',
            agent_id         char(36)     NOT NULL,
            target_id        char(36)     NOT NULL,

            -- the URI (target supplies the host; these supply the rest)
            scheme           varchar(8)   NOT NULL DEFAULT 'http',
            port             int(11)      DEFAULT 0,
            uri_path         varchar(512) DEFAULT '/',
            uri_query        varchar(512) DEFAULT '',

            -- request
            http_method      varchar(10)  NOT NULL DEFAULT 'GET',
            http_headers     json         DEFAULT NULL,
            body_encoding    varchar(16)  DEFAULT NULL,
            body             mediumtext   DEFAULT NULL,

            -- F5 assertion triple
            send_string      varchar(1024) DEFAULT NULL,
            receive_string   varchar(1024) DEFAULT NULL,
            receive_regex    tinyint(1)    NOT NULL DEFAULT 0,
            disable_string   varchar(1024) DEFAULT NULL,
            disable_regex    tinyint(1)    NOT NULL DEFAULT 0,

            -- response expectations; NULL = any 2xx/3xx
            expected_status  varchar(64)  DEFAULT NULL,
            follow_redirects tinyint(1)   NOT NULL DEFAULT 0,
            verify_tls       tinyint(1)   NOT NULL DEFAULT 1,
            timeout          int(11)      NOT NULL DEFAULT 10,

            -- auth, reference-only into credentials (5.4); never inline a secret
            auth_type        varchar(16)  DEFAULT NULL,
            auth_header_name varchar(128) DEFAULT NULL,
            auth_credential_id char(36)   DEFAULT NULL,

            -- scheduling; set once at create, see the PUT route for why
            pollcount        int(11)      DEFAULT 1,
            pollinterval     int(11)      DEFAULT 300,
            is_active        tinyint(1)   NOT NULL DEFAULT 1,

            -- rolled-up state; the RRD keeps the history, these are current only
            last_state       varchar(12)  DEFAULT 'UNKNOWN',
            last_status_code int(11)      DEFAULT 0,
            last_reason      varchar(64)  DEFAULT NULL,
            last_message     varchar(255) DEFAULT NULL,
            last_check       datetime     DEFAULT NULL,
            last_change      datetime     DEFAULT NULL,
            total_down       int(11)      DEFAULT 0,

            PRIMARY KEY (id),
            -- one host can serve many checks; only the URI distinguishes
            -- two services on the same target+agent (3.2). uri_query is in
            -- the key on purpose: ?model=a and ?model=b are distinct checks.
            UNIQUE KEY services_uniqueness (agent_id, target_id, scheme, port, uri_path, uri_query),
            KEY services_agent_idx (agent_id),
            KEY services_target_idx (target_id),
            CONSTRAINT services_agent_fk FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE,
            -- targets is the single host registry (I8): deleting a host takes
            -- its reachability monitors AND its services with it.
            CONSTRAINT services_target_fk FOREIGN KEY (target_id) REFERENCES targets (id) ON DELETE CASCADE,
            -- RESTRICT, not CASCADE: a running check must not have its secret
            -- yanked out from under it (5.4). Referenced credentials refuse to
            -- be deleted at the credential route instead.
            CONSTRAINT services_credential_fk FOREIGN KEY (auth_credential_id) REFERENCES credentials (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
    });
}

# Section 5.1 validation, all rules closed. Returns a list of error
# strings; empty list means valid. Calls in the routes render the list
# as the usual "Validation failed: ..." 400.
#
# $dbh and $is_admin are OPTIONAL and default to off, so the pure
# field rules can be tested without a database. When a $dbh is given,
# the credential rules run against the credentials table: the id must
# exist there, and a HIGH/CRITICAL sensitivity credential may only be
# attached by an admin. That second rule is an authorization decision,
# not a field check, so it is signalled by pushing the sentinel message
# below; the routes recognise that exact string and answer 403 instead
# of folding it into the 400 envelope.
#
# The sentinel is inlined here AND in each route: these subs are
# extracted and eval'd one at a time by the brace-extraction tests, so
# none of them may reach for a file-level lexical. Keep the copies in
# sync.
sub validate_service_data {
    my ($data, $is_update, $dbh, $is_admin) = @_;
    $data = {} unless ref($data) eq 'HASH';
    my @errors;
    my $credential_admin_error = 'Admin required to attach a HIGH or CRITICAL sensitivity credential';

    # agent_id / target_id required on create (5.1). Existence is the
    # routes' job, where it maps to the 404 the monitor routes answer.
    unless ($is_update) {
        push @errors, "Missing agent_id" unless $data->{agent_id};
        push @errors, "Missing target_id" unless $data->{target_id};
    }
    else {
        # Partial update only: absent keys mean "leave alone", but an
        # explicit null or empty here would bind NULL into NOT NULL
        # columns and die as a 500 further down. Catch it as a 400.
        push @errors, "agent_id cannot be empty"
            if exists $data->{agent_id} && !(defined $data->{agent_id} && length $data->{agent_id});
        push @errors, "target_id cannot be empty"
            if exists $data->{target_id} && !(defined $data->{target_id} && length $data->{target_id});
    }

    # scheme: http|https. Case-insensitive here because URLs are, but
    # stored lowercase so the uniqueness key and the agent agree.
    if (exists $data->{scheme}) {
        if (defined $data->{scheme} && $data->{scheme} =~ /^(https?)$/i) {
            $data->{scheme} = lc $data->{scheme};
        }
        else {
            push @errors, "Invalid scheme (must be http or https)";
        }
    }

    # port: 0 means "scheme default" and lives alongside real ports
    if (defined $data->{port}) {
        unless ($data->{port} =~ /^\d+$/ && $data->{port} <= 65535) {
            push @errors, "Port must be 0 (scheme default) or between 1 and 65535";
        }
    }

    # uri_path: empty or leading '/' — the host can never hide in the
    # path, so a body like "http://evil.example/x" is refused, not fixed.
    if (exists $data->{uri_path}) {
        my $path = $data->{uri_path} // '';
        $data->{uri_path} = $path;   # null means empty, not SQL NULL, keeps the unique key honest
        push @errors, "uri_path must be empty or start with '/'"
            if length($path) && $path !~ m{^/};
        push @errors, "uri_path must not contain a scheme or host"
            if $path =~ m{://};
    }

    my %VALID_HTTP_METHODS = map { $_ => 1 } qw(GET POST PUT PATCH HEAD OPTIONS DELETE);
    if (exists $data->{http_method}) {
        if (defined $data->{http_method} && $VALID_HTTP_METHODS{uc $data->{http_method}}) {
            $data->{http_method} = uc $data->{http_method};
        }
        else {
            push @errors, "Invalid http_method (must be one of GET, POST, PUT, PATCH, HEAD, OPTIONS, DELETE)";
        }
    }

    my %VALID_BODY_ENCODINGS = map { $_ => 1 } qw(text json base64 form);
    if (exists $data->{body_encoding}) {
        if (defined $data->{body_encoding} && length $data->{body_encoding}) {
            if ($VALID_BODY_ENCODINGS{lc $data->{body_encoding}}) {
                $data->{body_encoding} = lc $data->{body_encoding};
            }
            else {
                push @errors, "Invalid body_encoding (must be text, json, base64, or form)";
            }
        }
    }
    my $has_body = defined $data->{body} && $data->{body} ne '' ? 1 : 0;
    if ($has_body && !(defined $data->{body_encoding} && length $data->{body_encoding})) {
        push @errors, "body_encoding is required when body is set";
    }

    # The encoding promises the agent a shape; honouring it at save time
    # beats discovering it broken at probe time.
    if ($has_body && defined $data->{body_encoding} && length $data->{body_encoding}) {
        my $encoding = $data->{body_encoding};
        if ($encoding eq 'json') {
            my $decoded = eval { JSON->new->decode($data->{body}) };
            push @errors, "body does not contain valid JSON" if $@;
        }
        elsif ($encoding eq 'base64') {
            # RFC 2045 tolerates embedded CRLF, so strip whitespace before
            # the shape check: length must be a multiple of 4 with at most
            # two trailing '='.
            my $b64 = $data->{body};
            $b64 =~ s/\s+//g;
            unless ($b64 =~ /\A[A-Za-z0-9+\/]+={0,2}\z/ && length($b64) % 4 == 0) {
                push @errors, "body is not valid base64";
            }
        }
        elsif ($encoding eq 'form') {
            my $parses = 1;
            foreach my $pair (split /&/, $data->{body}) {
                unless ($pair =~ /^[^&=]+=/) {
                    $parses = 0;
                    last;
                }
            }
            push @errors, "body does not parse as form data (k=v&k2=v2)" unless $parses;
        }
    }

    # F5 model (4): send_string IS the request body, so "both" makes no
    # sense — the agent would have to pick one and nobody agrees which.
    if ($has_body && defined $data->{send_string} && $data->{send_string} ne '') {
        push @errors, "send_string and body cannot both be set";
    }

    # An invalid regex is a config error caught here, never a probe-time
    # crash (4). The empty-string case skips: qr// compiles fine and
    # matching everything is what the column default already means.
    if ($data->{receive_regex} && defined $data->{receive_string} && length $data->{receive_string}) {
        eval { qr/$data->{receive_string}/ };
        push @errors, "receive_regex does not compile: $@" if $@;
    }
    if ($data->{disable_regex} && defined $data->{disable_string} && length $data->{disable_string}) {
        eval { qr/$data->{disable_string}/ };
        push @errors, "disable_regex does not compile: $@" if $@;
    }

    # expected_status: comma-separated NNN or NNN-NNN, 100..599. Normalise
    # the stored form (spaces stripped, one comma) so the agent can split
    # on ',' with no parsing opinions of its own.
    if (defined $data->{expected_status} && length $data->{expected_status}) {
        my @clean;
        foreach my $token (split /\s*,\s*/, $data->{expected_status}) {
            next unless length $token;
            if ($token =~ /^(\d{3})(?:-(\d{3}))?$/) {
                my ($low, $high) = ($1, $2);
                if ($low < 100 || $low > 599 || (defined $high && ($high < 100 || $high > 599))) {
                    push @errors, "Invalid expected_status token: $token (status codes must be 100..599)";
                }
                elsif (defined $high && $high < $low) {
                    # A reversed range would silently match nothing and the
                    # check would sit DOWN forever; refuse it up front.
                    push @errors, "Invalid expected_status token: $token (range start must not exceed range end)";
                }
                else {
                    push @clean, (defined $high ? "$low-$high" : "$low");
                }
            }
            else {
                push @errors, "Invalid expected_status token: $token (must be NNN or NNN-NNN)";
            }
        }
        $data->{expected_status} = join(",", @clean) if @clean;
    }

    # timeout: int seconds, 1..120 — a 0 or negative timeout would make the
    # agent's HTTP client behave inconsistently across transports
    if (exists $data->{timeout}) {
        unless (defined $data->{timeout} && $data->{timeout} =~ /^\d+$/ && $data->{timeout} >= 1 && $data->{timeout} <= 120) {
            push @errors, "Timeout must be between 1 and 120";
        }
    }

    # Boolean toggles: normalise nulls and truthies to the 0|1 the columns
    # declare (NOT NULL), so a JSON null cannot reach the DB as a 500.
    # verify_tls nulls back to 1 on purpose — disabling verification is a
    # recorded, deliberate act (4), never something a stray null does.
    if (exists $data->{follow_redirects}) {
        $data->{follow_redirects} = (defined $data->{follow_redirects} && $data->{follow_redirects}) ? 1 : 0;
    }
    if (exists $data->{verify_tls}) {
        $data->{verify_tls} = (!defined $data->{verify_tls} || $data->{verify_tls}) ? 1 : 0;
    }
    if (exists $data->{receive_regex}) {
        $data->{receive_regex} = (defined $data->{receive_regex} && $data->{receive_regex}) ? 1 : 0;
    }
    if (exists $data->{disable_regex}) {
        $data->{disable_regex} = (defined $data->{disable_regex} && $data->{disable_regex}) ? 1 : 0;
    }
    if (exists $data->{is_active}) {
        $data->{is_active} = (defined $data->{is_active} && $data->{is_active}) ? 1 : 0;
    }

    my %VALID_AUTH_TYPES = map { $_ => 1 } qw(none basic bearer header);
    if (exists $data->{auth_type}) {
        if (defined $data->{auth_type} && length $data->{auth_type} && $VALID_AUTH_TYPES{lc $data->{auth_type}}) {
            $data->{auth_type} = lc $data->{auth_type};
            if ($data->{auth_type} eq 'header'
                && !(defined $data->{auth_header_name} && length $data->{auth_header_name})) {
                push @errors, "auth_header_name is required when auth_type is header";
            }
        }
        else {
            push @errors, "Invalid auth_type (must be none, basic, bearer, or header)";
        }
    }

    # No credential, no auth (5.4): a service never carries the secret
    # itself, so auth_type beyond none without a credential reference is
    # simply unusable config.
    my $auth_type = defined $data->{auth_type} && length $data->{auth_type} ? $data->{auth_type} : undef;
    my $cred_id   = $data->{auth_credential_id};
    if (defined $auth_type && $auth_type ne 'none'
        && !(defined $cred_id && length $cred_id)) {
        push @errors, "auth_credential_id is required when auth_type is not none";
    }

    # The credential itself must exist, and HIGH/CRITICAL are admin-only
    # to attach (5.4). Both 400-able via this function's envelope, except
    # the admin rule which the routes lift out to a 403.
    if (defined $cred_id && length $cred_id && ref($dbh)) {
        my $cred = eval {
            $dbh->selectrow_hashref("SELECT id, sensitivity FROM credentials WHERE id = ?", undef, $cred_id);
        };
        if ($@ || !$cred) {
            # 400, not 404: 5.1 closes every rule with the
            # "Validation failed:" envelope and this is one of them.
            push @errors, "Credential not found";
        }
        elsif (!$is_admin && ($cred->{sensitivity} // '') =~ /^(HIGH|CRITICAL)$/) {
            push @errors, $credential_admin_error;
        }
    }

    # http_headers arrives as a JSON object (header map) from the API; the
    # column is a json type, so whatever ends up stored must be real JSON
    # or the DB rejects the row. Normalise to the encoded string the
    # INSERT/UPDATE binds, or SQL NULL when the caller clears the map.
    if (exists $data->{http_headers}) {
        my $headers = $data->{http_headers};
        my $obj;
        my $bad;
        if (!defined $headers || (!ref($headers) && !length $headers)) {
            $obj = undef;
        }
        elsif (ref($headers) eq 'HASH') {
            $obj = $headers;
        }
        elsif (!ref($headers)) {
            # A pre-encoded string is accepted as long as it decodes to an
            # object; anything else is a shape we cannot reason about.
            $obj = eval { JSON->new->decode($headers) };
            if ($@ || ref($obj) ne 'HASH') {
                $bad = 1;
            }
        }
        else {
            $bad = 1;   # arrays and refs are not a header map
        }
        if ($bad) {
            push @errors, "http_headers must be a JSON object";
        }
        elsif (defined $obj) {
            my $encoded = eval { encode_json($obj) };
            if ($@) {
                push @errors, "http_headers must be a JSON object";
            }
            else {
                $data->{http_headers_json} = $encoded;
            }
        }
        else {
            $data->{http_headers_json} = undef;
        }
    }

    # Set-once pair; ranges are the RRD's problem, so only reject nonsense
    # (non-numeric or zero) here rather than duplicating the monitor bounds.
    if (defined $data->{pollcount}) {
        push @errors, "Pollcount must be a positive integer"
            unless $data->{pollcount} =~ /^\d+$/ && $data->{pollcount} >= 1;
    }
    if (defined $data->{pollinterval}) {
        push @errors, "Pollinterval must be a positive integer"
            unless $data->{pollinterval} =~ /^\d+$/ && $data->{pollinterval} >= 1;
    }

    return @errors;
}

sub register_services {
    my ($db_config) = @_;

    # Initialize table
    my $dbh = DBI->connect(
        $db_config->{dsn},
        $db_config->{username},
        $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    ensure_services_table($dbh);
    $dbh->disconnect;

    # @summary Create new service
    # @description Creates a new HTTP(S) check for an agent and target,
    # including the URI (scheme, port, path, query), the F5 assertion triple
    # and optional credential-backed auth. pollcount and pollinterval are
    # fixed at creation. Admin-only.
    # @tags Services
    # @security bearerAuth
    main::post '/service' => sub {
        my $c = shift;

        my $is_admin = $c->stash('jwt_payload') && $c->stash('jwt_payload')->{is_admin};
        if (!$is_admin) {
            return $c->render(json => {
                status => 'error',
                message => 'Admin required'
            }, status => 403);
        }

        my $data = $c->req->json;
        my $dbh;

        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            # Validation needs the handle: the credential rules consult the
            # credentials table (existence, sensitivity).
            my @validation_errors = validate_service_data($data, 0, $dbh, $is_admin);
            if (@validation_errors) {
                $dbh->disconnect;
                # The HIGH/CRITICAL rule is authorization, not validation:
                # it gets its own 403 so the UI can hide the control, instead
                # of a 400 that looks like a typo.
                if (grep { $_ eq 'Admin required to attach a HIGH or CRITICAL sensitivity credential' } @validation_errors) {
                    return $c->render(json => {
                        status => 'error',
                        message => 'Admin required to attach a HIGH or CRITICAL sensitivity credential'
                    }, status => 403);
                }
                return $c->render(json => {
                    status => 'error',
                    message => 'Validation failed: ' . join(', ', @validation_errors)
                }, status => 400);
            }

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
                INSERT INTO services (
                    id, description, agent_id, target_id, scheme, port, uri_path, uri_query,
                    http_method, http_headers, body_encoding, body,
                    send_string, receive_string, receive_regex, disable_string, disable_regex,
                    expected_status, follow_redirects, verify_tls, timeout,
                    auth_type, auth_header_name, auth_credential_id,
                    pollcount, pollinterval, is_active
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?
                )
            }, undef,
                $uuid,
                $data->{description} // '',
                $data->{agent_id},
                $data->{target_id},
                $data->{scheme} // 'http',
                $data->{port} // 0,
                $data->{uri_path} // '/',
                $data->{uri_query} // '',
                $data->{http_method} // 'GET',
                $data->{http_headers_json},
                $data->{body_encoding},
                $data->{body},
                $data->{send_string},
                $data->{receive_string},
                $data->{receive_regex} // 0,
                $data->{disable_string},
                $data->{disable_regex} // 0,
                $data->{expected_status},
                $data->{follow_redirects} // 0,
                $data->{verify_tls} // 1,
                $data->{timeout} // 10,
                $data->{auth_type},
                $data->{auth_header_name},
                $data->{auth_credential_id},
                $data->{pollcount} // 1,
                $data->{pollinterval} // 300,
                $data->{is_active} // 1
            );

            $dbh->disconnect;

            return $c->render(json => {
                status => 'success',
                message => 'Service created successfully',
                id => $uuid
            });
        } catch {
            $dbh->disconnect if $dbh;

            if ($_ =~ /Duplicate entry.*for key 'services_uniqueness'/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Service with these parameters already exists'
                }, status => 400);
            }

            return $c->render(json => {
                status => 'error',
                message => "Failed to create service: $_"
            }, status => 500);
        };
    };

    # @summary Update service configuration
    # @description Updates an existing service check: URI, request options,
    # assertion triple, auth reference and active flag. pollcount and
    # pollinterval cannot be changed. Admin-only.
    # @tags Services
    # @security bearerAuth
    main::put '/service/:id' => sub {
        my $c = shift;

        my $is_admin = $c->stash('jwt_payload') && $c->stash('jwt_payload')->{is_admin};
        if (!$is_admin) {
            return $c->render(json => {
                status => 'error',
                message => 'Admin required'
            }, status => 403);
        }

        my $id = $c->param('id');
        my $data = $c->req->json;
        my $dbh;

        unless ($data) {
            return $c->render(json => {
                status => 'error',
                message => 'No update data provided'
            }, status => 400);
        }

        # Set-once like the monitor routes: the service RRD's archives are
        # sized from pollcount/pollinterval at create time (3.4), so letting
        # these move afterwards would desync the fixed RRD layout.
        if (exists $data->{pollcount} || exists $data->{pollinterval}) {
            return $c->render(json => {
                status => 'error',
                message => 'Cannot modify polling parameters after service creation. Delete and recreate the service to change these values.'
            }, status => 400);
        }

        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            my @validation_errors = validate_service_data($data, 1, $dbh, $is_admin);
            if (@validation_errors) {
                $dbh->disconnect;
                if (grep { $_ eq 'Admin required to attach a HIGH or CRITICAL sensitivity credential' } @validation_errors) {
                    return $c->render(json => {
                        status => 'error',
                        message => 'Admin required to attach a HIGH or CRITICAL sensitivity credential'
                    }, status => 403);
                }
                return $c->render(json => {
                    status => 'error',
                    message => 'Validation failed: ' . join(', ', @validation_errors)
                }, status => 400);
            }

            # Check if service exists
            my $exists = $dbh->selectrow_array(
                "SELECT 1 FROM services WHERE id = ?",
                undef, $id
            );
            unless ($exists) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Service not found'
                }, status => 404);
            }

            # Build update query (only allowed fields; the schedule pair was
            # refused above, and the rolled-up columns belong to the agent)
            my @updates;
            my @params;

            foreach my $field (qw(description agent_id target_id scheme port
                                  uri_path uri_query http_method body_encoding body
                                  send_string receive_string receive_regex disable_string
                                  disable_regex expected_status follow_redirects verify_tls
                                  timeout auth_type auth_header_name auth_credential_id is_active)) {
                if (exists $data->{$field}) {
                    push @updates, "$field = ?";
                    push @params, $data->{$field};
                }
            }
            if (exists $data->{http_headers}) {
                # The column is json but the bound value is the encoded
                # string validate_service_data prepared (or null to clear).
                push @updates, "http_headers = ?";
                push @params, $data->{http_headers_json};
            }

            if (@updates) {
                push @params, $id;
                my $sql = "UPDATE services SET " . join(", ", @updates) . " WHERE id = ?";
                my $rows = $dbh->do($sql, undef, @params);
            }

            $dbh->disconnect;

            return $c->render(json => {
                status => 'success',
                message => 'Service updated successfully',
                id => $id
            });
        } catch {
            $dbh->disconnect if $dbh;

            if ($_ =~ /Duplicate entry.*for key 'services_uniqueness'/) {
                return $c->render(json => {
                    status => 'error',
                    message => 'Service with these parameters already exists'
                }, status => 400);
            }

            return $c->render(json => {
                status => 'error',
                message => "Failed to update service: $_"
            }, status => 500);
        };
    };

    # @summary Delete service
    # @description Removes a service check. Deleting the target or the agent
    # cascades here too, so this route only handles the explicit case.
    # Admin-only.
    # @tags Services
    # @security bearerAuth
    main::del '/service/:id' => sub {
        my $c = shift;

        my $is_admin = $c->stash('jwt_payload') && $c->stash('jwt_payload')->{is_admin};
        if (!$is_admin) {
            return $c->render(json => {
                status => 'error',
                message => 'Admin required'
            }, status => 403);
        }

        my $id = $c->param('id');
        my $dbh;

        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            # Check if service exists
            my $exists = $dbh->selectrow_array(
                "SELECT 1 FROM services WHERE id = ?",
                undef, $id
            );

            unless ($exists) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Service not found'
                }, status => 404);
            }

            my $rows = $dbh->do("DELETE FROM services WHERE id = ?", undef, $id);
            $dbh->disconnect;

            # The rrd is the history (3.4), so it goes with the row -
            # otherwise a deleted service leaves a file behind that
            # nothing will ever write or read again, same as the monitor
            # side's target-delete cleanup. A failed unlink is a warning,
            # not an error: the row is already gone.
            my $rrd_file = "/var/rrd/service-$id.rrd";
            if (-e $rrd_file) {
                unlink $rrd_file or $c->app->log->warn("Could not delete RRD file $rrd_file: $!");
            }

            return $c->render(json => {
                status => 'success',
                message => 'Service deleted successfully',
                id => $id
            });
        } catch {
            $dbh->disconnect if $dbh;
            return $c->render(json => {
                status => 'error',
                message => "Failed to delete service: $_"
            }, status => 500);
        };
    };

    # @summary Reset service counters
    # @description Clears the rolled-up state (total_down and the current
    # status columns) on a service. last_check is nulled on purpose: the
    # due-gate hands out items by last_check age (6.1), so nulling it puts
    # the check back in rotation immediately. Admin-only.
    # @tags Services
    # @security bearerAuth
    main::post '/service/:id/reset' => sub {
        my $c = shift;

        my $is_admin = $c->stash('jwt_payload') && $c->stash('jwt_payload')->{is_admin};
        if (!$is_admin) {
            return $c->render(json => {
                status => 'error',
                message => 'Admin required'
            }, status => 403);
        }

        my $id = $c->param('id');
        my $dbh;

        try {
            $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

            # Check if service exists
            my $exists = $dbh->selectrow_array(
                "SELECT 1 FROM services WHERE id = ?",
                undef, $id
            );

            unless ($exists) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Service not found'
                }, status => 404);
            }

            my $sql = q{
                UPDATE services
                SET last_state = 'UNKNOWN',
                    last_status_code = 0,
                    last_reason = NULL,
                    last_message = NULL,
                    last_check = NULL,
                    last_change = NOW(),
                    total_down = 0
                WHERE id = ?
            };

            my $rows = $dbh->do($sql, undef, $id);
            $dbh->disconnect;

            return $c->render(json => {
                status => 'success',
                message => 'Service statistics reset successfully',
                id => $id
            });
        } catch {
            $dbh->disconnect if $dbh;
            return $c->render(json => {
                status => 'error',
                message => "Failed to reset service statistics: $_"
            }, status => 500);
        };
    };
}

1;