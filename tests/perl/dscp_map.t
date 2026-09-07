#!/usr/bin/env perl
# dscp_map.t - AF/EF/CS maps are TOS bytes (DSCP << 2) and agree
use strict;
use warnings;
use FindBin;
use Test::More;

my $root = "$FindBin::Bin/../..";
my %expect = (
    BE   => 0x00,
    EF   => 0xB8,
    AF11 => 0x28, AF12 => 0x30, AF13 => 0x38,
    AF21 => 0x48, AF22 => 0x50, AF23 => 0x58,
    AF31 => 0x68, AF32 => 0x70, AF33 => 0x78,
    AF41 => 0x88, AF42 => 0x90, AF43 => 0x98,
    CS1  => 0x20, CS2 => 0x40, CS3 => 0x60,
    CS4  => 0x80, CS5 => 0xA0, CS6 => 0xC0, CS7 => 0xE0,
);

sub extract_map {
    my ($file) = @_;
    open my $fh, '<', $file or die "$file: $!";
    my $src = do { local $/; <$fh> };
    my $start = index($src, "'BE' =>");
    return {} if $start < 0;
    my $chunk = substr($src, $start, 800);
    my %m;
    while ($chunk =~ /'(BE|EF|AF\d+|CS\d+)'\s*=>\s*(0x[0-9A-Fa-f]+)/g) {
        $m{$1} = hex($2);
    }
    return \%m;
}

my @files = (
    'netping-agent.pl',
    'netping-legacy.pl',
    'socket-agent.pl',
);
plan tests => 3 * (scalar keys %expect);

for my $f (@files) {
    my $got = extract_map("$root/$f");
    for my $k (sort keys %expect) {
        is($got->{$k}, $expect{$k}, "$f $k TOS byte");
    }
}
