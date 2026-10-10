=head1 NAME

agent_services - the agent-facing service-polling endpoints (agent password auth)

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, OUTSIDE the JWT group
    use agent_services qw(register_agent_services);
    register_agent_services($db_config);

=head1 DESCRIPTION

The service-monitor half of the agent contract (spec 6.1/6.2/5.4/3.4).
A separate module on purpose: the old un-upgraded agent polls only
/agent/:id/monitors, so it can never be handed a service check, and
agent_monitors.pm stays response-identical (I1 amended: its only
permitted divergence is the additive, envelope-derived,
set-only-when-present version clause in _validate_agent - the only way
a 0.1.0 monitor-only agent can ever report a version, since that
endpoint is the only one it contacts). Version skew is a
property of the protocol - the endpoint either exists or 404s - not of
a shared code path that has to keep proving itself.

The GET hands over the active services that are due: is_active on the
service AND its target, last_check NULL or older than pollinterval
(services default to 300 s). The due-gate is server-side, same as the
monitor gate - a service that is not due is simply never offered, so
the agent stays a dumb poller with no scheduling opinions of its own.

Each entry carries the full URI (host resolved from the joined targets
row, never copied onto the service row - I8) plus the F5 assertion
triple and the request/TLS/auth config the probe needs. Secrets ride
the channel only at delivery (5.4): a service stores a reference,
auth_credential_id, and the GET resolves it against the credentials
table. A missing, inactive or expired credential is refused - no secret
in the payload, entry marked auth_error - because a credential the
operator pulled must not end up probing unauthenticated; the agent
reports the check DOWN with reason=auth_error and the UI can point at
the lapsed credential.

The POST files a batch of results. id/loss/median are required (same
400 style as monitors); unknown extra fields are ignored, not rejected,
so a newer agent against an older portal degrades instead of failing
(I6). Every statement is scoped to services owned by the authenticated
agent. A result updates last_state, last_status_code, last_reason,
last_message, last_check=NOW() and total_down (a 100%-loss poll counts,
DISABLED included), stamps last_change only when the state actually
changed, and writes the poll into /var/rrd/service-{id}.rrd: the
monitors' loss/rtt layout plus DS:status:GAUGE holding the real HTTP
code, or U when nothing answered. U, never 0 - nothing actually sent 0,
so a zero would read as a code that never happened - and RRA:LAST only,
because averaging status codes is meaningless (3.4).

Auth is the monitor endpoints': constant-time compare against the
stored agents.password, address refreshed from the first valid
X-Forwarded-For hop or the direct peer. The address/_is_valid_ip logic
is duplicated here deliberately (6.1). The one shared piece is the
version grammar: agent_checkin.pm parses "NetPing-Agent/<version>" from
the User-Agent for both modules' _validate_agent, which records it
(set-only-when-present, never clearing) on every authenticated
check-in, GET or POST - a protocol constant must not drift along the
monitor/services line (contract D5). Capability is derived, not
declared: any authenticated contact with this module proves the agent
speaks the services half and sets supports_services=1. The former
POST-body declaration writer is retired; body-declared keys are
accepted-and-ignored by design (one mechanism, not two - see the POST
handler).

=head1 SEE ALSO

cgi-bin/api (dispatcher wiring), cgi-bin/agent_monitors.pm,
cgi-bin/services.pm, api-docs/db_schema.md, api-docs/openapi.yaml.

=cut

package agent_services;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use File::Path qw(make_path);
use RRDs;
use Socket qw(AF_INET AF_INET6 inet_pton);
use Mojo::JSON qw(from_json);
use Mojo::Util qw(secure_compare);
use agent_checkin qw(parse_agent_ua_version);

# Routes register into the main app as `main::get '/agent/:id/services'
# => sub { ... }`. Forward-declared here so `perl -c` of this file alone
# can parse that LIST syntax; the real definitions come from
# Mojolicious::Lite when the dispatcher loads.
sub main::get;
sub main::post;

our @EXPORT_OK = qw(register_agent_services ensure_agent_capability_columns);

# Services default to a 300 s poll - monitors keep their 60. The RRD
# step is derived from it at POST time.
my $DEFAULT_POLLINTERVAL = 300;

# The two agents columns this feature owns (3.3). Guarded through
# information_schema, not MariaDB's ADD COLUMN IF NOT EXISTS, so the
# step also survives a MySQL port. Exported so tests can run it alone.
sub ensure_agent_capability_columns {
    my ($db_config) = @_;
    my $dbh = DBI->connect(
        $db_config->{dsn}, $db_config->{username}, $db_config->{password},
        { RaiseError => 1, AutoCommit => 1 }
    );
    my $present = $dbh->selectall_hashref(q{
        SELECT COLUMN_NAME
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'agents'
           AND COLUMN_NAME IN ('supports_services', 'agent_version')
    }, 'COLUMN_NAME');
    my @needed;
    push @needed, 'supports_services tinyint(1) NOT NULL DEFAULT 0'
        unless $present->{supports_services};
    push @needed, 'agent_version varchar(32) DEFAULT NULL'
        unless $present->{agent_version};
    if (@needed) {
        $dbh->do('ALTER TABLE agents ADD COLUMN ' . join(', ADD COLUMN ', @needed));
    }
    $dbh->disconnect;
    return scalar @needed;
}

sub register_agent_services {
    my ($db_config) = @_;

    my $datadir = '/var/rrd';

    # Schema step at registration, since the columns belong to this
    # module. A failed ensure must not sink the dispatcher: the monitor
    # endpoints have to keep serving untouched agents (I1/I2).
    eval { ensure_agent_capability_columns($db_config) };
    if (my $err = $@) {
        print STDERR "agent_services: capability columns ensure failed: $err\n";
    }

    # Utility: agent login + address refresh. Duplicated from
    # agent_monitors.pm on purpose (6.1): hoisting it into a shared
    # module edits the monitor live path, which I1 forbids.
    sub _validate_agent {
        my ($c, $agent_id, $password) = @_;
        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, {RaiseError=>1,AutoCommit=>1});
        my $agent = $dbh->selectrow_hashref("SELECT id, password FROM agents WHERE id=? OR name=?", undef, $agent_id, $agent_id);
        unless ($agent) {
            $dbh->disconnect;
            $c->render(json => {status=>'error', message=>'Invalid agent credentials'}, status=>401);
            return;
        }
        # Constant-time comparison so response timing cannot be used to
        # recover the stored agent password byte by byte.
        unless (defined $agent->{password} && defined $password
            && secure_compare($agent->{password}, $password)) {
            $dbh->disconnect;
            $c->render(json => {status=>'error', message=>'Invalid agent credentials'}, status=>401);
            return;
        }
        # Update agent address: only the first X-Forwarded-For hop (or the
        # direct peer address when the header is absent or invalid), and
        # only if it validates as IPv4/IPv6 before it is stored. With no
        # valid address, only last_seen is refreshed.
        my $xff = $c->req->headers->header('X-Forwarded-For') // '';
        my ($ip) = split /\s*,\s*/, $xff, 2;
        $ip = '' unless defined $ip;
        $ip =~ s/^\s+|\s+$//g;
        $ip = $c->tx->remote_address // '' unless _is_valid_ip($ip);
        $ip = ''                           unless _is_valid_ip($ip);
        # The version also rides the User-Agent of EVERY authenticated
        # check-in (agent_checkin.pm) - fold the optional clause into the
        # same self-announcement UPDATE, one statement, no second failure
        # path. Set-only-when-present: an absent or unparsable header
        # writes NOTHING for version, so a stored value is refreshed,
        # never cleared, exactly like the address rule above. No request
        # body is read here (the GET-body and POST-body declaration
        # writers are gone by design).
        my $ua_version = parse_agent_ua_version($c->req->headers->user_agent);
        # Capability is derived from the contact itself (contract D3):
        # successfully authenticating against the services module is the
        # proof that the agent speaks the services half - no declaration
        # can add information beyond the successful call. Unconditional on
        # every authenticated exchange here, GET or POST, active or not
        # (an inactive agent gets a 200 with an empty list and is still,
        # accurately, capable); rows already at 1 do not move.
        # One UPDATE:
        #   SET last_seen=NOW() [, address=?] [, agent_version=?]
        #       , supports_services=1
        my @sets = ('last_seen=NOW()', 'supports_services=1');
        my @vals;
        if ($ip ne '') {
            push @sets, 'address=?';
            push @vals, $ip;
        }
        if (defined $ua_version) {
            push @sets, 'agent_version=?';
            push @vals, $ua_version;    # already truncated to 32 (varchar(32))
        }
        $dbh->do('UPDATE agents SET ' . join(', ', @sets) . ' WHERE id=?',
            undef, @vals, $agent->{id});
        $dbh->disconnect;
        return $agent->{id};
    }

    # Utility: strict IPv4/IPv6 validation for stored agent addresses
    sub _is_valid_ip {
        my ($ip) = @_;
        return 0 unless defined $ip && length $ip;
        return 1 if inet_pton(AF_INET, $ip);
        return 1 if inet_pton(AF_INET6, $ip);
        return 0;
    }

    # @summary Get agent's service assignments
    # @description Returns a list of ACTIVE HTTP(S) service checks assigned
    # to the specified agent that are due for polling (active service,
    # active target, last_check NULL or older than pollinterval). Each
    # entry carries the full URI plus the assertion config, and the auth
    # secret resolved from the credentials table at delivery time; a
    # missing, inactive or expired credential is refused (entry marked
    # auth_error, no secret in the payload).
    # @tags Agent Services
    main::get '/agent/:id/services' => sub {
        my $c = shift;
        # A probe that sends no/odd body still gets a clean 401 instead
        # of a 500; the password defaults off.
        my $request_body = $c->req->json;
        $request_body = {} unless ref($request_body) eq 'HASH';
        my $password = $request_body->{password} // '';
        my $agent_id = $c->param('id');
        # Authenticate and update address
        my $db_id = _validate_agent($c, $agent_id, $password) or return;

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, { RaiseError=>1, AutoCommit=>1 });

        # GET stays safe: nothing from the request body is written here -
        # not now, not ever. The old GET-body capability write was
        # reverted once and stays dead. The only agents writes on this
        # poll are _validate_agent's self-announcement (address/last_seen,
        # agent_version from the User-Agent, supports_services derived
        # from this authenticated contact itself): envelope facts of the
        # check-in, the agent announcing itself, not user-supplied data.

        # Check agent is active
        my ($agent_active) = $dbh->selectrow_array("SELECT is_active FROM agents WHERE id = ?", undef, $db_id);
        unless ($agent_active) {
            $dbh->disconnect;
            return $c->render(json => {status=>'success', services=>[]});
        }

        # Same server-side due-gate as monitors, with last_check playing
        # last_update's role (6.1): whatever is not due is never offered,
        # so the agent carries no scheduling logic.
        my $sql = q{
            SELECT
                s.id,
                t.address,
                s.scheme,
                s.port,
                s.uri_path,
                s.uri_query,
                s.http_method,
                s.http_headers,
                s.body_encoding,
                s.body,
                s.send_string,
                s.receive_string,
                s.receive_regex,
                s.disable_string,
                s.disable_regex,
                s.expected_status,
                s.follow_redirects,
                s.verify_tls,
                s.timeout,
                s.auth_type,
                s.auth_header_name,
                s.auth_credential_id
            FROM services s
            JOIN targets t ON t.id = s.target_id
            WHERE s.agent_id = ?
                AND s.is_active = 1
                AND t.is_active = 1
                AND
                    (
                    s.last_check IS NULL
                    OR UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(s.last_check) >= s.pollinterval
                    )
        };

        my $sth = $dbh->prepare($sql);
        $sth->execute($db_id);
        my @services;
        while (my $s = $sth->fetchrow_hashref) {
            # Ensure default values are set
            $s->{scheme}           //= 'http';
            $s->{port}             //= 0;
            $s->{uri_path}         //= '/';
            $s->{uri_query}        //= '';
            $s->{http_method}      //= 'GET';
            $s->{receive_regex}    //= 0;
            $s->{disable_regex}    //= 0;
            $s->{follow_redirects} //= 0;
            $s->{verify_tls}       //= 1;
            $s->{timeout}          //= 10;

            # DBI returns the JSON column as encoded text; decode it once
            # here so every agent does not have to. Text that does not
            # decode to a structure goes through unchanged, not dropped.
            if (defined $s->{http_headers}) {
                my $decoded = eval { from_json($s->{http_headers}) };
                $s->{http_headers} = $decoded if ref $decoded;
            }

            # 5.4: resolve, don't store. An inactive or expired credential
            # is NOT shipped - the operator pulled it, so probing with it
            # (or silently without it) would mask the real problem; the
            # marker is what makes the check fail legibly as auth_error.
            # The secret never reaches a log.
            my $auth_type = $s->{auth_type} // '';
            if ($auth_type ne '' && $auth_type ne 'none') {
                my $cred;
                if (defined $s->{auth_credential_id} && length $s->{auth_credential_id}) {
                    $cred = $dbh->selectrow_hashref(q{
                        SELECT username, password, is_active,
                               (expiry_date IS NOT NULL AND expiry_date <= NOW()) AS expired
                          FROM credentials
                         WHERE id = ?
                    }, undef, $s->{auth_credential_id});
                }
                if ($cred && $cred->{is_active} && !$cred->{expired} && defined $cred->{password}) {
                    $s->{auth_username} = defined $cred->{username} ? $cred->{username} : '';
                    $s->{auth_secret}   = $cred->{password};
                }
                else {
                    unless ($cred) {
                        $c->app->log->warn("Service $s->{id}: auth_type=$auth_type has no usable credential; refusing secret (auth_error)");
                    }
                    $s->{auth_error} = 1;
                }
            }
            # The reference id ships to nobody: the agent has no
            # credentials API, so a dangling id would just tempt misuse.
            # Resolved pair or marker - that is the whole contract.
            delete $s->{auth_credential_id};

            push @services, $s;
        }
        $dbh->disconnect;
        $c->render(json => {status=>'success', services=>\@services});
    };

    # @summary Submit service results
    # @description Accepts a batch of HTTP check results from an agent and
    # updates each service's rolled-up state and its service RRD. Base
    # fields id/loss/median are required; unknown extra result fields are
    # ignored for forward compatibility. Results are only filed against
    # services that belong to the authenticated agent. The agent's version
    # is read from the User-Agent header of the authenticated check-in and
    # supports_services is derived from the contact itself; any
    # body-declared capability keys are ignored by design.
    # @tags Agent Services
    main::post '/agent/:id/services' => sub {
        my $c = shift;
        my $request_body = $c->req->json;
        $request_body = {} unless ref($request_body) eq 'HASH';
        my $password  = $request_body->{password} // '';
        my $agent_id  = $c->param('id');
        my $results      = $request_body->{results} || [];
        $results        = [] unless ref($results) eq 'ARRAY';
        my $db_id = _validate_agent($c, $agent_id, $password) or return;

        my $db = $c->app->defaults->{db};
        my $dbh = DBI->connect($db->{dsn}, $db->{username}, $db->{password}, {RaiseError=>1,AutoCommit=>1});

        # Body-declared capability is IGNORED by design (contract D2): the
        # User-Agent header is the one declaration mechanism. The version
        # is recorded by _validate_agent on every authenticated check-in,
        # supports_services is derived there; a second writer on the body
        # would mean two contracts for one fact. A body field is
        # caller-composed data the request can steer to any value; the
        # User-Agent is set once at LWP construction from $VERSION and
        # carried identically on every call the agent ever makes. The
        # version/supports_services keys deployed 0.2.0 agents still send
        # are accepted-and-ignored like any other unknown-extra body field
        # (I6), so no agent release is needed.

        # Ensure RRD directory exists
        unless (-d $datadir) {
            print STDERR "Creating RRD directory: $datadir\n";
            make_path($datadir);
        }

        foreach my $r (@$results) {
            # 6.4: base fields required, everything else accepted-and-
            # ignored - rejecting an unknown field would break every
            # payload a newer agent sends (I6).
            unless (defined $r->{id} && defined $r->{loss} && defined $r->{median}) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => 'Missing required fields (id, loss, median)'
                }, status => 400);
            }

            # Service config + rolled-up counters. The agent_id clause is
            # the scope: another agent's id fails exactly like a bogus
            # one, so results can never be filed against someone else's
            # service.
            my $svc = $dbh->selectrow_hashref(
                "SELECT id, pollinterval, last_state, total_down FROM services WHERE id=? AND agent_id=?",
                undef,
                $r->{id}, $db_id
            );

            unless ($svc) {
                $dbh->disconnect;
                return $c->render(json => {
                    status => 'error',
                    message => "Invalid service ID: $r->{id}"
                }, status => 400);
            }

            my $step = $svc->{pollinterval} // $DEFAULT_POLLINTERVAL;

            # Posted state wins when legal; otherwise derive from loss so
            # a laconic result still files coherently. DISABLED is never
            # guessed - it only means something when the agent says it.
            my $state = defined $r->{state} ? uc($r->{state}) : '';
            $state = '' unless $state eq 'UP' || $state eq 'DOWN' || $state eq 'DISABLED';
            $state = $r->{loss} == 100 ? 'DOWN' : 'UP' unless $state;

            # last_change is stamped only when the state actually changed
            my $prev_state      = $svc->{last_state} // 'UNKNOWN';
            my $set_last_change = $state ne $prev_state ? "last_change = NOW()," : "";

            # Downtime tracking - DISABLED carries loss 100 too, so it
            # counts as a down poll like any other.
            my $total_down = $svc->{total_down} || 0;
            $total_down++ if $r->{loss} == 100;

            # HTTP detail is optional and never trusted blindly: a
            # plausible 100..599 is recorded, anything else is "no code",
            # stored as 0.
            my $status_code = (defined $r->{status_code} && $r->{status_code} =~ /^\d+$/)
                ? (0 + $r->{status_code}) : 0;
            $status_code = 0 if $status_code < 100 || $status_code > 599;

            # Cap to the column sizes: an out-of-spec blob is filed
            # truncated, not turned into a 500 for the whole batch.
            my $reason  = defined $r->{reason}  ? substr($r->{reason},  0, 64)  : undef;
            my $message = defined $r->{message} ? substr($r->{message}, 0, 255) : undef;

            my $sql = qq{
                UPDATE services SET
                    last_state       = ?,
                    last_status_code = ?,
                    last_reason      = ?,
                    last_message     = ?,
                    last_check       = NOW(),
                    $set_last_change
                    total_down       = ?
                WHERE id = ? AND agent_id = ?
            };
            $sql =~ s/,\s+,/,/g;
            $sql =~ s/,$//g;

            $dbh->do($sql, undef,
                $state, $status_code, $reason, $message,
                $total_down, $svc->{id}, $db_id
            );

            # RRD handling (3.4): the filename uses the id as stored in
            # the DB (canonical form), never the raw posted value - a
            # forged id must not steer writes into another service's
            # file. rtt is U when down; status is the real code or U when
            # nothing was heard - 0 would read as a code that never
            # happened.
            my $rrdfile = "$datadir/service-$svc->{id}.rrd";

            my $is_down   = ($r->{loss} == 100 && $r->{median} == 0);
            my $rtt_value = $is_down ? 'U' : $r->{median};
            my $rrd_status = $status_code ? $status_code : 'U';

            print STDERR "RRD: attempt create/update $rrdfile (loss=$r->{loss}, rtt=$rtt_value, status=$rrd_status)\n";

            unless (-e $rrdfile) {
                print STDERR "RRD: creating $rrdfile with step $step\n";
                RRDs::create(
                    $rrdfile,
                    '--step', $step,
                    'DS:loss:GAUGE:'.($step*3).':0:100',
                    'DS:rtt:GAUGE:'.($step*3).':0:U',
                    'DS:status:GAUGE:'.($step*3).':0:599',
                    # LAST-only archive on purpose (3.4): averaging status
                    # codes is meaningless, so nothing here ever folds it.
                    'RRA:LAST:0.5:1:525600'
                );
                my $ERR = RRDs::error;
                if ($ERR) {
                    $c->app->log->error("RRD create $rrdfile: $ERR");
                    print STDERR "RRD error: $ERR\n";
                }
            }

            my $now = time();
            RRDs::update($rrdfile, '--template', 'loss:rtt:status', "$now:$r->{loss}:$rtt_value:$rrd_status");
            my $ERR = RRDs::error;
            if ($ERR) {
                $c->app->log->error("RRD update $rrdfile: $ERR");
                print STDERR "RRD error: $ERR\n";
            }
        }

        $dbh->disconnect;
        $c->render(json => {status=>'success'});
    };
}

1;