#!/usr/bin/env perl
use Net::Ping;
print "Net::Ping Version: $Net::Ping::VERSION\n";

my $p = Net::Ping->new("icmp");
print "TOS supported: " . ($p->can('tos') ? "Yes" : "No") . "\n";
