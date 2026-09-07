#!/usr/bin/env perl
# latency_flag.t - the API-side latency spike rules match the PHP lib.
#
# public_api::add_latency_fields() is the Perl twin of
# wanportal_is_latency_issue() in htdocs/lib/monitor_metrics.php. The
# API stamps latency_flag / latency_threshold_ms on every /monitors
# row, so the two implementations must agree gate for gate:
#
#   - effectively active (monitor, agent, and target all enabled)
#   - fresh: last_update newer than 3 * pollinterval seconds
#     (pollinterval falls back to the DB default of 60)
#   - current_loss < 100 (down is not a latency problem)
#   - at least 2 samples so the median is real
#   - threshold (avg_median + 2*avg_stddev) > 0, and
#     current_median strictly above it
#
# The test rows mirror the cases in tests/php/lib_smoke.php so the
# two suites pin the same behavior from both sides.
#
# Runs under tests/run.sh (prove -l -r /srv/tests/perl in the
# wanportal container, where RRDs and the rest of the API's module
# pile are installed). Skips outside the container.

use strict;
use warnings;
use FindBin;
use lib "$FindBin::Bin/../../cgi-bin";
use POSIX qw(strftime);
use Test::More;

# public_api.pm compiles main::get route registrations, so the Lite
# helpers must predeclare them before the module is required - exactly
# like the real dispatcher does. Both that and RRDs only live in the
# full stack (the wanportal container), hence the skip.
BEGIN {
    plan skip_all => 'needs Mojolicious::Lite + RRDs; run under tests/run.sh in the container'
        unless eval { local $SIG{__DIE__}; require Mojolicious; 1 };
}

use Mojolicious::Lite;
require public_api;

my $now = time();

# Format an offset from $now as the DB datetime shape both sides parse.
my $ts = sub {
    my ($offset) = @_;
    return strftime('%Y-%m-%d %H:%M:%S', localtime($now + $offset));
};

my $base = {
    is_active         => 1,
    agent_is_active   => 1,
    target_is_active  => 1,
    sample            => 10,
    avg_median        => 12.0,
    avg_stddev        => 2.0,
    current_median    => 40.0,
    current_loss      => 0,
    last_update       => $ts->(0),
    pollinterval      => 60,
};

{
    my $row = public_api::add_latency_fields({ %$base }, $now);
    is($row->{latency_flag}, 1, 'spike above avg_median+2sigma flags');
    is($row->{latency_threshold_ms}, 16.0, 'threshold is avg_median + 2*avg_stddev');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, current_loss => 100, current_median => 0 }, $now);
    is($row->{latency_flag}, 0, '100% loss is not a latency issue');
    is($row->{latency_threshold_ms}, 16.0,
        'threshold still stamped on rows that fail the gates');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, sample => 1, avg_median => 0, avg_stddev => 0,
          current_median => 5 }, $now);
    is($row->{latency_flag}, 0, 'no baseline (threshold 0) is not an issue');
    is($row->{latency_threshold_ms}, 0.0, 'no baseline reports threshold 0');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, current_median => 15 }, $now);
    is($row->{latency_flag}, 0, 'current within avg_median+2sigma is not an issue');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, current_median => 16.0 }, $now);
    is($row->{latency_flag}, 0, 'current exactly at threshold is not an issue');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, last_update => $ts->(-300) }, $now);
    is($row->{latency_flag}, 0, 'last_update older than 3*pollinterval is not an issue');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, last_update => $ts->(-170) }, $now);
    is($row->{latency_flag}, 1, 'last_update inside the 180s window still flags');
}

{
    my $row = public_api::add_latency_fields({ %$base, last_update => undef }, $now);
    is($row->{latency_flag}, 0, 'missing last_update is not an issue');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, last_update => '0000-00-00 00:00:00' }, $now);
    is($row->{latency_flag}, 0, 'unparseable last_update counts as stale');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, pollinterval => undef, last_update => $ts->(-181) }, $now);
    is($row->{latency_flag}, 0, 'default pollinterval 60 gives a 180s freshness window');
    my $row2 = public_api::add_latency_fields(
        { %$base, pollinterval => undef, last_update => $ts->(-60) }, $now);
    is($row2->{latency_flag}, 1, 'default pollinterval 60 still flags inside the window');
}

{
    my $row = public_api::add_latency_fields(
        { %$base, pollinterval => 0, last_update => $ts->(-181) }, $now);
    is($row->{latency_flag}, 0, 'non-positive pollinterval falls back to 60');
}

{
    my $row = public_api::add_latency_fields({ %$base, is_active => 0 }, $now);
    is($row->{latency_flag}, 0, 'inactive monitor is not an issue');
}

{
    my $row = public_api::add_latency_fields({ %$base, agent_is_active => 0 }, $now);
    is($row->{latency_flag}, 0, 'inactive agent is not an issue');
}

{
    my $row = public_api::add_latency_fields({ %$base, target_is_active => 0 }, $now);
    is($row->{latency_flag}, 0, 'inactive target is not an issue');
}

{
    # DBI hands numeric columns back as strings, sometimes as empty
    # strings or undef; none of that may trip warnings or flags.
    my $row;
    my $ok = eval {
        local $SIG{__DIE__};
        $row = public_api::add_latency_fields(
            { %$base, avg_median => '', avg_stddev => undef,
              current_median => 'junk', current_loss => undef }, $now);
        1;
    };
    ok($ok, 'non-numeric metric fields do not die');
    is($row->{latency_flag}, 0, 'junk metrics resolve to no baseline, no flag');
    is($row->{latency_threshold_ms}, 0.0, 'junk metrics resolve to threshold 0');
}

done_testing();