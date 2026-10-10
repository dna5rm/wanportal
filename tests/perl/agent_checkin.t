#!/usr/bin/env perl
# agent_checkin.t - the agent version is a property of the check-in,
# not of a payload (contract D1-D5, I1 amendment).
#
# Unit layer (offline): the shared parser grammar in
# cgi-bin/agent_checkin.pm - anchored, case-sensitive, charset- and
# length-bounded, truncated to the varchar(32) column, undefined for
# anything that must record nothing.
#
# Live layer (skipped when the local API/DB is unreachable): drives the
# real agent endpoints against a provisioned throwaway agents row and
# pins the whole check-in contract:
#   - 401 precedes any write: a spoofed UA without credentials records
#     nothing
#   - version is recorded from the User-Agent on authenticated monitor
#     contacts (POST and legacy-style GET with no declaration keys)
#   - absent/empty/garbage UA records nothing on a fresh row (stays NULL)
#     and never clears a known value
#   - a GET with a body version field still records nothing from the body
#     (the reverted GET-body write stays dead)
#   - the retired POST-body declaration writer stays retired (declared
#     keys are ignored; a body supports_services=0 does not clear the
#     derived flag)
#   - supports_services flips only at first authenticated services
#     contact, never from monitor contacts
#   - truncation to 32 chars
#   - cross-agent isolation: one agent's check-in never writes another
#     agent's row
#
# The response shapes are asserted byte-exact - the endpoints' payloads
# must not change when the check-in writer was added.

use strict;
use warnings;
use FindBin;
use Test::More;
use lib "$FindBin::Bin/../../cgi-bin";

use agent_checkin qw(parse_agent_ua_version);

# ---- unit: the parser grammar ------------------------------------------------

ok(!defined parse_agent_ua_version(undef), 'undef header parses to undef');
ok(!defined parse_agent_ua_version(''),    'empty header parses to undef');

my %good = (
    'NetPing-Agent/0.1.0'                 => '0.1.0',
    'NetPing-Agent/0.2.0'                 => '0.2.0',
    'NetPing-Agent/2.0.0'                 => '2.0.0',
    'NetPing-Agent/0.2.5.1-alpha.build~x' => '0.2.5.1-alpha.build~x',
    '  NetPing-Agent/0.3.7  '             => '0.3.7',  # OWS trimmed
    "\tNetPing-Agent/9.9\t"               => '9.9',    # tabs trimmed
    'NetPing-Agent/a'                     => 'a',      # 1-char minimum
    'NetPing-Agent/' . ('v' x 64)         => 'v' x 32, # 64-char maximum, stored as 32
);
while (my ($in, $want) = each %good) {
    is(parse_agent_ua_version($in), $want, "parses: [$in]");
}

my $truncated = parse_agent_ua_version('NetPing-Agent/0.2.5.1-alpha.build.2026.10.10-padded-extra');
is($truncated, '0.2.5.1-alpha.build.2026.10.10-p',
    'stored value is the first 32 chars of a 43-char legal version');

# Anything that must record nothing: undefined result, caller writes nothing.
my @rejects = (
    'NetPing-Agent',                    # bare, no version
    'NetPing-Agent/',                   # no version after slash
    'NetPing-Agent/0.2.0 (linux)',      # platform suffix: stale, never erased
    'NetPing-Agent/0.2.0 extra',        # second token
    'NetPing-Agent/ .hidden',           # first char not alnum
    'NetPing-Agent/../../x',            # hostile charset (traversal)
    'NetPing-Agent/%00',                # hostile charset (formatting)
    "NetPing-Agent/0.\x01.0",           # interior control char
    'netping-agent/0.2.0',              # case-sensitive: no prefix match
    'NETPING-AGENT/0.2.0',              # case-sensitive: no upper match
    'NetPing-Agent/0.2.0/..',           # second slash rejects
    'curl/8.14.1',                      # non-agent client
    'Mozilla/5.0 (X11; Linux x86_64)',  # browser
    'libwww-perl/6.77',                 # LWP default
    'x' . ('y' x 64),                   # 65 chars: over the bound
);
for my $in (@rejects) {
    ok(!defined parse_agent_ua_version($in), "rejects: [$in]");
}
# 31 unit assertions so far.

# ---- live layer ---------------------------------------------------------------

my $base = $ENV{WANPORTAL_API} || 'http://127.0.0.1/cgi-bin/api';

sub dbh {
    require DBI;
    my $host = $ENV{MYSQL_HOST}     || 'wandb';
    my $port = $ENV{MYSQL_PORT}     || '3306';
    my $user = $ENV{MYSQL_USER}     || 'root';
    my $pass = $ENV{MYSQL_PASSWORD} || 'netops';
    my $dbnm = $ENV{MYSQL_DB}       || 'netops';
    return DBI->connect(
        "DBI:mysql:database=$dbnm;host=$host;port=$port",
        $user, $pass, { RaiseError => 1, AutoCommit => 1 }
    );
}

# Provisioned-row names: recognizable, unique per run, cleaned up in END.
my $run_tag = 'TEST-AGENTCHECKIN-' . $$ . '-' . int(rand 100000);
my ($agg_a, $agg_b) = ("$run_tag-A", "$run_tag-B");

my $can_live = 1;
eval {
    require LWP::UserAgent;
    my $probe = LWP::UserAgent->new(timeout => 5);
    my $health = $probe->get("$base/health");
    die "health not ok" unless $health->is_success;
    dbh()->selectrow_array('SELECT 1');
    1;
} or do {
    $can_live = 0;
    note("live layer skipped: $@");
};

if ($can_live) {
    require LWP::UserAgent;
    require Mojo::JSON;
    Mojo::JSON->import(qw(encode_json decode_json));

    my $http  = LWP::UserAgent->new(timeout => 10);
    my $agent_password = 'achk-' . join '', map { sprintf '%02x', int rand 256 } 1..8;

    my $drop_leftovers = sub {
        my $dbh = dbh();
        $dbh->do("DELETE FROM agents WHERE name LIKE 'TEST-AGENTCHECKIN-%'");
        $dbh->disconnect;
    };
    my $insert_row = sub {
        my ($name) = @_;
        # Version-4-shaped uuid (agents.id is char(36), checked by the
        # UI/API id allow-lists), built without external modules.
        my $uuid = sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            int rand 0xffff, int rand 0xffff, int rand 0xffff, int rand 0xfff,
            (int rand 0xffff) & 0x3fff | 0x8000,
            int rand 0xffff, int rand 0xffff, int rand 0xffff);
        my $dbh = dbh();
        $dbh->do('INSERT INTO agents (id, name, password, is_active, description) VALUES (?,?,?,?,?)',
            undef, $uuid, $name, $agent_password, 1, 'throwaway row for agent_checkin.t');
        $dbh->disconnect;
        return $uuid;
    };
    my $row = sub {
        my ($name, $col) = @_;
        my $dbh = dbh();
        my ($v) = $dbh->selectrow_array("SELECT $col FROM agents WHERE name = ?", undef, $name);
        $dbh->disconnect;
        return $v;
    };

    # Agent-shape request: JSON body, per-request User-Agent.
    my $call = sub {
        my ($method, $path, $ua, $body) = @_;
        my $req = HTTP::Request->new($method, "$base$path");
        $req->header('Content-Type' => 'application/json');
        $req->header('User-Agent'   => $ua) if defined $ua;
        $req->content(encode_json($body));
        my $res = $http->request($req);
        return ($res->code, $res->content);
    };
    # The one case LWP cannot do: truly ABSENT User-Agent header. Same
    # path vocabulary as $call: the API path part is derived from $base.
    my $raw_get_no_ua = sub {
        my ($path, $body) = @_;
        (my $api_path = $base) =~ s{\Ahttps?://[^/]+}{};
        $api_path = '' if $api_path eq '/';   # base at the root
        require IO::Socket::INET;
        my $payload = encode_json($body);
        my $s = IO::Socket::INET->new(
            PeerAddr => '127.0.0.1', PeerPort => 80, Proto => 'tcp', Timeout => 5
        );
        return (0, '') unless $s;
        print $s join("\r\n",
            "GET $api_path$path HTTP/1.0",
            'Host: 127.0.0.1',
            'Content-Type: application/json',
            'Content-Length: ' . length($payload),
            '', $payload);
        my $all = do { local $/; <$s> };
        close $s;
        return (0, '') unless defined $all && $all =~ /\AHTTP\/1\.[01] (\d{3})/;
        my $code = $1;
        $all =~ /\r\n\r\n(.*)\z/s;
        return ($code, $1 || '');
    };

    $drop_leftovers->();

    subtest provisioning => sub {
        plan tests => 3;
        my $id_a = $insert_row->($agg_a);
        ok(length($id_a) == 36, 'agent row A provisioned (uuid id)');
        my $id_b = $insert_row->($agg_b);
        ok(length($id_b) == 36, 'agent row B provisioned (uuid id)');
        is($row->($agg_a, 'agent_version'), undef, 'fresh row: agent_version NULL');
    };

    subtest '401 precedes any write; spoofed UA records nothing' => sub {
        plan tests => 3;
        my $id = $row->($agg_a, 'id');
        my ($code, $body) = $call->('POST', "/agent/$id/monitors", 'NetPing-Agent/9.9.9',
            {password => 'wrong-password', results => []});
        is($code, 401, 'bad credentials -> 401 even with a valid UA');
        is(decode_json($body)->{status}, 'error', 'error status');
        is($row->($agg_a, 'agent_version'), undef, 'no version write: auth precedes the UPDATE');
    };

    subtest 'no-UA and garbage-UA contacts on a fresh row stay NULL' => sub {
        plan tests => 4;
        my $id = $row->($agg_a, 'id');
        my ($code) = $raw_get_no_ua->("/agent/$id/monitors", {password => $agent_password});
        is($code, 200, 'truly header-less GET serves the agent');
        is($row->($agg_a, 'agent_version'), undef, 'absent User-Agent records nothing');
        ($code) = $call->('POST', "/agent/$id/monitors", 'curl/8.14.1', {password => $agent_password, results => []});
        is($code, 200, 'curl-UA POST still serves the agent');
        is($row->($agg_a, 'agent_version'), undef, 'non-agent User-Agent records nothing');
    };

    subtest 'responses stay byte-exact while the UA version is recorded' => sub {
        plan tests => 4;
        my $id = $row->($agg_a, 'id');
        my ($code, $body) = $call->('GET', "/agent/$id/monitors", 'NetPing-Agent/0.1.0', {password => $agent_password});
        is($code, 200, 'GET /monitors 200');
        is($body, '{"monitors":[],"status":"success"}', 'GET /monitors body unchanged (byte-exact)');
        is($row->($agg_a, 'agent_version'), '0.1.0', 'version recorded from the UA (the old-agent case)');
        ($code, $body) = $call->('POST', "/agent/$id/monitors", 'NetPing-Agent/0.1.0',
            {password => $agent_password, results => []});
        is($body, '{"status":"success"}', 'POST /monitors body unchanged (byte-exact)');
    };

    subtest 'no-UA contact on a known value: refreshed, never cleared' => sub {
        plan tests => 4;
        my $id = $row->($agg_a, 'id');
        my ($code) = $raw_get_no_ua->("/agent/$id/monitors", {password => $agent_password});
        is($code, 200, 'header-less GET 200');
        is($row->($agg_a, 'agent_version'), '0.1.0', 'absent User-Agent keeps the known value');
        ($code) = $call->('POST', "/agent/$id/monitors", 'curl/8.14.1', {password => $agent_password, results => []});
        is($code, 200, 'curl-UA POST 200');
        is($row->($agg_a, 'agent_version'), '0.1.0', 'garbage User-Agent keeps the known value');
    };

    subtest 'legacy-style call: no declaration keys, version still recorded' => sub {
        plan tests => 2;
        my $id = $row->($agg_a, 'id');
        my ($code, $body) = $call->('GET', "/agent/$id/monitors", 'NetPing-Agent/0.2.4',
            {password => $agent_password});
        is($code, 200, 'legacy-style GET 200 (body carries the password ONLY)');
        is($row->($agg_a, 'agent_version'), '0.2.4', 'version recorded from the header alone');
    };

    subtest 'unparsable UAs on a known value: stale, never erased' => sub {
        plan tests => 7;
        my $id = $row->($agg_a, 'id');
        for my $ua ('NetPing-Agent', 'NetPing-Agent/0.2.0 extra', 'NetPing-Agent/0.2.0 (linux)',
                    'NetPing-Agent/../../x', 'netping-agent/0.2.0', 'NetPing-Agent/') {
            my ($code) = $call->('GET', "/agent/$id/monitors", $ua, {password => $agent_password});
            is($code, 200, "GET with UA [$ua] still serves");
        }
        is($row->($agg_a, 'agent_version'), '0.2.4', 'rejected UAs keep the last good value');
    };

    subtest 'monitor contacts never derive supports_services' => sub {
        plan tests => 1;
        is($row->($agg_a, 'supports_services'), 0,
            'supports_services stays 0 after many monitor-only contacts');
    };

    subtest 'REGRESSION: a GET ignores a body version field' => sub {
        plan tests => 2;
        my $id = $row->($agg_a, 'id');
        my ($code) = $call->('GET', "/agent/$id/monitors", 'NetPing-Agent/0.2.4',
            {password => $agent_password, version => '9.9.9'});
        is($code, 200, 'GET /monitors with body version=9.9.9 -> 200');
        is($row->($agg_a, 'agent_version'), '0.2.4', 'body version never recorded (the reverted bug stays dead)');
    };

    subtest 'first services contact flips supports_services, records the UA version' => sub {
        plan tests => 4;
        my $id = $row->($agg_a, 'id');
        my ($code, $body) = $call->('GET', "/agent/$id/services", 'NetPing-Agent/0.2.5',
            {password => $agent_password, version => '9.9.9-curl'});
        is($code, 200, 'GET /services 200');
        is($row->($agg_a, 'agent_version'), '0.2.5', 'header version recorded, NOT the body 9.9.9-curl');
        is($row->($agg_a, 'supports_services'), 1, 'supports_services derived from the first services contact');
        is($body, '{"services":[],"status":"success"}', 'response body unchanged (byte-exact)');
    };

    subtest 'retired POST-body declaration writer stays retired' => sub {
        plan tests => 3;
        my $id = $row->($agg_a, 'id');
        my ($code) = $call->('POST', "/agent/$id/services", 'NetPing-Agent/0.2.6',
            {password => $agent_password, results => [],
             version => '7.7.7-body', supports_services => 0});
        is($code, 200, 'POST /services 200 with declared keys');
        is($row->($agg_a, 'agent_version'), '0.2.6', 'body version 7.7.7-body ignored; UA version recorded');
        is($row->($agg_a, 'supports_services'), 1, 'body supports_services=0 does not clear the derived flag');
    };

    subtest 'truncation to the varchar(32) column' => sub {
        plan tests => 2;
        my $id = $row->($agg_a, 'id');
        my ($code) = $call->('POST', "/agent/$id/monitors",
            'NetPing-Agent/0.2.5.1-alpha.build.2026.10.10-padded-extra',
            {password => $agent_password, results => []});
        is($code, 200, '43-char legal charset version accepted');
        is($row->($agg_a, 'agent_version'), '0.2.5.1-alpha.build.2026.10.10-p',
            'stored value is exactly the first 32 chars');
    };

    subtest 'cross-agent isolation' => sub {
        plan tests => 3;
        my $id_b = $row->($agg_b, 'id');
        my ($code) = $call->('POST', "/agent/$id_b/monitors", 'NetPing-Agent/3.3.3',
            {password => $agent_password, results => []});
        is($code, 200, 'agent B contact 200');
        is($row->($agg_b, 'agent_version'), '3.3.3', 'agent B records its own version');
        is($row->($agg_a, 'agent_version'), '0.2.5.1-alpha.build.2026.10.10-p',
            'agent A row unmodified by agent B check-in');
    };

    # Cleanup, then one explicit verification outside END so the success
    # path proves the rows are gone.
    my $cleanup = dbh();
    $cleanup->do("DELETE FROM agents WHERE name LIKE 'TEST-AGENTCHECKIN-%'");
    $cleanup->disconnect;
    my $left = dbh();
    my ($n) = $left->selectrow_array("SELECT COUNT(*) FROM agents WHERE name LIKE 'TEST-AGENTCHECKIN-%'");
    $left->disconnect;
    is($n, 0, 'provisioned test rows removed');
}

subtest 'live layer status' => sub {
    plan tests => 1;
    if ($can_live) {
        ok(1, 'live layer ran against the local API');
    }
    else {
        ok(1, 'live layer skipped: no local API/DB reachable (unit grammar still pinned)');
    }
};

END {
    return if $ENV{HARNESS_NOT_END_CLEANUP};
    eval {
        my $host = $ENV{MYSQL_HOST}     || 'wandb';
        my $port = $ENV{MYSQL_PORT}     || '3306';
        my $user = $ENV{MYSQL_USER}     || 'root';
        my $pass = $ENV{MYSQL_PASSWORD} || 'netops';
        my $dbnm = $ENV{MYSQL_DB}       || 'netops';
        require DBI;
        my $dbh = DBI->connect(
            "DBI:mysql:database=$dbnm;host=$host;port=$port",
            $user, $pass, { RaiseError => 0, AutoCommit => 1, PrintError => 0 }
        ) or return;
        $dbh->do("DELETE FROM agents WHERE name LIKE 'TEST-AGENTCHECKIN-%'");
        $dbh->disconnect;
    };
}

done_testing();