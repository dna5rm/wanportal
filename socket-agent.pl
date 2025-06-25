#!/usr/bin/env perl

use strict;
use warnings;
use Socket qw(
    getaddrinfo getnameinfo NI_NUMERICHOST AI_NUMERICHOST
    AF_INET AF_INET6 SOCK_RAW SOCK_STREAM
    AI_ADDRCONFIG AI_V4MAPPED
    IPPROTO_ICMP IPPROTO_ICMPV6 IPPROTO_TCP IPPROTO_IP IPPROTO_IPV6
    IP_TOS IPV6_TCLASS
    SOL_SOCKET SO_ERROR TCP_NODELAY
    SO_RCVTIMEO SO_SNDTIMEO
    pack_sockaddr_in pack_sockaddr_in6 unpack_sockaddr_in unpack_sockaddr_in6
    inet_aton inet_ntoa inet_ntop inet_pton
);
use Errno qw(EINPROGRESS);
use Fcntl qw(F_GETFL F_SETFL O_NONBLOCK);
use LWP::UserAgent;
use IO::Socket::SSL qw(SSL_VERIFY_NONE);
use JSON qw(decode_json encode_json);
use Time::HiRes qw(time usleep gettimeofday);
use Parallel::ForkManager;
use POSIX qw(strftime);
use Getopt::Long;
use Data::Dumper;
use List::Util qw(sum min max);
use Sys::CPU;

our $VERSION = '2.0.0';

# DSCP to TOS mapping
use constant {
    DSCP_MAP => {
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
    }
};

# Command line options
my $debug = 0;
my $help = 0;
my $max_processes = Sys::CPU::cpu_count() * 4;

GetOptions(
    "debug|d"     => \$debug,
    "help|h"      => \$help,
) or die "Error in command line arguments\n";

if ($help) {
    print_usage();
    exit 0;
}

# Check for root privileges
die "This script must be run as root for raw socket access\n" unless $> == 0;

# Configuration
my %CONFIG = (
    AGENT_ID        => $ENV{AGENT_ID}      || die("ERROR: AGENT_ID env not set\n"),
    PASSWORD        => $ENV{PASSWORD}      || die("ERROR: PASSWORD env not set\n"),
    API_SERVER      => $ENV{SERVER}        || die("ERROR: SERVER env not set\n"),
    DEFAULT_TIMEOUT => $ENV{PING_TIMEOUT}  || 5,
    DEFAULT_SIZE    => $ENV{PING_SIZE}     || 56,
    ICMP_ID        => $$,                  # Use process ID for ICMP identifier
);

# Debug logger
sub debug_log {
    return unless $debug;
    my ($msg) = @_;
    my $timestamp = strftime("%Y-%m-%d %H:%M:%S", localtime);
    printf STDERR "[DEBUG][%s] %s\n", $timestamp, $msg;
    STDERR->flush();
}

# Info logger
sub info_log {
    my ($msg) = @_;
    my $timestamp = strftime("%Y-%m-%d %H:%M:%S", localtime);
    printf "[INFO][%s] %s\n", $timestamp, $msg;
    STDOUT->flush();
}

# Resolve hostname to IP address
sub resolve_host {
    my ($hostname) = @_;
    debug_log("Resolving hostname: $hostname");
    
    my ($err, @res) = getaddrinfo(
        $hostname, "", 
        {
            flags => AI_ADDRCONFIG | AI_V4MAPPED,
            socktype => SOCK_RAW
        }
    );
    
    if ($err) {
        debug_log("Failed to resolve $hostname: $err");
        return;
    }

    foreach my $r (@res) {
        my ($err, $host) = getnameinfo($r->{addr}, NI_NUMERICHOST);
        next if $err;
        debug_log("Resolved $hostname to $host");
        return ($host, $r->{family} == AF_INET6, $r->{family}, $r->{addr});
    }
    
    return;
}

# Calculate checksum for ICMP packets
sub checksum {
    my ($data) = @_;
    my $sum = 0;
    my @words = unpack("n*", $data . "\0" x (length($data) % 2));
    foreach (@words) {
        $sum += $_;
        $sum = ($sum >> 16) + ($sum & 0xffff) if $sum > 0xffff;
    }
    return ~$sum & 0xffff;
}

# Create ICMP/ICMPv6 packet
sub create_icmp_packet {
    my ($id, $seq, $is_ipv6) = @_;
    
    my $type = $is_ipv6 ? 128 : 8;  # Echo Request (8 for IPv4, 128 for IPv6)
    my $code = 0;
    my $checksum = 0;
    my $data = "NetPing" . "0" x 48;  # Pad to DEFAULT_SIZE
    
    my $packet = pack("CCnnn a56", $type, $code, $checksum, $id, $seq, $data);
    $checksum = checksum($packet);
    return pack("CCnnn a56", $type, $code, $checksum, $id, $seq, $data);
}

# Create socket address structure
sub create_sockaddr {
    my ($port, $addr, $family) = @_;
    
    if ($family == AF_INET6) {
        return pack_sockaddr_in6($port, $addr);
    } else {
        my $packed_ip = inet_aton($addr);
        return pack_sockaddr_in($port, $packed_ip);
    }
}

# Create and configure socket
sub create_raw_socket {
    my ($protocol, $tos, $family, $timeout) = @_;
    
    my $socket;
    if ($protocol eq 'ICMP') {
        my $proto = ($family == AF_INET6) ? IPPROTO_ICMPV6 : IPPROTO_ICMP;
        socket($socket, $family, SOCK_RAW, $proto)
            or die "Cannot create raw socket: $!";
    } elsif ($protocol eq 'TCP') {
        socket($socket, $family, SOCK_STREAM, IPPROTO_TCP)
            or die "Cannot create TCP socket: $!";
    } else {
        die "Unsupported protocol: $protocol";
    }

    # Set TOS/TCLASS
    if ($family == AF_INET6) {
        setsockopt($socket, IPPROTO_IPV6, IPV6_TCLASS, pack("I", $tos))
            or debug_log("Failed to set TCLASS: $!");
    } else {
        setsockopt($socket, IPPROTO_IP, IP_TOS, pack("I", $tos))
            or debug_log("Failed to set TOS: $!");
    }

    # Set socket timeout
    my $timeout_struct = pack('l!l!', int($timeout), ($timeout - int($timeout)) * 1_000_000);
    setsockopt($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout_struct)
        or debug_log("Failed to set receive timeout: $!");
    setsockopt($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout_struct)
        or debug_log("Failed to set send timeout: $!");

    return $socket;
}

# Perform ICMP ping
sub icmp_ping {
    my ($target, $tos, $timeout, $is_ipv6, $family, $addr) = @_;
    
    # Get the resolved IP address for comparison
    my ($resolved_ip, $is_ip_v6, $ip_family, $ip_addr) = resolve_host($target);
    unless ($resolved_ip) {
        debug_log("Could not resolve $target");
        return undef;
    }
    
    my $socket = create_raw_socket('ICMP', $tos, $family, $timeout);
    my $seq = int(rand(65536));
    my $packet = create_icmp_packet($CONFIG{ICMP_ID}, $seq, $is_ipv6);
    
    debug_log(sprintf("ICMP >> Target: %s [%s], ID: 0x%04x, Seq: %d", 
        $target, $resolved_ip, $CONFIG{ICMP_ID}, $seq));
    
    my $start_time = time();
    my $dest = create_sockaddr(0, $resolved_ip, $family);
    
    my $bytes_sent = send($socket, $packet, 0, $dest);
    unless (defined $bytes_sent && $bytes_sent == length($packet)) {
        debug_log(sprintf("Send failed: sent=%d, expected=%d, error=%s", 
            $bytes_sent // -1, length($packet), $!));
        close($socket);
        return undef;
    }
    
    debug_log(sprintf("Sent %d bytes to %s", $bytes_sent, $target));
    
    my $rin = '';
    vec($rin, fileno($socket), 1) = 1;
    
    while (time() - $start_time < $timeout) {
        my $rout = $rin;
        my $remaining = $timeout - (time() - $start_time);
        last if $remaining <= 0;
        
        debug_log("Waiting for response... (timeout in ${remaining}s)");
        
        my $nfound = select($rout, undef, undef, $remaining);
        if (!defined $nfound || $nfound == 0) {
            debug_log("Select timeout or error: $!");
            next;
        }
        
        my $response;
        my $from = recv($socket, $response, 1500, 0);
        unless ($from) {
            debug_log("Receive failed: $!");
            next;
        }
        
        my $resp_len = length($response);
        debug_log(sprintf("Received %d bytes", $resp_len));
        
        # For IPv4, parse IP header to get source address
        if (!$is_ipv6 && length($response) >= 20) {
            my ($ver_ihl, $tos, $len, $id, $frag, $ttl, $proto, $chk, $src, $dst) = 
                unpack('CCnnnCCnNN', substr($response, 0, 20));
            
            my $from_ip = join('.', unpack('C4', pack('N', $src)));
            
            # Compare against the resolved IP instead of hostname
            unless ($from_ip =~ /^(?:$resolved_ip)$/) {
                debug_log(sprintf("Received response from different IP: %s (resolved target was %s)", 
                    $from_ip, $resolved_ip));
                next;
            }
            
            debug_log(sprintf("Received response from %s [%s]", $target, $from_ip));
            
            # Dump first few bytes for debugging
            my $hex_dump = unpack("H*", substr($response, 0, 32));
            debug_log("Response hex dump: $hex_dump");
            
            my $icmp_header = substr($response, 20, 8);
            unless (length($icmp_header) == 8) {
                debug_log("Invalid ICMP header length");
                next;
            }
            
            my ($type, $code, $checksum, $recv_id, $recv_seq) = 
                unpack("CCnnn", $icmp_header);
            
            debug_log(sprintf(
                "ICMP << From=%s [%s] Type=%d, Code=%d, ID=0x%04x, Seq=%d (expected: ID=0x%04x, Seq=%d)",
                $target, $from_ip, $type, $code, $recv_id, $recv_seq, $CONFIG{ICMP_ID}, $seq
            ));
            
            # Check for error responses
            if ($type == 3) {  # Destination Unreachable
                debug_log("Received Destination Unreachable from $from_ip");
                close($socket);
                return undef;
            }
            if ($type == 11) {  # Time Exceeded
                debug_log("Received Time Exceeded from $from_ip");
                close($socket);
                return undef;
            }
            
            # Verify it's our echo reply
            if ($type == 0 && 
                $code == 0 && 
                $recv_id == $CONFIG{ICMP_ID} && 
                $recv_seq == $seq) {
                my $end_time = time();
                close($socket);
                return ($end_time - $start_time);
            }
            
            debug_log("ICMP validation failed");
            next;
        }
    }
    
    debug_log("Timeout waiting for response");
    close($socket);
    return undef;
}

# Perform TCP ping
sub tcp_ping {
    my ($target, $port, $tos, $timeout, $family, $addr) = @_;
    
    # Get the resolved IP address for comparison
    my ($resolved_ip, $is_ip_v6, $ip_family, $ip_addr) = resolve_host($target);
    unless ($resolved_ip) {
        debug_log("Could not resolve $target");
        return undef;
    }
    
    # Validate port
    unless ($port && $port > 0 && $port <= 65535) {
        debug_log("Invalid TCP port: $port");
        return undef;
    }
    
    debug_log(sprintf("TCP >> Target: %s [%s]:%d", $target, $resolved_ip, $port));
    
    my $socket = eval {
        create_raw_socket('TCP', $tos, $family, $timeout);
    };
    if ($@) {
        debug_log("Failed to create TCP socket: $@");
        return undef;
    }
    
    # Set TCP specific options
    setsockopt($socket, IPPROTO_TCP, TCP_NODELAY, pack("l", 1))
        or debug_log("Failed to set TCP_NODELAY: $!");
    
    # Set non-blocking mode
    my $flags = fcntl($socket, F_GETFL, 0)
        or do {
            debug_log("Cannot get socket flags: $!");
            close($socket);
            return undef;
        };
    
    fcntl($socket, F_SETFL, $flags | O_NONBLOCK)
        or do {
            debug_log("Cannot set non-blocking mode: $!");
            close($socket);
            return undef;
        };
    
    # Create proper socket address structure using resolved IP
    my $dest = create_sockaddr($port, $resolved_ip, $family);
    
    my $start_time = time();
    my $connect_result = connect($socket, $dest);
    my $connect_error = $!;
    
    # If connect() returns true immediately, something is wrong
    # (should return false in non-blocking mode)
    if ($connect_result) {
        debug_log("Unexpected immediate connection success");
        close($socket);
        return undef;
    }
    
    # Check if the error is what we expect (EINPROGRESS)
    unless ($! == EINPROGRESS) {
        debug_log("Connect failed immediately: $!");
        close($socket);
        return undef;
    }
    
    # Prepare for select
    my $wout = '';
    my $eout = $wout;
    vec($wout, fileno($socket), 1) = 1;
    vec($eout, fileno($socket), 1) = 1;
    
    # Wait for connection completion
    my ($wrote, $error);
    my $select_result = select(undef, $wrote = $wout, $error = $eout, $timeout);
    
    if (!defined $select_result) {
        debug_log("Select error: $!");
        close($socket);
        return undef;
    }
    
    if ($select_result == 0) {
        debug_log("Connection timed out");
        close($socket);
        return undef;
    }
    
    # Check for connection errors
    if (vec($error, fileno($socket), 1)) {
        debug_log("Socket error condition set");
        close($socket);
        return undef;
    }
    
    if (vec($wrote, fileno($socket), 1)) {
        # Get socket error status
        my $error = getsockopt($socket, SOL_SOCKET, SO_ERROR);
        if (!defined $error) {
            debug_log("Failed to get socket error status");
            close($socket);
            return undef;
        }
        
        my $errno = unpack("I", $error);
        if ($errno != 0) {
            debug_log(sprintf("Connection failed: %s (errno: %d)", 
                $errno ? $! : "Unknown error", $errno));
            close($socket);
            return undef;
        }
        
        # Connection successful - verify the peer address
        my $peer_name = getpeername($socket);
        if ($peer_name) {
            my ($peer_port, $peer_addr);
            if ($family == AF_INET6) {
                ($peer_port, $peer_addr) = unpack_sockaddr_in6($peer_name);
                $peer_addr = inet_ntop(AF_INET6, $peer_addr);
            } else {
                ($peer_port, $peer_addr) = unpack_sockaddr_in($peer_name);
                $peer_addr = inet_ntoa($peer_addr);
            }
            
            # Compare against the resolved IP
            unless ($peer_addr =~ /^(?:$resolved_ip)$/) {
                debug_log(sprintf("Connected to unexpected IP: %s (resolved target was %s)",
                    $peer_addr, $resolved_ip));
                close($socket);
                return undef;
            }
            
            debug_log(sprintf("TCP connection successful to %s [%s]:%d", 
                $target, $peer_addr, $port));
        }
        
        my $end_time = time();
        my $duration = $end_time - $start_time;
        
        close($socket);
        return $duration;
    }
    
    debug_log("Unexpected select result");
    close($socket);
    return undef;
}

# Main ping function
sub pinghost {
    my ($monitor) = @_;
    
    my $target = $monitor->{address};
    my $protocol = uc($monitor->{protocol} // 'ICMP');
    my $port = $monitor->{port} // 0;
    my $pollcount = $monitor->{pollcount} // 5;
    my $dscp = $monitor->{dscp} // 'BE';
    
    # Resolve target address
    my ($resolved_addr, $is_ipv6, $family, $addr) = resolve_host($target);
    unless ($resolved_addr) {
        debug_log("Could not resolve $target");
        return (100, []);
    }
    
    debug_log(sprintf(
        "Pinging %s [%s] [protocol=%s%s, count=%d, DSCP=%s]",
        $target,
        $resolved_addr,
        $protocol,
        $protocol eq 'TCP' ? ":$port" : "",
        $pollcount,
        $dscp
    ));
    
    my $tos = DSCP_MAP->{$dscp} // 0x00;
    debug_log(sprintf("Using %s value: 0x%02x", 
        $is_ipv6 ? "TCLASS" : "TOS", $tos));
    
    my @rtts;
    my $sent = 0;
    my $received = 0;
    
    for my $i (1 .. $pollcount) {
        $sent++;
        my $rtt;
        
        if ($protocol eq 'ICMP') {
            $rtt = icmp_ping($target, $tos, $CONFIG{DEFAULT_TIMEOUT}, 
                           $is_ipv6, $family, $addr);
        } elsif ($protocol eq 'TCP') {
            $rtt = tcp_ping($target, $port, $tos, $CONFIG{DEFAULT_TIMEOUT}, 
                          $family, $addr);
        }
        
        if (defined $rtt) {
            $received++;
            my $rtt_ms = $rtt * 1000;  # Convert to milliseconds
            push @rtts, $rtt_ms;
            debug_log(sprintf("Ping %d/%d successful: %.3f ms", 
                $i, $pollcount, $rtt_ms));
        } else {
            debug_log(sprintf("Ping %d/%d failed", $i, $pollcount));
        }
        
        # Small delay between pings
        usleep(10000) if $i < $pollcount;
    }
    
    my $loss = $sent > 0 ? (($sent - $received) / $sent) * 100 : 100;
    
    my $stats = calculate_stats($loss, \@rtts);
    
    if (@rtts) {
        debug_log(sprintf(
            "Ping summary for %s: sent=%d received=%d loss=%.1f%% min=%.3f avg=%.3f max=%.3f",
            $target, $sent, $received, $stats->{loss}, $stats->{min}, 
            ($stats->{min} + $stats->{max}) / 2, $stats->{max}
        ));
    } else {
        debug_log(sprintf(
            "Ping summary for %s: sent=%d received=%d loss=100%% (no successful pings)",
            $target, $sent, $received
        ));
    }
    
    return $stats;
}

# Calculate statistics
sub calculate_stats {
    my ($loss, $rtts) = @_;
    
    # If 100% loss or no RTTs, return all zeros except loss
    if ($loss == 100 || !@$rtts) {
        return {
            loss => $loss,
            median => 0,
            min => 0,
            max => 0,
            stddev => 0
        };
    }

    # Calculate stats from valid RTTs
    my @sorted = sort { $a <=> $b } @$rtts;
    my $count = @sorted;
    
    my $min = $sorted[0];
    my $max = $sorted[-1];
    my $median = $count % 2 ? 
        $sorted[int($count/2)] : 
        ($sorted[int($count/2)-1] + $sorted[int($count/2)]) / 2;
    
    my $sum = sum(@sorted);
    my $avg = $sum / $count;
    my $variance = 0;
    $variance += ($_-$avg)**2 for @sorted;
    my $stddev = sqrt($variance/$count);

    return {
        loss => $loss,
        median => $median,
        min => $min,
        max => $max,
        stddev => $stddev
    };
}

# Initialize HTTP client
sub init_http_client {
    debug_log("Initializing HTTP client...");
    $ENV{PERL_LWP_SSL_VERIFY_HOSTNAME} = 0;
    return LWP::UserAgent->new(
        timeout    => 30,
        ssl_opts   => {
            SSL_verify_mode => SSL_VERIFY_NONE,
            verify_hostname => 0,
        },
        agent      => "NetPing-Agent/$VERSION"
    );
}

# Fetch monitors from API
sub fetch_monitors {
    my $ua = shift;
    debug_log("Fetching monitors from API...");
    
    my $url = "$CONFIG{API_SERVER}/agent/$CONFIG{AGENT_ID}/monitors";
    my $body = encode_json({ password => $CONFIG{PASSWORD} });
    
    my $response = $ua->request(
        HTTP::Request->new(
            'GET',
            $url,
            ['Content-Type' => 'application/json'],
            $body
        )
    );
    
    debug_log("API response received: " . $response->status_line);
    die "API Error: " . $response->status_line . "\n" unless $response->is_success;
    
    my $data = decode_json($response->decoded_content);
    die "Server Error: $data->{message}\n"
        if $data->{status} && $data->{status} ne 'success';

    return $data->{monitors} || [];
}

# Submit results to API
sub submit_results {
    my ($ua, $results) = @_;
    debug_log("Submitting results to API...");
    
    my $payload = {
        password => $CONFIG{PASSWORD},
        results  => $results
    };
    
    debug_log("Submit payload:");
    debug_log(encode_json($payload));
    
    my $response = $ua->post(
        "$CONFIG{API_SERVER}/agent/$CONFIG{AGENT_ID}/monitors",
        'Content-Type' => 'application/json',
        Content => encode_json($payload)
    );
    
    die "Submit Error: " . $response->status_line . "\n" unless $response->is_success;
    
    my $data = decode_json($response->decoded_content);
    die "Server Error: $data->{message}\n"
        unless $data->{status} && $data->{status} eq 'success';
}

# Print usage information
sub print_usage {
    print <<EOF;
NetPing Agent v$VERSION
Usage: $0 [options]

Options:
  -d, --debug              Enable debug output
  -h, --help              Show this help message

Environment variables:
  AGENT_ID                Agent identifier
  PASSWORD               Authentication password
  SERVER                 API server URL
  PING_TIMEOUT           Ping timeout in seconds (default: 5)
  PING_SIZE              Ping packet size (default: 56)

Note: This script must be run as root for raw socket access.
The agent will automatically use optimal number of parallel processes
based on the number of CPU cores available ($max_processes processes).

Supports:
- IPv4 and IPv6 addresses
- Hostname resolution
- ICMP and TCP protocols
- DSCP/TOS/TCLASS settings
EOF
}

# Main execution
sub main {
    info_log("Starting NetPing Agent v$VERSION");
    info_log("Using $max_processes parallel processes");
    
    # Set up signal handlers
    $SIG{INT} = $SIG{TERM} = sub {
        info_log("Received shutdown signal, cleaning up...");
        exit 0;
    };
    
    my $start_time = time();
    my $ua = init_http_client();
    my $monitors = fetch_monitors($ua);
    
    if (!@$monitors) {
        info_log("No monitors assigned for agent $CONFIG{AGENT_ID}");
        return 0;
    }
    
    info_log(sprintf("Processing %d monitors", scalar(@$monitors)));
    
    my $pm = Parallel::ForkManager->new($max_processes);
    my @results;
    
    # Set up data collection from child processes
    $pm->run_on_finish(sub {
        my ($pid, $exit_code, $ident, $exit_signal, $core_dump, $data) = @_;
        
        if (defined $data) {
            push @results, $data;
            debug_log(sprintf(
                "Received results from child process %d for monitor %s",
                $pid, $data->{id}
            ));
        } else {
            debug_log("Child process $pid finished with no data");
        }
    });

    # Process each monitor in parallel
    foreach my $mon (@$monitors) {
        $pm->start and next;
        
        eval {
            debug_log("Processing monitor: " . $mon->{id});
            
            # Perform ping tests
            my $stats = pinghost($mon);
            
            # Add monitor ID to stats
            $stats->{id} = $mon->{id};
            
            debug_log(sprintf(
                "Monitor %s complete - Loss: %.1f%%, Median: %.3f ms",
                $mon->{id}, 
                $stats->{loss},
                $stats->{median}
            ));
            
            $pm->finish(0, $stats);
        };
        
        if ($@) {
            debug_log("Error processing monitor " . $mon->{id} . ": $@");
            $pm->finish(1);
        }
    }
    
    # Wait for all child processes to complete
    $pm->wait_all_children;
    
    # Submit results if we have any
    if (@results) {
        eval {
            submit_results($ua, \@results);
        };
        if ($@) {
            die "Failed to submit results: $@\n";
        }
    }
    
    my $duration = time() - $start_time;
    info_log(sprintf(
        "Completed processing %d monitors in %.2f seconds", 
        scalar(@$monitors), 
        $duration
    ));
    
    return 0;
}

# Execute main function with error handling
eval {
    exit main();
};

# Handle any unhandled exceptions
if ($@) {
    my $error = $@;
    debug_log("Fatal error: $error");
    die "Fatal error: $error\n";
}
