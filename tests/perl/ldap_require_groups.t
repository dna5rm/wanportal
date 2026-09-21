#!/usr/bin/env perl
# ldap_require_groups.t - the optional LDAP group allowlist in
# cgi-bin/auth.pm (AUTH_LDAP_REQUIRE_GROUPS), without a live LDAP server.
#
# Four layers:
#   1. _ldap_config parses the pipe-separated DN list (a DN contains
#      commas, so the pipe is the separator; empty entries are dropped).
#   2. _ldap_group_filter builds the RFC 4515 any-of filter carrying the
#      AD transitive-membership rule; _ldap_group_filter_plain builds the
#      plain (memberOf=DN) variant for servers that reject extensible
#      matches (LLDAP answers code 53). Both run every DN through
#      _ldap_filter_escape (raw env text never reaches a filter).
#   3. _ldap_authenticate runs the gate after the user bind: nested rule
#      first on the service-bind connection; on ANY nonzero code one
#      retry with the plain filter on the SAME connection. Denial with
#      'Invalid LDAP credentials' when both searches fail or the
#      successful search matches nothing; no extra search at all when
#      the allowlist is empty.
#   4. Wiring and POD: the env key is parsed into cfg require_groups and
#      the fallback is documented.
#
# Subs are extracted from the live source by brace balancing and eval'd
# standalone (same approach as ldap_escape.t); Net::LDAP is faked.
#
# Run: docker exec wanportal prove -l /srv/tests/perl/ldap_require_groups.t

use strict;
use warnings;
use FindBin;
use Test::More;

my $auth_pm = "$FindBin::Bin/../../cgi-bin/auth.pm";

my $src = do {
    open my $fh, '<', $auth_pm or die "cannot read $auth_pm: $!\n";
    local $/; <$fh>
};

# Brace-balanced extraction of a named sub (the subs pulled here contain no
# unbalanced braces in strings or regexes).
sub extract_sub {
    my ($name) = @_;
    my $start = index($src, "sub $name");
    return undef if $start < 0;
    my $open = index($src, '{', $start);
    return undef if $open < 0;
    my $depth = 0;
    for my $i ($open .. length($src) - 1) {
        my $ch = substr($src, $i, 1);
        $depth++ if $ch eq '{';
        $depth-- if $ch eq '}';
        return substr($src, $start, $i - $start + 1) if $depth == 0;
    }
    return undef;
}

my $escape_body  = extract_sub('_ldap_filter_escape');
my $config_body  = extract_sub('_ldap_config');
my $filter_body  = extract_sub('_ldap_group_filter');
my $plain_body   = extract_sub('_ldap_group_filter_plain');
my $connect_body = extract_sub('_ldap_connect');
my $auth_body    = extract_sub('_ldap_authenticate');

if (!$escape_body || !$config_body || !$filter_body || !$plain_body
    || !$connect_body || !$auth_body) {
    plan skip_all =>
        'one of _ldap_filter_escape/_ldap_config/_ldap_group_filter/'
      . '_ldap_group_filter_plain/_ldap_connect/_ldap_authenticate not found in auth.pm; update this test';
    exit 0;
}

# The filter subs call the escape sub; compile the helpers together. All of
# them use lexicals and %ENV only, so they compile under this file's strict.
my $helpers_ok = eval "$escape_body\n$config_body\n$filter_body\n$plain_body\n$connect_body\n1";
plan skip_all => "extracted auth.pm helpers do not compile: $@" if !$helpers_ok;

# _ldap_authenticate references auth.pm's file-level $LDAP_AVAILABLE flag;
# declare it (true) so the extracted sub compiles and runs.
my $auth_ok = eval "no strict 'vars';\nour \$LDAP_AVAILABLE = 1;\n$auth_body\n1";
plan skip_all => "extracted _ldap_authenticate does not compile: $@" if !$auth_ok;

# ---- fake Net::LDAP ---------------------------------------------------------
# The real module is never loaded in this process. The fake records every
# ->search so the scenarios can assert base/scope/filter and which
# connection was used. Base-scope (group gate) searches pop the next preset
# result off @GROUP_RESULTS; once the queue runs dry the last preset repeats,
# which models a server answering identically every time.

our @SEARCHES;           # every ->search call: { conn, base, scope, filter }
our @GROUP_RESULTS;      # queued { code, count } presets for base-scope searches
our $LAST_GROUP_RESULT;  # reused when the queue is empty
our $USER_DN        = 'CN=Test User,OU=Users,DC=example,DC=com';
our $CONN_N         = 0;   # fake connection counter

{
    package FakeEntry;
    sub dn        { return $main::USER_DN; }
    sub get_value { my ($self, $attr) = @_; return $attr eq 'cn' ? 'Test User' : undef; }
}

{
    package FakeUserSearch;   # result of the scope=sub user lookup
    sub code  { return 0 }
    sub count { return 1 }
    sub entry { return bless {}, 'FakeEntry' }
}

{
    package FakeGroupSearch;  # result of a scope=base group check
    sub code  { my ($s) = @_; return $s->{res}{code} }
    sub count { my ($s) = @_; return $s->{res}{count} }
}

{
    package FakeMesg;         # bind/unbind result
    sub code { return 0 }
}

{
    package Net::LDAP;
    sub new {
        my ($class) = @_;
        my $self = bless {}, $class;
        $self->{conn} = ++$main::CONN_N;
        return $self;
    }
    sub bind   { return bless {}, 'FakeMesg' }
    sub set_option { return 1 }
    sub search {
        my ($self, %args) = @_;
        push @main::SEARCHES,
            { conn => $self->{conn}, base => $args{base},
              scope => $args{scope}, filter => $args{filter} };
        if (($args{scope} // '') eq 'base') {
            my $res = @main::GROUP_RESULTS
                ? shift @main::GROUP_RESULTS
                : ($main::LAST_GROUP_RESULT // { code => 0, count => 0 });
            $main::LAST_GROUP_RESULT = $res;
            return bless { res => $res }, 'FakeGroupSearch';
        }
        return bless {}, 'FakeUserSearch';
    }
    sub unbind { return bless {}, 'FakeMesg' }
}

# ---- helpers ----------------------------------------------------------------

# Config for the gate scenarios, independent of the container's live env.
sub gate_cfg {
    my ($groups) = @_;
    return {
        enabled        => 1,
        server_uri     => 'ldap://ldap.example.invalid',
        bind_dn        => 'CN=svc,DC=example,DC=com',
        bind_pass      => 'placeholder-not-a-secret',
        base_dn        => 'DC=example,DC=com',
        search_attr    => 'uid',
        ignore_cert    => 1,
        require_groups => $groups,
    };
}

# Run the gate with a queue of group-search results; each successive
# base-scope search pops the next preset, and once the queue is empty the
# last preset repeats (a server answering identically every time).
sub run_gate {
    my ($groups, @results) = @_;
    @SEARCHES          = ();
    @GROUP_RESULTS     = @results;
    $LAST_GROUP_RESULT = undef;
    return [ main::_ldap_authenticate('testuser', 'secret', gate_cfg($groups)) ];
}

sub base_scope_searches {
    return grep { ($_->{scope} // '') eq 'base' } @SEARCHES;
}

sub parsed_groups {
    my ($env_value) = @_;
    if (defined $env_value) {
        local $ENV{AUTH_LDAP_REQUIRE_GROUPS} = $env_value;
        return main::_ldap_config()->{require_groups};
    }
    delete local $ENV{AUTH_LDAP_REQUIRE_GROUPS};
    return main::_ldap_config()->{require_groups};
}

plan tests => 53;

# --- 1. require_groups parsing ------------------------------------------------

is_deeply(parsed_groups(undef), [], 'unset env -> empty allowlist (gate off)');
is_deeply(parsed_groups(''),    [], 'empty env -> empty allowlist (gate off)');
is_deeply(parsed_groups('|'),   [], 'pipes only -> empty allowlist');
is_deeply(parsed_groups('||'),  [], 'empty entries are all dropped');
is_deeply(
    parsed_groups('CN=A,DC=x'),
    ['CN=A,DC=x'],
    'single DN kept verbatim (commas intact)'
);
is_deeply(
    parsed_groups('CN=A,DC=x|CN=B,DC=y'),
    ['CN=A,DC=x', 'CN=B,DC=y'],
    'pipe-separated DNs; commas inside a DN never split it'
);
is_deeply(
    parsed_groups('|CN=A,DC=x|'),
    ['CN=A,DC=x'],
    'empty entries around a DN are dropped'
);

# --- 2a. nested filter construction (the AD rule) -------------------------------

is(_ldap_group_filter([]), '', 'empty allowlist -> empty filter (gate skipped)');
is(_ldap_group_filter(undef), '', 'undef -> empty filter');

is(
    _ldap_group_filter(['CN=Netops,DC=example,DC=com']),
    '(|(memberOf:1.2.840.113556.1.4.1941:=CN=Netops,DC=example,DC=com))',
    'one DN -> one any-of branch'
);

is(
    _ldap_group_filter(['CN=A,DC=x', 'CN=B,DC=y']),
    '(|(memberOf:1.2.840.113556.1.4.1941:=CN=A,DC=x)(memberOf:1.2.840.113556.1.4.1941:=CN=B,DC=y))',
    'two DNs -> two any-of branches'
);

is(
    _ldap_group_filter(['CN=Evil*(x),DC=y']),
    '(|(memberOf:1.2.840.113556.1.4.1941:=CN=Evil\\2a\\28x\\29,DC=y))',
    'DN metacharacters are RFC 4515-escaped (injection-safe)'
);

# --- 2b. plain filter construction (LLDAP fallback) ------------------------------

is(_ldap_group_filter_plain([]),    '', 'plain: empty allowlist -> empty filter');
is(_ldap_group_filter_plain(undef), '', 'plain: undef -> empty filter');

is(
    _ldap_group_filter_plain(['cn=admins,ou=groups,dc=example,dc=com']),
    '(|(memberOf=cn=admins,ou=groups,dc=example,dc=com))',
    'plain: one DN -> plain memberOf equality'
);

is(
    _ldap_group_filter_plain(['CN=A,DC=x', 'CN=B,DC=y']),
    '(|(memberOf=CN=A,DC=x)(memberOf=CN=B,DC=y))',
    'plain: two DNs -> two plain any-of branches'
);

is(
    _ldap_group_filter_plain(['CN=Evil*(x),DC=y']),
    '(|(memberOf=CN=Evil\\2a\\28x\\29,DC=y))',
    'plain: DN metacharacters are RFC 4515-escaped (injection-safe)'
);

unlike(
    _ldap_group_filter_plain(['CN=A,DC=x']),
    qr/1\.2\.840\.113556\.1\.4\.1941/,
    'plain filter carries no matching-rule OID'
);

# --- 3. the gate inside _ldap_authenticate -------------------------------------

my $ADMIN_DN = 'cn=admins,ou=groups,dc=example,dc=com';

# allow: nested rule succeeds (AD path stays on the OID filter)
my $r = run_gate([$ADMIN_DN], { code => 0, count => 1 });
is($r->[0], 1, 'nested rule succeeds -> allow');
is($r->[1], 'Test User', 'full name still returned when the gate allows');
is(scalar(base_scope_searches()), 1, 'a successful nested search is not retried');
my ($group_search) = base_scope_searches();
is($group_search->{base}, $USER_DN, 'gate search is scoped to the authenticated DN');
is(
    $group_search->{filter},
    _ldap_group_filter([$ADMIN_DN]),
    'gate filter is the escaped nested any-of filter'
);
is(
    $group_search->{conn},
    $SEARCHES[0]{conn},
    'gate runs on the service-bind connection, not the user-bind one'
);
is($SEARCHES[0]{filter}, '(uid=testuser)', 'user search filter still built from the configured attr');

# allow, LLDAP style: nested rule rejected with 53, plain retry matches
$r = run_gate([$ADMIN_DN], { code => 53, count => 0 }, { code => 0, count => 1 });
is($r->[0], 1, 'code 53 then a matching plain search -> allow');
is($r->[1], 'Test User', 'fallback allow still carries the full name');
my @fallback = base_scope_searches();
is(scalar(@fallback), 2, 'exactly one retry: two base-scope searches total');
like($fallback[0]{filter}, qr/memberOf:1\.2\.840\.113556\.1\.4\.1941:=/,
     'first attempt uses the nested matching rule');
unlike($fallback[1]{filter}, qr/1\.2\.840\.113556\.1\.4\.1941/,
       'retry drops the matching-rule OID');
is(
    $fallback[1]{filter},
    _ldap_group_filter_plain([$ADMIN_DN]),
    'retry filter is the escaped plain any-of filter'
);
is($fallback[0]{conn}, $fallback[1]{conn}, 'retry reuses the same connection');
is($fallback[1]{conn}, $SEARCHES[0]{conn}, 'retry is on the service-bind connection');

# deny: nested rule succeeds but matches nothing (no retry on success)
$r = run_gate([$ADMIN_DN], { code => 0, count => 0 });
is($r->[0], 0, 'successful nested search with no match denies');
is($r->[1], 'Invalid LDAP credentials', 'zero-match denial reads Invalid LDAP credentials');
is(scalar(base_scope_searches()), 1, 'a successful empty nested search is not retried');

# deny: both attempts fail (server rejects the rule twice)
$r = run_gate([$ADMIN_DN], { code => 53, count => 0 }, { code => 53, count => 0 });
is($r->[0], 0, 'nested and plain searches both failing denies');
is($r->[1], 'Invalid LDAP credentials', 'both-fail denial reads Invalid LDAP credentials');
is(scalar(base_scope_searches()), 2, 'both attempts ran before the denial');

# deny: plain retry succeeds but the user is not a member
$r = run_gate([$ADMIN_DN], { code => 53, count => 0 }, { code => 0, count => 0 });
is($r->[0], 0, 'plain retry succeeding with no match denies');
is($r->[1], 'Invalid LDAP credentials', 'fallback zero-match denial reads Invalid LDAP credentials');

# allow: any nonzero code (not only 53) falls back to plain
$r = run_gate([$ADMIN_DN], { code => 2, count => 1 }, { code => 0, count => 1 });
is($r->[0], 1, 'a non-53 error also falls back to plain and allows');
my @generic = base_scope_searches();
unlike($generic[1]{filter}, qr/1\.2\.840\.113556\.1\.4\.1941/,
       'fallback after a generic error is the plain filter');

# empty allowlist: legacy behavior, no group search at all
$r = run_gate([], { code => 0, count => 0 });    # count 0 would deny if searched
is($r->[0], 1, 'empty allowlist keeps the legacy behavior (any bindable user)');
is(scalar(base_scope_searches()), 0, 'no base-scope search when the allowlist is empty');

# --- 4. wiring and POD ----------------------------------------------------------

ok(
    index($src, 'AUTH_LDAP_REQUIRE_GROUPS') < index($src, 'package auth;'),
    'POD documents AUTH_LDAP_REQUIRE_GROUPS'
);
like($src, qr/answers code 53/, 'POD documents the plain-filter fallback after code 53');
like($config_body, qr/AUTH_LDAP_REQUIRE_GROUPS/, '_ldap_config reads AUTH_LDAP_REQUIRE_GROUPS');
like($auth_body,   qr/require_groups/,           'gate reads cfg require_groups');
like($auth_body,   qr/_ldap_group_filter/,       'gate builds its nested filter via _ldap_group_filter');
like($auth_body,   qr/_ldap_group_filter_plain/, 'gate retries with the plain memberOf filter');
like($plain_body,  qr/_ldap_filter_escape/,      'plain filter escapes DNs too');
like($auth_body,   qr/Invalid LDAP credentials/, 'gate denies with Invalid LDAP credentials');