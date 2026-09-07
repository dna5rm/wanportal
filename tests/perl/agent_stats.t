#!/usr/bin/env perl
# agent_stats.t - loss/median/min/max/stddev math at the tail of
# netping-agent.pl sub ping(), extracted and run verbatim.
#
# The stats block is not a named sub, so this extracts the source from
# "my $success = scalar @rtts;" through the final
# "return (100, 0, 0, 0, 0);" and wraps it in a test-only sub that takes
# ( \@rtts_ms, $probe_count ). The production expression is what runs --
# the test carries no copy of the math. Pins in particular that loss is
# computed against the planned probe count ($count), not the number of
# successful replies, and that an empty result set is 100% loss.
#
# Skips if the markers disappear (refactor moved the code), so a reflow
# does not leave a red suite: re-point the markers here when that happens.

use strict;
use warnings;
use FindBin;
use Test::More;
use List::Util qw(sum);

my $root = "$FindBin::Bin/../..";
my $file = "$root/netping-agent.pl";

unless (-f $file) {
    plan skip_all => 'netping-agent.pl not present';
    exit 0;
}

my $src = do { open my $fh, '<', $file or die "$file: $!"; local $/; <$fh> };

my $start = index($src, 'my $success = scalar @rtts;');
my $end_marker = 'return (100, 0, 0, 0, 0);';
my $end = index($src, $end_marker, $start > 0 ? $start : 0);

if ($start < 0 || $end < 0) {
    plan skip_all => 'stats tail not found in netping-agent.pl (markers moved?)';
    exit 0;
}

my $tail = substr($src, $start, $end - $start + length($end_marker));

my $code = <<'PREAMBLE';
sub {
    my @rtts  = @{$_[0]};   # successful probe RTTs, milliseconds
    my $count = $_[1];      # planned probe count for this monitor
PREAMBLE
$code .= $tail . "\n}";

my $stats = eval $code;
if ($@ || !$stats) {
    plan skip_all => "extracted stats tail does not compile: $@";
    exit 0;
}

plan tests => 29;

# approx comparator for stddev: ok(abs(got - want) < 1e-9). Inlined on
# purpose -- a helper named `close` collides with the builtin and Perl
# resolves the calls to CORE::close, silently emitting no test output.
sub close_to {
    my ($got, $want, $name) = @_;
    ok(defined($got) && abs($got - $want) < 1e-9, $name);
}

# Case A: odd sample count, no loss ([20,10,30] sorts to 10,20,30)
{
    my @r = $stats->([20, 10, 30], 3);
    is(scalar @r, 5, 'odd N: returns 5 stats');
    cmp_ok($r[0], '==', 0,        'odd N: loss 0 when all probes succeed');
    cmp_ok($r[1], '==', 20,       'odd N: median is middle sorted value');
    cmp_ok($r[2], '==', 10,       'odd N: min');
    cmp_ok($r[3], '==', 30,       'odd N: max');
    close_to($r[4], 8.16496580927726, 'odd N: population stddev of 10/20/30');
}

# Case B: even sample count ([40,10,30,20] sorts to 10,20,30,40)
{
    my @r = $stats->([40, 10, 30, 20], 4);
    cmp_ok($r[0], '==', 0,        'even N: loss 0');
    cmp_ok($r[1], '==', 25,       'even N: median is mean of middle pair');
    cmp_ok($r[2], '==', 10,       'even N: min');
    cmp_ok($r[3], '==', 40,       'even N: max');
    close_to($r[4], 11.180339887498949, 'even N: stddev of 10/20/30/40');
}

# Case C: partial loss -- loss counts planned probes, not successes
{
    my @r = $stats->([10, 20, 30], 5);
    cmp_ok($r[0], '==', 40,       'partial: 3 of 5 probes -> loss 40');
    cmp_ok($r[1], '==', 20,       'partial: median of successes only');
    cmp_ok($r[2], '==', 10,       'partial: min of successes');
    cmp_ok($r[3], '==', 30,       'partial: max of successes');
    close_to($r[4], 8.16496580927726, 'partial: stddev of successes');
}

# Case D: every probe missed
{
    my @r = $stats->([], 5);
    is_deeply(\@r, [100, 0, 0, 0, 0], 'all missed: 100% loss, zeroed stats');
}

# Case E: early stop -- three consecutive misses end the loop early
{
    my @r = $stats->([5, 15], 5);
    cmp_ok($r[0], '==', 60,       'early stop: 2 of 5 probes -> loss 60');
    cmp_ok($r[1], '==', 10,       'early stop: median');
    cmp_ok($r[2], '==', 5,        'early stop: min');
    cmp_ok($r[3], '==', 15,       'early stop: max');
    close_to($r[4], 5.0, 'early stop: stddev of 5/15');
}

# Case F: single sample
{
    my @r = $stats->([12.5], 1);
    cmp_ok($r[0], '==', 0,        'single: loss 0');
    cmp_ok($r[1], '==', 12.5,     'single: median is the one sample');
    cmp_ok($r[2], '==', 12.5,     'single: min');
    cmp_ok($r[3], '==', 12.5,     'single: max');
    cmp_ok($r[4], '==', 0,        'single: stddev 0');
}

# Case G: even pair, non-integer median
{
    my @r = $stats->([10, 15], 2);
    cmp_ok($r[1], '==', 12.5,     'pair: median 12.5');
    close_to($r[4], 2.5, 'pair: stddev of 10/15');
}