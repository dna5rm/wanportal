#!/usr/bin/env perl
# netping_script.t - GET /netping-script serves /srv/agent/netping-agent.pl to JWT callers.
#
# Extraction-based drift guard (same pattern as session_route.t and the
# other tests/perl suites): `sub register_netping_script` is pulled out
# of cgi-bin/netping_agent.pm at run time with brace-balanced
# extraction, eval'd against a stub main::get, then invoked directly -
# once with a temp-file path so the handler is exercised without
# touching the real script, and once for route-shape assertions.
# No Mojolicious boot; a failed extraction degrades to a loud skip.
#
# Auth enforcement (JWT required, 401 without a bearer) is covered
# live by tests/validate.sh; the route is registered inside the JWT
# group in cgi-bin/api.

use strict;
use warnings;
use FindBin;
use File::Temp qw(tempdir);
use Test::More;

my $root     = "$FindBin::Bin/../..";
my $src_file = "$root/cgi-bin/netping_agent.pm";
my $php_file = "$root/htdocs/classic/netping.php";

ok(-f $src_file, 'cgi-bin/netping_agent.pm found')
    or plan skip_all => 'cgi-bin/netping_agent.pm missing';
my $src = do { local $/; open my $fh, '<', $src_file or die "$src_file: $!"; <$fh> };

# ---- brace-balanced extraction of `sub register_netping_script` ------------
my $start = index($src, 'sub register_netping_script {');
plan skip_all => 'sub register_netping_script not found in netping_agent.pm (layout changed)'
    if $start < 0;
my $open = index($src, '{', $start);
my ($depth, $end) = (0, -1);
for my $i ($open .. length($src) - 1) {
    my $ch = substr($src, $i, 1);
    if    ($ch eq '{') { $depth++ }
    elsif ($ch eq '}') { $depth--; if ($depth == 0) { $end = $i; last } }
}
plan skip_all => 'brace-balanced extraction of register_netping_script failed'
    if $end < 0;
my $sub_src = substr($src, $start, $end - $start + 1);

# ---- path-parity guard: same file the classic page reads --------------------
# htdocs/classic/netping.php serves $filename = '/srv/agent/netping-agent.pl' to classic
# users; the API endpoint must read exactly that path by default.
my $php_src = do { local $/; open my $fh, '<', $php_file or die "$php_file: $!"; <$fh> };
my ($php_path) = $php_src =~ m{^\s*\$filename\s*=\s*'([^']+)'\s*;}m;
plan skip_all => "could not find \$filename literal in htdocs/classic/netping.php"
    unless defined $php_path;
like($sub_src, qr{'\Q$php_path\E'}, "default script path matches netping.php ($php_path)");

# ---- the file body must never be logged ------------------------------------
unlike($sub_src, qr/->\s*log\b/, 'handler contains no logging call (file body never logged)');

# ---- stub route collector ---------------------------------------------------
# The extracted source calls the fully-qualified main::get, so defining
# sub get in this (main) package captures the registration.
our @ROUTES;
sub get { my ($path, $cb) = @_; push @main::ROUTES, [ 'get', $path, $cb ]; }

eval $sub_src;
fail("eval register_netping_script: $@") if $@;

my $tmpdir = tempdir(CLEANUP => 1);
my $script = "$tmpdir/netping-agent.pl";
{
    open my $fh, '>', $script or die "open $script: $!";
    print {$fh} "#!/usr/bin/env perl\n# probe script with 'quotes' and \"doubles\"\nprint \"hi\\n\";\n";
    close $fh;
}

# register_netping_script only registers the route when it is invoked
# (dispatcher-style: eval defines the sub, the dispatcher calls it).
eval { main::register_netping_script($script); };
fail("register_netping_script call died: $@") if $@;

# ---- route registration assertions ------------------------------------------
is(scalar @main::ROUTES, 1, 'register_netping_script registers exactly one route');
is($main::ROUTES[0][0], 'get',       'route method is GET');
is($main::ROUTES[0][1], '/netping-script', 'route path is /netping-script');
my $cb = $main::ROUTES[0][2];
ok(ref $cb eq 'CODE', 'route has a handler coderef');

# ---- stub controller ---------------------------------------------------------
{
    package FakeC;
    sub new    { bless { rendered => [] }, $_[0] }
    sub render { my $self = shift; push @{ $self->{rendered} }, {@_}; 1 }
}

sub last_render {
    my ($c) = @_;
    return @{ $c->{rendered} } ? $c->{rendered}[-1] : undef;
}

# ---- success: file content passes through verbatim ---------------------------
{
    my $c     = FakeC->new;
    my $disk  = do { local $/; open my $fh, '<', $script or die; <$fh> };
    $cb->($c);
    my $r = last_render($c);
    ok($r, 'handler renders on success');
    is($r->{status} // 200, 200, 'success renders HTTP 200 (explicit or Mojolicious default)');
    is_deeply([sort keys %{ $r->{json} }], [qw(content filename status)],
        'renders exactly status/filename/content');
    is($r->{json}{status},   'success',           'status is success');
    is($r->{json}{filename}, 'netping-agent.pl',  'filename is the agent script basename');
    is($r->{json}{content},  $disk,               'content is the file body verbatim');
}

# ---- missing file: 404 with a plain error, no content key --------------------
{
    my $c = FakeC->new;
    main::register_netping_script("$tmpdir/nope/missing.pl");
    is(scalar @main::ROUTES, 2, 'second registration captured');
    my $cb404 = $main::ROUTES[1][2];
    $cb404->($c);
    my $r = last_render($c);
    ok($r, 'handler renders when file is missing');
    is($r->{status}, 404, 'missing file renders HTTP 404');
    is($r->{json}{status}, 'error', 'missing file reports status error');
    like($r->{json}{message}, qr/netping-agent\.pl not found/, 'message names the script');
    ok(!exists $r->{json}{content}, 'no content key leaked on the 404');
}

done_testing();