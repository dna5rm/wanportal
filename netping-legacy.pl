#!/usr/bin/env perl

use strict;
use warnings;
use Net::Ping;
use Time::HiRes qw(sleep time);
use JSON;
use IO::Socket::SSL;
use POSIX qw(strftime);
use List::Util qw(min max);

our $VERSION = '0.0.1';

# Get environment variables
my $AGENT_ID = $ENV{AGENT_ID} || die("ERROR: AGENT_ID env not set\n");
my $PASSWORD = $ENV{PASSWORD} || die("ERROR: PASSWORD env not set\n");
my $SERVER = $ENV{SERVER} || die("ERROR: SERVER env not set\n");
my $debug = $ENV{DEBUG} || 0;

# DSCP to TOS mapping
my $DSCP_MAP = {
    'BE' => 0x00,    # Best Effort
    'EF' => 0xB8,    # Expedited Forwarding
    'AF11' => 0x0A,  # Assured Forwarding 11
    'AF12' => 0x0C,
    'AF13' => 0x0E,
    'AF21' => 0x12,
    'AF22' => 0x14,
    'AF23' => 0x16,
    'AF31' => 0x1A,
    'AF32' => 0x1C,
    'AF33' => 0x1E,
    'AF41' => 0x22,
    'AF42' => 0x24,
    'AF43' => 0x26,
    'CS1' => 0x20,   # Class Selector 1
    'CS2' => 0x40,
    'CS3' => 0x60,
    'CS4' => 0x80,
    'CS5' => 0xA0,
    'CS6' => 0xC0,
    'CS7' => 0xE0,
};

# Debug logger
sub debug_log {
    return unless $debug;
    my ($msg) = @_;
    my $timestamp = strftime("%Y-%m-%d %H:%M:%S", localtime);
    printf "[DEBUG][%s] %s\n", $timestamp, $msg;
}

# Info logger
sub info_log {
    my ($msg) = @_;
    my $timestamp = strftime("%Y-%m-%d %H:%M:%S", localtime);
    printf "[INFO][%s] %s\n", $timestamp, $msg;
}

info_log(sprintf("Starting NetPing Agent v%s (%s)", $VERSION, $AGENT_ID));

# Parse SERVER URL
$SERVER =~ m{^https://([^/]+)(/.*)$} or die "Invalid SERVER URL\n";
my ($host, $path) = ($1, $2);

# Fetch monitors from API
debug_log("Fetching monitors from API...");

my $ssl = IO::Socket::SSL->new(
    PeerHost => $host,
    PeerPort => 443,
    SSL_version => 'SSLv3',
    SSL_cipher_list => 'AES256-SHA',
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

# Extract JSON from the response
$body =~ s/^.*?(\{.*\}).*$/$1/s;

debug_log("Extracted JSON: $body");

my $data = eval { decode_json($body) };
die "Invalid JSON response from API: $@\n" if $@;
die "Invalid response format from API\n" unless ref $data eq 'HASH';
die "No monitors array in API response\n" unless ref $data->{monitors} eq 'ARRAY';

my @hosts = @{$data->{monitors}};
info_log("Processing " . scalar(@hosts) . " monitors");

# Process monitors
my $MAX_PROCESSES = min(120, scalar(@hosts));
my $current_processes = 0;
my @results;

# Set up child reaper
$SIG{CHLD} = sub {
    while ((my $pid = waitpid(-1, 0)) > 0) {
        $current_processes--;
    }
};

foreach my $monitor (@hosts) {
    # Basic validation
    next unless $monitor->{id} && $monitor->{address};

    # Wait if we've hit the process limit
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

# Collect results
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

# Submit results if we have any
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

    # Split results into chunks of 100
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

sub ping {
    my $monitor = shift;

    my $protocol = lc($monitor->{protocol} || 'icmp');
    my $port = $monitor->{port} || 0;
    my $dscp = $monitor->{dscp} || 'BE';
    my $tos = $DSCP_MAP->{$dscp} || 0x00;
    my $count = min(5, $monitor->{pollcount} || 5);

    my $p;
    if ($monitor->{address} =~ /:/) {
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
        last if $consecutive_fails >= 3;

        my ($success, $rtt) = $p->ping($monitor->{address});
        if ($success) {
            $rtt *= 1000;  # Convert to milliseconds
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

        # Calculate median
        my $median;
        if ($success % 2 == 0) {
            $median = ($rtts[$success/2 - 1] + $rtts[$success/2]) / 2;
        } else {
            $median = $rtts[int($success/2)];
        }

        # Calculate standard deviation
        my $mean = sum(@rtts) / $success;
        my $variance = sum(map { ($_ - $mean) ** 2 } @rtts) / $success;
        my $stddev = sqrt($variance);

        return ($loss, $median, $min, $max, $stddev);
    }

    return (100, 0, 0, 0, 0);
}

sub sum {
    my $sum = 0;
    $sum += $_ for @_;
    return $sum;
}

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

    my $ssl = IO::Socket::SSL->new(
        PeerHost => $host,
        PeerPort => 443,
        SSL_version => 'SSLv3',
        SSL_cipher_list => 'AES256-SHA',
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

    # Extract JSON from the response
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
