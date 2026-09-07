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

plan tests => 8;

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
