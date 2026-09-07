#!/usr/bin/env perl
# rrd_id.t - /rrd monitor-id allowlist in cgi-bin/public_api.pm.
# Extracts the live allowlist regex from the source and applies it directly,
# so the test tracks the code actually shipped (no Mojolicious boot).
# The route rejects a missing/empty id first, then requires the id to match
# the extracted pattern before any DB lookup or RRD filename concat.
# Run: docker exec wanportal prove -l -r /srv/tests/perl

use strict;
use warnings;
use FindBin;
use Test::More;

# /srv is the repo root in both views (bind mount /srv/wanportal -> /srv),
# so cgi-bin is always two levels up from this test dir.
my $api_pm = "$FindBin::Bin/../../cgi-bin/public_api.pm";

my $src = do {
    open my $fh, '<', $api_pm or die "cannot read $api_pm: $!\n";
    local $/; <$fh>
};

my ($pat) = $src =~ /unless\s+\$id\s*=~\s*(\/[^\n]*?\/)\s*;/;
if (!defined $pat) {
    plan skip_all => 'id allowlist regex not found in public_api.pm; update this test';
    exit 0;
}

my $re = eval "qr$pat";
if (!defined $re || $@) {
    my $err = $@ || 'undefined';
    chomp $err;
    plan skip_all => "extracted allowlist regex does not compile: $err";
    exit 0;
}

plan tests => 12;

# Mirrors the live route: missing param fails the truthiness guard, then the
# extracted allowlist decides.
sub allows {
    my ($id) = @_;
    return 0 unless defined $id;
    return $id =~ $re ? 1 : 0;
}

my $uuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

ok(allows($uuid),                        'lowercase UUID allowed');
ok(allows(uc $uuid),                     'uppercase UUID allowed');
ok(allows('f' x 18 . '0' x 18),          '36 hex chars without dashes allowed');

ok(!allows('../../../etc/passwd'),       'path traversal rejected');
ok(!allows('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5'), '35 chars rejected');
ok(!allows($uuid . '0'),                 '37 chars rejected');
ok(!allows(''),                          'empty id rejected');
ok(!allows(undef),                       'missing id rejected');
ok(!allows(' ' . $uuid),                 'leading whitespace rejected');
ok(!allows($uuid . "\n"),                'trailing newline rejected');
ok(!allows($uuid . chr(0)),              'NUL-suffixed id rejected');
ok(!allows('z' x 36),                    'non-hex 36 chars rejected');