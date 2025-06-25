#!/usr/bin/env perl

use strict;
use warnings;
use Net::Ping;
use Time::HiRes qw(sleep time);
use LWP::UserAgent;
use JSON qw(decode_json encode_json);
use IO::Socket::SSL qw(SSL_VERIFY_NONE);
use POSIX qw(strftime WNOHANG);
use List::Util qw(min);

our $VERSION = '0.1.0';

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

# Initialize HTTP client with better timeout handling
my $ua = LWP::UserAgent->new(
    timeout => 30,
    max_redirect => 0,
    ssl_opts => {
        SSL_verify_mode => SSL_VERIFY_NONE,
        verify_hostname => 0,
    },
    agent => "NetPing-Agent/$VERSION",
    keep_alive => 1  # Enable keep-alive connections
);

# Fetch monitors from API
debug_log("Fetching monitors from API...");
my $response = $ua->get(
    "$SERVER/agent/$AGENT_ID/monitors",
    'Content-Type' => 'application/json',
    'Content' => encode_json({ password => $PASSWORD })
);

die "API Error: " . $response->status_line . "\n" unless $response->is_success;

my $data = eval { decode_json($response->decoded_content) };
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
    while ((my $pid = waitpid(-1, WNOHANG)) > 0) {
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
        my ($loss, $rtt) = ping($monitor);
        printf $writer "%s %.1f %s\n", $monitor->{id}, $loss, $rtt;
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
        my ($id, $loss, $rtt) = split(/\s+/, $line);
        push @final_results, {
            id => $id,
            loss => $loss + 0,
            median => $rtt eq 'U' ? 0 : ($rtt + 0),
            min => $rtt eq 'U' ? 0 : ($rtt + 0),
            max => $rtt eq 'U' ? 0 : ($rtt + 0),
            stddev => 0
        };
    }
}

# Submit results if we have any
if (@final_results) {
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
                my $submit_response = $ua->post(
                    "$SERVER/agent/$AGENT_ID/monitors",
                    'Content-Type' => 'application/json',
                    timeout => 30,  # Increased timeout
                    Content => encode_json({
                        password => $PASSWORD,
                        results => \@chunk
                    })
                );

                if ($submit_response->is_success) {
                    my $result = decode_json($submit_response->decoded_content);
                    if ($result->{status} eq 'success') {
                        $submitted = 1;
                        debug_log("Successfully submitted chunk");
                    } else {
                        die "API Error: " . ($result->{message} // 'Unknown error');
                    }
                } else {
                    die "Submit Error: " . $submit_response->status_line . "\n" .
                        "Response content: " . $submit_response->decoded_content;
                }
            };
            
            if ($@) {
                debug_log("Submission attempt failed: $@");
                if ($attempt == $retry_count) {
                    info_log("Failed to submit chunk after $retry_count attempts");
                    $success = 0;
                }
            }
        }

        # Small delay between chunks
        sleep(1) if @final_results;
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
    my $tos = $DSCP_MAP->{$dscp} // 0x00;
    my $count = min(5, $monitor->{pollcount} || 5);
    
    # Add IPv6 support by using the appropriate ping type
    my $p;
    if ($monitor->{address} =~ /:/) {
        $p = Net::Ping->new('icmpv6', 1, 56, undef, $tos);
    } else {
        $p = Net::Ping->new($protocol, 1, 56, 0, $tos);
    }
    
    if ($protocol eq "tcp" || $protocol eq "udp") {
        $p->{port_num} = $port;
    }
    $p->hires(1);
    
    my ($success, $total_rtt) = (0, 0);
    my $consecutive_fails = 0;
    
    for my $i (1..$count) {
        last if $consecutive_fails >= 3;
        
        my @result = $p->ping($monitor->{address});
        if ($result[0]) {
            my $rtt = sprintf("%.3f", $result[1]) * 1000;
            $success++;
            $total_rtt += $rtt;
            $consecutive_fails = 0;
        } else {
            $consecutive_fails++;
        }
        sleep(0.1) if $i < $count;
    }
    
    $p->close;
    
    my $loss = $success ? (($count - $success) / $count * 100) : 100;
    my $avg_rtt = $success ? sprintf("%.0f", $total_rtt / $success) : "U";
    
    return ($loss, $avg_rtt);
}
