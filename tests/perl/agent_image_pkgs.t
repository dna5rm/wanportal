#!/usr/bin/env perl
# agent_image_pkgs.t - Dockerfile.agent apk packages cover netping-agent.pl use lines
#
# Static/extract check tying the agent image to the code it ships:
#   1. every non-pragmat `use` in netping-agent.pl maps to an apk package
#      actually present in the Dockerfile.agent `apk add --no-cache` line
#      (core modules map to the base `perl` package);
#   2. the HTTPS enabler perl-lwp-protocol-https is shipped even though no
#      `use` line references it;
#   3. packages the Dockerfile deliberately excludes (curl, jq,
#      perl-sys-cpu, perl-lwp-useragent-determined, perl-parallel-forkmanager)
#      are absent, and netping-agent.pl does not use the modules they provide;
#   4. drift guard: a new `use Module` in netping-agent.pl fails here until it
#      is mapped below and shipped in Dockerfile.agent.
#
# Skips when Dockerfile.agent or netping-agent.pl is missing (image removed
# from the repo), fails on structural drift (no apk line, unknown module).

use strict;
use warnings;
use FindBin;
use Test::More;

my $root = "$FindBin::Bin/../..";

unless (-f "$root/agent/Dockerfile.agent" && -f "$root/agent/netping-agent.pl") {
    plan skip_all => 'agent/Dockerfile.agent or agent/netping-agent.pl not present';
    exit 0;
}

sub slurp {
    my ($f) = @_;
    open my $fh, '<', $f or die "$f: $!";
    local $/;
    <$fh>;
}

my $agent = slurp("$root/agent/netping-agent.pl");
my $df    = slurp("$root/agent/Dockerfile.agent");

# Join backslash line continuations so `apk add --no-cache X \` + `Y` is one
# logical line, then grab every apk add list (global match covers multiple RUNs).
(my $df_flat = $df) =~ s/\\\n/ /g;
my @apk_lists = $df_flat =~ /apk add --no-cache([^\n]*)/g;
if (!@apk_lists) {
    fail('Dockerfile.agent has an apk add --no-cache line');
    done_testing();
    exit 0;
}
my @pkgs = split ' ', join ' ', @apk_lists;
my %shipped = map { $_ => 1 } @pkgs;

# Modules netping-agent.pl actually `use`s (Pod/comment text is not anchored
# at line start after "use ", so it does not match).
my @used = $agent =~ /^\s*use\s+([A-Za-z_]\w*(?:::\w+)*)/mg;

my %PRAGMA = map { $_ => 1 } qw(
    strict warnings vars constant feature diagnostics encoding
    bigint integer bytes fields base parent utf8 version subs attrs
);

# Alpine package that ships each module. Core modules ride in with the
# base `perl` package (Net::Ping, Time::HiRes, POSIX, List::Util).
my %PKG = (
    'Net::Ping'       => 'perl',
    'Time::HiRes'     => 'perl',
    'POSIX'           => 'perl',
    'List::Util'      => 'perl',
    'LWP::UserAgent'  => 'perl-libwww',
    'JSON'            => 'perl-json',
    'IO::Socket::SSL' => 'perl-io-socket-ssl',
);

# Packages the Dockerfile comment names as deliberately not shipped, and the
# modules those packages provide (must not appear in netping-agent.pl either).
my %EXCLUDED = (
    'curl'                          => undef,
    'jq'                            => undef,
    'perl-sys-cpu'                  => 'Sys::CPU',
    'perl-lwp-useragent-determined' => 'LWP::UserAgent::Determined',
    'perl-parallel-forkmanager'     => 'Parallel::ForkManager',
);

my @external       = grep { !$PRAGMA{$_} } @used;

# 1. Drift guard: every non-pragma `use` must be mapped to a package.
for my $mod (sort @external) {
    ok($PKG{$mod}, "netping-agent.pl: `use $mod` is mapped to an apk package"
        . ' (add it to %PKG here and to Dockerfile.agent)');

    if ($PKG{$mod}) {
        ok($shipped{ $PKG{$mod} },
            "Dockerfile.agent ships $PKG{$mod} for $mod");
    }
}

# 2. HTTPS support for LWP has no `use` line; pin it anyway.
ok($shipped{'perl-lwp-protocol-https'},
    'Dockerfile.agent ships perl-lwp-protocol-https (https support for LWP)');

# 3. Container basics: crond is the main process, tzdata for log timestamps.
ok($shipped{'cronie'}, 'Dockerfile.agent ships cronie (crond main process)');
ok($shipped{'tzdata'}, 'Dockerfile.agent ships tzdata (log timestamps)');

# 4. Deliberately-excluded packages stay out, and the agent does not use
#    the modules they would provide.
for my $pkg (sort keys %EXCLUDED) {
    ok(!$shipped{$pkg}, "Dockerfile.agent does not ship $pkg");

    my $mod = $EXCLUDED{$pkg} or next;
    ok(index($agent, $mod) < 0,
        "netping-agent.pl does not use $mod (that belongs to socket-agent.pl)");
}

done_testing();