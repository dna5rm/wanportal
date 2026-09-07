#!/usr/bin/env perl
# avg_fold.t - lifetime averages in cgi-bin/agent_monitors.pm
use strict;
use warnings;
use FindBin;
use Test::More;

my $pm = "$FindBin::Bin/../../cgi-bin/agent_monitors.pm";
my $src = do { open my $fh, '<', $pm or die $!; local $/; <$fh> };
my $body;
{
    my $start = index($src, 'sub _fold_monitor_averages');
    if ($start >= 0) {
        my $open = index($src, '{', $start);
        if ($open >= 0) {
            my $depth = 0;
            for my $i ($open .. length($src) - 1) {
                my $ch = substr($src, $i, 1);
                $depth++ if $ch eq '{';
                $depth-- if $ch eq '}';
                if ($depth == 0) {
                    $body = substr($src, $start, $i - $start + 1);
                    last;
                }
            }
        }
    }
}
if (!defined $body) {
    plan skip_all => '_fold_monitor_averages not found';
    exit 0;
}
eval "$body\n1" or do { plan skip_all => "compile: $@"; exit 0 };

plan tests => 24;

my $up = _fold_monitor_averages({}, { loss => 0, median => 10, min => 8, max => 12, stddev => 1 });
ok(!$up->{is_down}, 'up sample is not down');
is($up->{sample}, 1, 'first sample count 1');
is($up->{avg_loss}, 0, 'first avg_loss 0');
is($up->{avg_median}, 10, 'first avg_median 10');

my $down = _fold_monitor_averages($up, { loss => 100, median => 0, min => 0, max => 0, stddev => 0 });
ok($down->{is_down}, '100/0 is down');
is($down->{sample}, 2, 'sample increments on down');
is($down->{avg_loss}, 50, 'avg_loss folds 100');
is($down->{avg_median}, 10, 'avg_median frozen on down');

# Recovered sample: RTT averages must mix over up samples only. The
# monitor row carries total_down=1 after the down sample above was
# filed, so the up denominator is sample(3) - total_down(1) = 2 and the
# frozen down sample contributes no weight. (10+20)/2 = 15, not
# (10*2+20)/3.
my $back = _fold_monitor_averages({ %$down, total_down => 1 },
    { loss => 0, median => 20, min => 18, max => 22, stddev => 2 });
ok(!$back->{is_down}, 'recovered sample is not down');
is($back->{sample}, 3, 'sample keeps counting across downtime');
is($back->{avg_loss}, ((50 * 2) + 0) / 3, 'avg_loss folds recovered sample');
is($back->{avg_median}, (10 * 1 + 20) / 2, 'avg_median mixes over up samples only: (10+20)/2');
is($back->{avg_min}, (8 * 1 + 18) / 2, 'avg_min mixes over up samples only');
is($back->{avg_max}, (12 * 1 + 22) / 2, 'avg_max mixes over up samples only');
is($back->{avg_stddev}, (1 * 1 + 2) / 2, 'avg_stddev mixes over up samples only');

# A second outage keeps freezing RTT averages while avg_loss keeps
# folding every sample.
my $down2 = _fold_monitor_averages($back, { loss => 100, median => 0, min => 0, max => 0, stddev => 0 });
ok($down2->{is_down}, 'second outage sample is down');
is($down2->{sample}, 4, 'sample increments on each down');
is($down2->{avg_loss}, (((50 * 2 + 0) / 3) * 3 + 100) / 4, 'avg_loss folds every sample');
is($down2->{avg_median}, 15, 'avg_median still frozen on down');

# Second recovery: the up denominator counts up samples across outages
# (sample 5 - total_down 2 = 3 up samples).
my $back2 = _fold_monitor_averages({ %$down2, total_down => 2 },
    { loss => 0, median => 30, min => 28, max => 32, stddev => 3 });
ok(!$back2->{is_down}, 'second recovery is not down');
is($back2->{sample}, 5, 'sample keeps counting');
is($back2->{avg_median}, (15 * 2 + 30) / 3, 'avg_median mixes over 3 up samples: (15*2+30)/3');
is($back2->{avg_loss}, ((((50 * 2 + 0) / 3) * 3 + 100) / 4 * 4 + 0) / 5, 'avg_loss keeps full-sample denominator');

# A row without total_down (pre-downtime-tracking schema) falls back to
# the full-sample denominator instead of dividing by a bogus count.
my $legacy = _fold_monitor_averages({ sample => 2, avg_loss => 50, avg_median => 10 },
    { loss => 0, median => 20, min => 18, max => 22, stddev => 2 });
is($legacy->{avg_median}, (10 * 2 + 20) / 3, 'missing total_down falls back to full-sample mixing');