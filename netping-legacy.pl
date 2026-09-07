#!/usr/bin/env perl

=head1 NAME

netping-legacy.pl - legacy Net::Ping fallback agent

=head1 SYNOPSIS

    SERVER=https://wanportal.example.com PASSWORD=... AGENT_ID=... \
        /srv/netping-legacy.pl

=head1 DESCRIPTION

This is the old-Perl fallback agent. It is not copied into
Dockerfile.agent; the agent that ships is netping-agent.pl. This file
stays in the tree as the legacy fallback.

The flow matches the shipped agent: fetch the monitor list for
AGENT_ID, fork one child per monitor (up to 120 in flight), ping each
target with Net::Ping, and post the results back in chunks of 100 with
up to three attempts per chunk. Chunks that still fail are logged and
dropped. Probes are ICMP by default, or TCP/UDP when the monitor calls
for it; an address containing a colon is pinged with icmpv6 only when
the transport is ICMP.

DSCP is where it falls short. The monitor's DSCP name is mapped to a
TOS byte, but nothing applies it: Net::Ping gets no TOS value here,
and Net::Ping's own TOS handling does not actually work anyway. Every
packet leaves best effort regardless of what the monitor asks for.
Real marking needs socket-agent.pl, which builds raw ICMP and stamps
IP_TOS / IPV6_TCLASS on the socket itself.

SSL certificate verification is off on purpose: agents are allowed to
talk to a server with a self-signed certificate. No SSL_version or
cipher pin is set: the local OpenSSL negotiates whatever protocol it
can, since forcing SSLv3 fails outright on modern stacks.

=head1 ENVIRONMENT

SERVER      Base URL of the wanportal API. Must include a path after
            the host (https://host/api). Required.
PASSWORD    Shared secret for this agent. Required.
AGENT_ID    36-character UUID assigned by the server. Required.
DEBUG       Enables debug output on stdout. Optional.

=head1 EXIT STATUS

Exits nonzero when SERVER, PASSWORD or AGENT_ID is missing, when
AGENT_ID is not a 36-character UUID, when the SERVER URL cannot be
parsed, or when the monitor fetch or a fork fails. Failed result
submissions are logged but do not change the exit status.

=head1 SEE ALSO

netping-agent.pl - the shipped default agent

socket-agent.pl - raw sockets with working DSCP marking

=cut

use strict;
use warnings;
use Net::Ping;
use Time::HiRes qw(sleep time);
use JSON;
use IO::Socket::SSL;
use POSIX qw(strftime);
use List::Util qw(min max);

our $VERSION = '0.0.1';

my $AGENT_ID = $ENV{AGENT_ID} || die("ERROR: AGENT_ID env not set\n");
my $PASSWORD = $ENV{PASSWORD} || die("ERROR: PASSWORD env not set\n");
my $SERVER = $ENV{SERVER} || die("ERROR: SERVER env not set\n");
my $debug = $ENV{DEBUG} || 0;

# AGENT_ID is interpolated into API URL paths; the server issues
# char(36) UUIDs (cgi-bin/agent.pm). Require that shape at startup so
# a malformed value cannot bend the request path into a confusing
# 401/404.
$AGENT_ID =~ /^[0-9a-fA-F-]{36}$/
    or die("ERROR: AGENT_ID must be a 36-character UUID (got '$AGENT_ID')\n");

# DSCP name to TOS byte. Resolved for every monitor but never applied:
# Net::Ping gets no TOS here, and its own TOS handling does not work
# anyway, so packets leave unmarked (see the POD).
my $DSCP_MAP = {
    'BE' => 0x00,    # Best Effort
    'EF' => 0xB8,    # Expedited Forwarding
    'AF11' => 0x28,  # Assured Forwarding 11 (DSCP << 2 = TOS)
    'AF12' => 0x30,
    'AF13' => 0x38,
    'AF21' => 0x48,
    'AF22' => 0x50,
    'AF23' => 0x58,
    'AF31' => 0x68,
    'AF32' => 0x70,
    'AF33' => 0x78,
    'AF41' => 0x88,
    'AF42' => 0x90,
    'AF43' => 0x98,
    'CS1' => 0x20,   # Class Selector 1
    'CS2' => 0x40,
    'CS3' => 0x60,
    'CS4' => 0x80,
    'CS5' => 0xA0,
    'CS6' => 0xC0,
    'CS7' => 0xE0,
};

# Timestamped log lines. debug_log stays quiet unless DEBUG is set.
sub debug_log {
    return unless $debug;
    my ($msg) = @_;
    my $timestamp = strftime("%Y-%m-%d %H:%M:%S", localtime);
    printf "[DEBUG][%s] %s\n", $timestamp, $msg;
}

sub info_log {
    my ($msg) = @_;
    my $timestamp = strftime("%Y-%m-%d %H:%M:%S", localtime);
    printf "[INFO][%s] %s\n", $timestamp, $msg;
}

info_log(sprintf("Starting NetPing Agent v%s (%s)", $VERSION, $AGENT_ID));

# SERVER must include a path after the host; split it off for the API
# routes below.
$SERVER =~ m{^https://([^/]+)(/.*)$} or die "Invalid SERVER URL\n";
my ($host, $path) = ($1, $2);

# Fetch this agent's monitors over a hand-rolled HTTPS GET. SSL
# certificate verification is off on purpose: agents are allowed to
# talk to a server with a self-signed certificate.
debug_log("Fetching monitors from API...");

# No SSL_version or cipher pin: the local OpenSSL negotiates whatever
# protocol it can. Old IO::Socket::SSL defaults to the SSLv23
# handshake; modern builds exclude SSLv2/SSLv3 themselves. Forcing
# SSLv3 fails outright on modern stacks.
my $ssl = IO::Socket::SSL->new(
    PeerHost => $host,
    PeerPort => 443,
    SSL_verify_mode => 0,
) or die "Failed to create SSL connection: $!\n";

my $request = "GET $path/agent/$AGENT_ID/monitors HTTP/1.1\r\n";
$request .= "Host: $host\r\n";
$request .= "Content-Type: application/json\r\n";
my $content = encode_json({ password => $PASSWORD });
$request .= "Content-Length: " . length($content) . "\r\n";
$request .= "\r\n";
$request .= $content;

$ssl->print($request);

my $response = '';
while (my $line = $ssl->getline()) {
    $response .= $line;
    last if $response =~ /\r\n\r\n$/;
}

my $body = '';
while (my $chunk = $ssl->getline()) {
    $body .= $chunk;
}

$ssl->close();

die "API Error: Invalid response\n" unless $response =~ /^HTTP\/1\.\d 200/;

debug_log("Raw response body: $body");

# The JSON sits somewhere inside the response body; carve from the
# first { to the last } and decode that.
$body =~ s/^.*?(\{.*\}).*$/$1/s;

debug_log("Extracted JSON: $body");

my $data = eval { decode_json($body) };
die "Invalid JSON response from API: $@\n" if $@;
die "Invalid response format from API\n" unless ref $data eq 'HASH';
die "No monitors array in API response\n" unless ref $data->{monitors} eq 'ARRAY';

my @hosts = @{$data->{monitors}};
info_log("Processing " . scalar(@hosts) . " monitors");

# Fork one child per monitor, capped at MAX_PROCESSES in flight. Each
# child pings its target and writes a single result line down a pipe.
my $MAX_PROCESSES = min(120, scalar(@hosts));
my $current_processes = 0;
my @results;

# Reap finished children so the in-flight counter stays accurate.
$SIG{CHLD} = sub {
    while ((my $pid = waitpid(-1, 0)) > 0) {
        $current_processes--;
    }
};

foreach my $monitor (@hosts) {
    next unless $monitor->{id} && $monitor->{address};

    # At the cap: wait for a child to be reaped before forking another.
    while ($current_processes >= $MAX_PROCESSES) {
        sleep(0.01);
    }

    pipe(my $reader, my $writer) or die "Pipe failed: $!";

    my $pid = fork();
    if (!defined $pid) {
        die "Fork failed: $!";
    } elsif ($pid == 0) { # Child
        close $reader;
        my ($loss, $median, $min, $max, $stddev) = ping($monitor);
        printf $writer "%s %.1f %.1f %.1f %.1f %.1f\n",
            $monitor->{id}, $loss, $median, $min, $max, $stddev;
        close $writer;
        exit 0;
    } else { # Parent
        close $writer;
        push @results, {
            pid => $pid,
            reader => $reader
        };
        $current_processes++;
    }
}

# Read one result line back from each child's pipe.
my @final_results;
foreach my $result (@results) {
    my $line = readline($result->{reader});
    close $result->{reader};

    if ($line) {
        chomp $line;
        my ($id, $loss, $median, $min, $max, $stddev) = split(/\s+/, $line);
        push @final_results, {
            id => $id,
            loss => $loss + 0,
            median => $median + 0,
            min => $min + 0,
            max => $max + 0,
            stddev => $stddev + 0
        };
    }
}

# Post results to the API in chunks of 100, three attempts per chunk.
# Chunks that still fail are logged and dropped; the exit status does
# not change.
if (@final_results) {
    debug_log("Final Results:");
    debug_log(sprintf("  %-36s %8s %8s %8s %8s %8s",
        "Monitor ID", "Loss%", "Min", "Med", "Max", "StdDev"));
    debug_log("  " . "-" x 82);

    foreach my $result (@final_results) {
        debug_log(sprintf("  %-36s %7.1f%% %8.1f %8.1f %8.1f %8.1f",
            $result->{id},
            $result->{loss},
            $result->{min},
            $result->{median},
            $result->{max},
            $result->{stddev}
        ));
    }

    debug_log("Preparing to submit " . scalar(@final_results) . " results");

    my $chunk_size = 100;
    my $retry_count = 3;
    my $retry_delay = 2;
    my $success = 1;

    while (my @chunk = splice(@final_results, 0, $chunk_size)) {
        debug_log("Processing chunk of " . scalar(@chunk) . " results");

        my $attempt = 0;
        my $submitted = 0;

        while ($attempt < $retry_count && !$submitted) {
            $attempt++;
            if ($attempt > 1) {
                debug_log("Retry attempt $attempt of $retry_count");
                sleep($retry_delay);
            }

            eval {
                $submitted = submit_results(\@chunk);
            };

            if ($@) {
                debug_log("Submission attempt failed: $@");
                if ($attempt == $retry_count) {
                    info_log("Failed to submit chunk after $retry_count attempts");
                    $success = 0;
                }
            }
        }
    }

    if ($success) {
        info_log("Successfully submitted all results");
    } else {
        info_log("Some submissions failed - check debug log for details");
    }
} else {
    info_log("No results to submit");
}

exit 0;

# Ping one monitor with Net::Ping. Returns (loss, median, min, max,
# stddev).
sub ping {
    my $monitor = shift;

    my $protocol = lc($monitor->{protocol} || 'icmp');
    my $port = $monitor->{port} || 0;
    my $dscp = $monitor->{dscp} || 'BE';
    my $tos = $DSCP_MAP->{$dscp} || 0x00;
    my $count = min(5, $monitor->{pollcount} || 5);

    # Transport comes from the monitor protocol; icmpv6 is only for
    # ICMP probes against IPv6 targets. A colon in the address must
    # not override a tcp/udp monitor, or a closed port would report
    # ICMPv6 latency as if the TCP port had answered. Net::Ping's
    # tcp/udp transports are IPv4-only, so tcp/udp probes against
    # IPv6 targets now fail visibly (loss) instead of being relabeled.
    my $p;
    if ($protocol eq "tcp" || $protocol eq "udp") {
        $p = Net::Ping->new($protocol, 1, 56);
    } elsif ($monitor->{address} =~ /:/) {
        $p = Net::Ping->new('icmpv6', 1, 56);
    } else {
        $p = Net::Ping->new($protocol, 1, 56);
    }

    if ($protocol eq "tcp" || $protocol eq "udp") {
        $p->{port_num} = $port;
    }
    $p->hires();

    my @rtts;
    my $consecutive_fails = 0;

    for my $i (1..$count) {
        # Three misses in a row: stop probing early.
        last if $consecutive_fails >= 3;

        my ($success, $rtt) = $p->ping($monitor->{address});
        if ($success) {
            $rtt *= 1000;  # seconds to milliseconds
            push @rtts, $rtt;
            $consecutive_fails = 0;
        } else {
            $consecutive_fails++;
        }
        sleep(0.1) if $i < $count;
    }

    $p->close;

    my $success = scalar @rtts;
    my $loss = $success ? (($count - $success) / $count * 100) : 100;

    if ($success) {
        @rtts = sort { $a <=> $b } @rtts;
        my $min = $rtts[0];
        my $max = $rtts[-1];

        # rtts is sorted by now; the median is the middle value, or the
        # mean of the middle pair.
        my $median;
        if ($success % 2 == 0) {
            $median = ($rtts[$success/2 - 1] + $rtts[$success/2]) / 2;
        } else {
            $median = $rtts[int($success/2)];
        }

        # Population standard deviation around the mean.
        my $mean = sum(@rtts) / $success;
        my $variance = sum(map { ($_ - $mean) ** 2 } @rtts) / $success;
        my $stddev = sqrt($variance);

        return ($loss, $median, $min, $max, $stddev);
    }

    return (100, 0, 0, 0, 0);
}

# Local sum() helper; List::Util is imported without sum here.
sub sum {
    my $sum = 0;
    $sum += $_ for @_;
    return $sum;
}

# Local sqrt() by Newton's method; plenty accurate for a stddev.
sub sqrt {
    my ($x) = @_;
    return 0 if $x == 0;
    my $guess = $x / 2;
    for (1..10) {
        $guess = ($guess + $x / $guess) / 2;
    }
    return $guess;
}

sub submit_results {
    my ($chunk) = @_;

    # Same hand-rolled HTTPS as the monitor fetch, this time a POST.
    # Same SSL setup too: no protocol or cipher pin, verification off
    # on purpose.
    my $ssl = IO::Socket::SSL->new(
        PeerHost => $host,
        PeerPort => 443,
        SSL_verify_mode => 0,
    ) or die "Failed to create SSL connection: $!\n";

    my $request = "POST $path/agent/$AGENT_ID/monitors HTTP/1.1\r\n";
    $request .= "Host: $host\r\n";
    $request .= "Content-Type: application/json\r\n";
    my $content = encode_json({
        password => $PASSWORD,
        results => $chunk
    });
    $request .= "Content-Length: " . length($content) . "\r\n";
    $request .= "\r\n";
    $request .= $content;

    $ssl->print($request);

    my $response = '';
    while (my $line = $ssl->getline()) {
        $response .= $line;
        last if $response =~ /\r\n\r\n$/;
    }

    my $body = '';
    while (my $chunk = $ssl->getline()) {
        $body .= $chunk;
    }

    $ssl->close();

    debug_log("Submit raw response body: $body");

    # Carve the JSON out of the body the same way as above.
    $body =~ s/^.*?(\{.*\}).*$/$1/s;

    debug_log("Submit extracted JSON: $body");

    if ($response =~ /^HTTP\/1\.\d 200/) {
        my $result = eval { decode_json($body) };
        if ($@) {
            debug_log("Submit JSON decode error: $@");
            return 0;
        }
        if ($result->{status} eq 'success') {
            return 1;
        }
    }
    return 0;
}
