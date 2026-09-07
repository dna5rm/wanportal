#!/usr/bin/env perl
# session_route.t - GET /session echoes the caller's JWT claims.
#
# Extraction-based drift guard (same pattern as the other tests/perl
# suites): `sub register_session` is pulled out of cgi-bin/auth.pm at
# run time with brace-balanced extraction, eval'd against a stub
# main::get and a stub controller, then invoked directly. Asserts the
# route is registered on GET /session and that the handler renders
# exactly status/username/is_admin/exp straight from the stashed
# jwt_payload, with boolean claim objects passed through untouched
# (Mojo::JSON serializes JSON::PP::Boolean as true/false). No
# Mojolicious boot; a failed extraction degrades to a loud skip.
#
# Middleware enforcement (JWT required) is covered live by
# tests/validate.sh (GET /session without a bearer must answer 401)
# and the route is registered inside the JWT group in cgi-bin/api.

use strict;
use warnings;
use FindBin;
use JSON::PP ();
use Test::More;

my $root     = "$FindBin::Bin/../..";
my $src_file = "$root/cgi-bin/auth.pm";

ok(-f $src_file, 'cgi-bin/auth.pm found')
    or plan skip_all => 'cgi-bin/auth.pm missing';
my $src = do { local $/; open my $fh, '<', $src_file or die "$src_file: $!"; <$fh> };

# ---- brace-balanced extraction of `sub register_session` -------------------
my $start = index($src, 'sub register_session {');
plan skip_all => 'sub register_session not found in auth.pm (layout changed)'
    if $start < 0;
my $open = index($src, '{', $start);
my ($depth, $end) = (0, -1);
for my $i ($open .. length($src) - 1) {
    my $ch = substr($src, $i, 1);
    if    ($ch eq '{') { $depth++ }
    elsif ($ch eq '}') { $depth--; if ($depth == 0) { $end = $i; last } }
}
plan skip_all => 'brace-balanced extraction of register_session failed'
    if $end < 0;
my $sub_src = substr($src, $start, $end - $start + 1);

# ---- stub route collector + controller -------------------------------------
# The extracted source calls the fully-qualified main::get, so defining
# sub get in this (main) package captures the registration.
our @ROUTES;
sub get { my ($path, $cb) = @_; push @main::ROUTES, [ 'get', $path, $cb ]; }

eval $sub_src;
fail("eval register_session: $@") if $@;

# register_session only registers the route when it is invoked
# (register_login-style: eval defines the sub, the dispatcher calls it).
eval { main::register_session(); };
fail("register_session call died: $@") if $@;

# ---- route registration assertions ------------------------------------------
is(scalar @main::ROUTES, 1, 'register_session registers exactly one route');
is($main::ROUTES[0][0], 'get',     'route method is GET');
is($main::ROUTES[0][1], '/session', 'route path is /session');
my $cb = $main::ROUTES[0][2];
ok(ref $cb eq 'CODE', 'route has a handler coderef');

# ---- stub controller ---------------------------------------------------------
{
    package FakeC;
    sub new    { bless { stash => {}, rendered => [] }, $_[0] }
    sub stash  { $_[0]->{stash}{ $_[1] } }
    sub render { my $self = shift; push @{ $self->{rendered} }, {@_}; 1 }
}

# ---- admin claims render verbatim --------------------------------------------
{
    my $c = FakeC->new;
    $c->{stash}{jwt_payload} = {
        username => 'alice',
        is_admin => JSON::PP::true(),
        exp      => 1800000000,
    };
    $cb->($c);
    my $r = $c->{rendered}[0]{json};
    is_deeply([sort keys %$r], [qw(exp is_admin status username)],
        'renders exactly status/username/is_admin/exp (no extra claims)');
    is($r->{status},   'success',    'status is success');
    is($r->{username}, 'alice',      'username comes from the claims');
    is($r->{exp},      1800000000,   'exp comes from the claims');
    ok($r->{is_admin}, 'is_admin truthy for an admin claim');
    is(ref $r->{is_admin}, 'JSON::PP::Boolean',
        'is_admin passes through as a JSON boolean object (Mojo renders true/false)');
}

# ---- non-admin claims render verbatim ----------------------------------------
{
    my $c = FakeC->new;
    $c->{stash}{jwt_payload} = {
        username => 'bob',
        is_admin => JSON::PP::false(),
        exp      => 1790000000,
    };
    $cb->($c);
    my $r = $c->{rendered}[0]{json};
    ok(!$r->{is_admin}, 'is_admin falsy for a non-admin claim');
    is(ref $r->{is_admin}, 'JSON::PP::Boolean',
        'non-admin is_admin also stays a JSON boolean object');
}

done_testing();