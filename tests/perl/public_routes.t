#!/usr/bin/env perl
# public_routes.t - the public read-only surface stays public and tight.
#
# Source-level check of cgi-bin/public_api.pm, in the same extraction
# style as openapi_paths.t (routes are read from the source at run
# time, so the test cannot stale-drift on a hardcoded list). Pins:
#
#   * the public listing routes are all still registered
#   * the detail routes exist: GET /agents/:id, /targets/:id, /monitors/:id
#   * GET /dashboard exists and carries the counts/percents/top-slow keys
#   * /health reports uptime_seconds
#   * /monitors accepts the agent_id, target_id, and q filters and
#     stamps latency fields on its rows
#   * the /agents/:id detail handler never selects the password column
#   * no cgi-bin file ever registers an /openapi route (the spec is
#     served by an Apache Alias, not the API dispatcher)
#
# Runs standalone (source parsing only, no Mojolicious needed).

use strict;
use warnings;
use Cwd qw(abs_path);
use File::Basename qw(dirname);
use Test::More;

my $root = abs_path(dirname(abs_path($0)) . '/../..');
my $pub  = "$root/cgi-bin/public_api.pm";

ok(-f $pub, "public_api.pm found: $pub") or diag 'public_api.pm missing';

# ---- extract main::get routes + handler slices from public_api.pm ----------
my %routes;     # route pattern => first line number
my @lines;

{
    open my $fh, '<', $pub or plan skip_all => "cannot open $pub";
    my $ln = 0;
    while (my $line = <$fh>) {
        $ln++;
        push @lines, $line;
        next if $line =~ /^\s*#/;          # comment lines
        next if $line =~ /^\s*=[a-zA-Z]/;  # POD blocks
        # matches: main::get '/agents/:id' => sub {   (single or double
        # quotes, optional parens written as a [(] class so the regex
        # stays readable)
        next unless $line =~ /\bmain::get\b\s*[(]?\s*(['"])(.*?)\1\s*=>/;
        my $route = $2;
        $routes{$route} = $ln unless exists $routes{$route};
    }
    close $fh;
}

# Handler body between two route registrations (inclusive-exclusive).
sub slice {
    my ($from_route, $to_route) = @_;
    my $from = $routes{$from_route} or return '';
    my $to   = defined $to_route && exists $routes{$to_route}
        ? $routes{$to_route}
        : scalar(@lines) + 1;
    return join '', @lines[ ($from - 1) .. ($to - 2) ];
}

# ---- the public surface -----------------------------------------------------
for my $route (qw(
    /health
    /agents
    /agents/:id
    /targets
    /targets/:id
    /monitors
    /monitors/:id
    /dashboard
    /rrd
)) {
    ok(exists $routes{$route}, "public route registered: GET $route");
}

# ---- /health: uptime --------------------------------------------------------
my $health = slice('/health', '/agents');
like($health, qr/uptime_seconds/, '/health reports uptime_seconds');
like(join('', @lines), qr{/proc/uptime},
    '/health uptime comes from /proc/uptime (via the _uptime_seconds helper)');

# ---- /monitors: filters + latency fields ------------------------------------
my $monitors = slice('/monitors', '/monitors/:id');
like($monitors, qr/\$c->param\('agent_id'\)/,  '/monitors honors the agent_id filter');
like($monitors, qr/\$c->param\('target_id'\)/, '/monitors honors the target_id filter');
like($monitors, qr/\$c->param\('q'\)/,         '/monitors honors the q filter');
like($monitors, qr/a\.name LIKE \?/,           '/monitors q filter sweeps agent names');
like($monitors, qr/t\.description LIKE \?/,    '/monitors q filter sweeps target descriptions');
like($monitors, qr/add_latency_fields\(/,      '/monitors stamps latency fields on rows');

# ---- /monitors/:id detail ----------------------------------------------------
my $monitor_detail = slice('/monitors/:id', '/dashboard');
like($monitor_detail, qr/monitor_is_active/,  'monitor detail keeps the raw monitor flag');
like($monitor_detail, qr/agent_description/,  'monitor detail joins agent description');
like($monitor_detail, qr/target_description/, 'monitor detail joins target description');
like($monitor_detail, qr/add_latency_fields\(/, 'monitor detail stamps latency fields');

# ---- /dashboard rollup -------------------------------------------------------
my $dashboard = slice('/dashboard', '/rrd');
like($dashboard, qr/percent_up/,       'dashboard reports percent_up');
like($dashboard, qr/percent_degraded/, 'dashboard reports percent_degraded');
like($dashboard, qr/percent_down/,     'dashboard reports percent_down');
like($dashboard, qr/top_slow/,         'dashboard reports top_slow');

# ---- /agents/:id: never leaks the password -----------------------------------
# Comment lines are stripped first: the handler's own doc comment says
# "passwords are never included", which must not read as a leak. The
# qw/dsn username password/ DBI connect idiom stays — it is connection
# config, not a column.
(my $agent_detail = slice('/agents/:id', '/targets')) =~ s/^\s*#.*$//mg;
unlike($agent_detail, qr/select[^;]*password/i, 'agent detail never selects a password column');
like($agent_detail, qr/SELECT id, name, address, description, last_seen, is_active/,
    'agent detail selects only the public columns');
like($agent_detail, qr/WHERE id = \?/, 'agent detail lookup is parameterized');

# ---- /openapi.yaml must never come back as an API route ----------------------
my $openapi_routes = 0;
my $cgi_dir = "$root/cgi-bin";
opendir my $dh, $cgi_dir or die "opendir $cgi_dir: $!";
for my $f (sort grep { !/^\./ && -f "$cgi_dir/$_" } readdir $dh) {
    open my $fh2, '<', "$cgi_dir/$f" or die "open $cgi_dir/$f: $!";
    while (my $line = <$fh2>) {
        next if $line =~ /^\s*#/;
        next if $line =~ /^\s*=[a-zA-Z]/;
        next unless $line =~ /\bmain::(get|post|put|del|delete|patch)\b/ &&
                    $line =~ /openapi/i;
        fail("no /openapi API route in $f");
        diag "  $f: $line";
        close $fh2;
        goto DONE_SCAN;
    }
    close $fh2;
}
DONE_SCAN:
close $dh;
pass('no cgi-bin file registers an /openapi route (spec stays on the Apache Alias)');

done_testing();