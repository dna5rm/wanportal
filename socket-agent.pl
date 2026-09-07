#!/usr/bin/env perl

=head1 NAME

socket-agent.pl - raw-socket ping agent with working DSCP marking

=head1 SYNOPSIS

    SERVER=https://wanportal.example.com PASSWORD=... AGENT_ID=... \
        /srv/socket-agent.pl [-d]

=head1 DESCRIPTION

This agent exists because Net::Ping's TOS support does not actually
work: whatever TOS value you hand it never reaches the packets, so DSCP
marking never gets on the wire. Here the monitor's DSCP name is mapped
to a TOS byte and set directly on the socket, IP_TOS for IPv4 and
IPV6_TCLASS for IPv6, which does work.

The pinging is done by hand on raw sockets. ICMP echo requests are
built with pack() (type 8 for IPv4, 128 for IPv6); TCP monitors are
timed with a non-blocking connect and select(). Names are resolved to
numeric addresses first, and replies are checked against the numeric
address and the ICMP id and sequence, so stray packets are ignored.

ICMP reply matching works for both families. IPv4 raw reads carry the
IP header, which is parsed for the source address; ICMPv6 reads are
the bare echo packet, with echo replies at type 129 and the source
address taken from recv(). TCP probes work for both families too.

Raw sockets need root; the script refuses to run without it. Monitors
are fanned out over Parallel::ForkManager, four workers per CPU core
by default.

Dockerfile.agent ships netping-agent.pl; this is the raw-socket
variant for when monitors need their DSCP class honored on the wire.

SSL certificate verification is off on purpose: agents are allowed to
talk to a server with a self-signed certificate.

=head1 OPTIONS

-d, --debug    Verbose debug output on stderr.
-h, --help     Print the usage summary and exit.

=head1 ENVIRONMENT

SERVER        Base URL of the wanportal API. Required.
PASSWORD      Shared secret for this agent. Required.
AGENT_ID      Identifier assigned by the server. Required.
PING_TIMEOUT  Per-probe timeout in seconds. Default 5.
PING_SIZE     Echo payload size in bytes, clamped to 56..1400 so a
              probe never needs fragmentation. Default 56.

=head1 EXIT STATUS

Exits nonzero when run without root, when SERVER, PASSWORD or AGENT_ID
is missing, when the monitor fetch fails, or when result submission
fails. A monitor that cannot be probed is reported as 100% loss rather
than aborting the run.

=head1 SEE ALSO

netping-agent.pl - the shipped default agent (Net::Ping based)

netping-legacy.pl - the old-Perl fallback

=cut

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

# DSCP name to TOS byte; the same value serves as the IPv6 traffic
# class. The mapping only reaches the wire because the sockets below
# are raw and get it set directly. Net::Ping cannot do this.
use constant {
    DSCP_MAP => {
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
    }
};

# Options: -d/--debug, -h/--help. Workers default to four per core.
my $debug = 0;
my $help = 0;
my $max_processes = (Sys::CPU::cpu_count() // 1) * 4;

GetOptions(
    "debug|d"     => \$debug,
    "help|h"      => \$help,
) or die "Error in command line arguments\n";

if ($help) {
    print_usage();
    exit 0;
}

# Raw sockets need root; bail out early with a clear message.
die "This script must be run as root for raw socket access\n" unless $> == 0;

# Environment. The three API settings are required.
my %CONFIG = (
    AGENT_ID        => $ENV{AGENT_ID}      || die("ERROR: AGENT_ID env not set\n"),
    PASSWORD        => $ENV{PASSWORD}      || die("ERROR: PASSWORD env not set\n"),
    API_SERVER      => $ENV{SERVER}        || die("ERROR: SERVER env not set\n"),
    DEFAULT_TIMEOUT => $ENV{PING_TIMEOUT}  || 5,
    DEFAULT_SIZE    => $ENV{PING_SIZE}     || 56,
    ICMP_ID        => $$,                  # Use process ID for ICMP identifier
);

$CONFIG{AGENT_ID} =~ /\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/
    or die("ERROR: AGENT_ID must be a UUID (8-4-4-4-12 hex)\n");

# Log lines. debug_log stays quiet without -d/--debug and writes to
# stderr with explicit flushes so forked workers do not garble output.
sub debug_log {
    return unless $debug;
    my ($msg) = @_;
    my $timestamp = strftime("%Y-%m-%d %H:%M:%S", localtime);
    printf STDERR "[DEBUG][%s] %s\n", $timestamp, $msg;
    STDERR->flush();
}

sub info_log {
    my ($msg) = @_;
    my $timestamp = strftime("%Y-%m-%d %H:%M:%S", localtime);
    printf "[INFO][%s] %s\n", $timestamp, $msg;
    STDOUT->flush();
}

# Resolve a target to its numeric address. AI_V4MAPPED keeps v4
# answers alive on v6-capable stacks. Returns (ip, v6 flag, family,
# packed addr), or nothing when resolution fails.
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

# Standard Internet checksum: a 16-bit one's-complement sum.
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

# Build one echo request: type 8 for IPv4 or 128 for IPv6, with a
# "NetPing" marker plus zero padding. The payload is PING_SIZE bytes,
# clamped to 56..1400. The checksum is patched in after the first
# packing pass.
sub create_icmp_packet {
    my ($id, $seq, $is_ipv6) = @_;
    
    my $type = $is_ipv6 ? 128 : 8;  # echo request
    my $code = 0;
    my $checksum = 0;
    
    # PING_SIZE (DEFAULT_SIZE) now drives the payload: 56 bytes
    # minimum, clamped at ~1400 so a probe never needs fragmentation.
    my $size = $CONFIG{DEFAULT_SIZE};
    $size = 56 if $size < 56;
    $size = 1400 if $size > 1400;
    my $data = "NetPing" . "\0" x ($size - 7);  # marker + zero padding
    
    my $packet = pack("CCnnn a$size", $type, $code, $checksum, $id, $seq, $data);
    $checksum = checksum($packet);
    return pack("CCnnn a$size", $type, $code, $checksum, $id, $seq, $data);
}

# Pack (port, address, family) into the sockaddr form that send() and
# connect() expect. The address arrives as text (the numeric form
# getnameinfo hands back), so the v6 branch runs it through inet_pton
# to get the 16-byte binary pack_sockaddr_in6 requires; the v4 text
# form feeds inet_aton directly.
sub create_sockaddr {
    my ($port, $addr, $family) = @_;

    if ($family == AF_INET6) {
        return pack_sockaddr_in6($port, inet_pton(AF_INET6, $addr));
    } else {
        my $packed_ip = inet_aton($addr);
        return pack_sockaddr_in($port, $packed_ip);
    }
}

# Open the socket for the protocol and stamp TOS/TCLASS and timeouts
# on it. This is where DSCP marking actually happens.
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

    # SO_RCVTIMEO / SO_SNDTIMEO take a struct timeval: seconds plus
    # microseconds.
    my $timeout_struct = pack('l!l!', int($timeout), ($timeout - int($timeout)) * 1_000_000);
    setsockopt($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout_struct)
        or debug_log("Failed to set receive timeout: $!");
    setsockopt($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout_struct)
        or debug_log("Failed to set send timeout: $!");

    return $socket;
}

# One ICMP round trip. The echo request goes out, then select() and
# recv() until the timeout runs out. Reply matching (source address,
# id, seq) works for both families: IPv4 raw reads carry the IP
# header, ICMPv6 reads are the bare echo packet. Returns the RTT in
# seconds, or undef on failure.
sub icmp_ping {
    my ($target, $tos, $timeout, $is_ipv6, $family, $addr) = @_;
    
    # Replies are matched against the numeric address, not the name: a
    # name can resolve to several addresses, and only one of them is
    # ours.
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
        
        # On IPv4 a raw ICMP read includes the IP header; parse it to
        # get the source address.
        if (!$is_ipv6 && length($response) >= 20) {
            my ($ver_ihl, $tos, $len, $id, $frag, $ttl, $proto, $chk, $src, $dst) = 
                unpack('CCnnnCCnNN', substr($response, 0, 20));
            
            my $from_ip = join('.', unpack('C4', pack('N', $src)));
            
            unless ($from_ip =~ /^(?:$resolved_ip)$/) {
                debug_log(sprintf("Received response from different IP: %s (resolved target was %s)", 
                    $from_ip, $resolved_ip));
                next;
            }
            
            debug_log(sprintf("Received response from %s [%s]", $target, $from_ip));
            
            # First bytes as hex, purely for debug eyes.
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
            
            # Destination-unreachable and time-exceeded are hard
            # failures, not packets to wait past.
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
            
            # Echo reply with our id and sequence: this is the one.
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
    
        # On IPv6 a raw ICMPv6 read is the echo packet alone, no IPv6
        # header: echo reply is type 129 at offset 0. The source
        # address comes back in $from from recv().
        elsif ($is_ipv6) {
            unless (length($response) >= 8) {
                debug_log("Short ICMPv6 packet: $resp_len bytes");
                next;
            }
    
            my ($from_port, $from_bin) = unpack_sockaddr_in6($from);
            my $from_ip = inet_ntop(AF_INET6, $from_bin);
    
            unless ($from_ip =~ /^(?:$resolved_ip)$/) {
                debug_log(sprintf("Received response from different IP: %s (resolved target was %s)",
                    $from_ip, $resolved_ip));
                next;
            }
    
            my ($type, $code, $checksum, $recv_id, $recv_seq) =
                unpack("CCnnn", substr($response, 0, 8));
    
            debug_log(sprintf(
                "ICMPv6 << From=%s [%s] Type=%d, Code=%d, ID=0x%04x, Seq=%d (expected: ID=0x%04x, Seq=%d)",
                $target, $from_ip, $type, $code, $recv_id, $recv_seq, $CONFIG{ICMP_ID}, $seq
            ));
    
            # Destination-unreachable and time-exceeded are hard
            # failures, same as the IPv4 types handled above.
            if ($type == 1) {  # Destination Unreachable
                debug_log("Received ICMPv6 Destination Unreachable from $from_ip");
                close($socket);
                return undef;
            }
            if ($type == 3) {  # Time Exceeded
                debug_log("Received ICMPv6 Time Exceeded from $from_ip");
                close($socket);
                return undef;
            }
    
            # Echo reply with our id and sequence: this is the one.
            if ($type == 129 &&
                $code == 0 &&
                $recv_id == $CONFIG{ICMP_ID} &&
                $recv_seq == $seq) {
                my $end_time = time();
                close($socket);
                return ($end_time - $start_time);
            }
    
            debug_log("ICMPv6 validation failed");
            next;
        }
    }
    
    debug_log("Timeout waiting for response");
    close($socket);
    return undef;
}

# One timed TCP connect: non-blocking connect(), select() for
# writability, then SO_ERROR to find out how the handshake ended.
# Returns the elapsed time in seconds, or undef on failure.
sub tcp_ping {
    my ($target, $port, $tos, $timeout, $family, $addr) = @_;
    
    # Resolve to the numeric address; the peer check below compares
    # against it, same as icmp_ping.
    my ($resolved_ip, $is_ip_v6, $ip_family, $ip_addr) = resolve_host($target);
    unless ($resolved_ip) {
        debug_log("Could not resolve $target");
        return undef;
    }
    
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
    
    # TCP tuning: disable Nagle.
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
    
    # connect() against the numeric address so the peer check below is
    # exact.
    my $dest = create_sockaddr($port, $resolved_ip, $family);
    
    my $start_time = time();
    my $connect_result = connect($socket, $dest);
    my $connect_error = $!;
    
    # On a non-blocking socket connect() usually fails with EINPROGRESS
    # while the handshake runs, but it can also complete immediately
    # (loopback targets do this). True is a success only when SO_ERROR
    # is 0, which tcp_connected() verifies.
    if ($connect_result) {
        return tcp_connected($socket, $resolved_ip, $target, $port,
                             $family, $start_time);
    }
    
    unless ($! == EINPROGRESS) {
        debug_log("Connect failed immediately: $!");
        close($socket);
        return undef;
    }
    
    my $wout = '';
    my $eout = $wout;
    vec($wout, fileno($socket), 1) = 1;
    vec($eout, fileno($socket), 1) = 1;
    
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
        # SO_ERROR, peer check and timing are shared with the
        # immediate-success path above.
        return tcp_connected($socket, $resolved_ip, $target, $port,
                             $family, $start_time);
    }
    
    debug_log("Unexpected select result");
    close($socket);
    return undef;
}

# Shared tail of tcp_ping for both connect paths (immediate and
# select-driven): ask SO_ERROR how the handshake ended, and only call
# it a success when it is 0 and the kernel connected where we aimed.
# Returns the elapsed time in seconds, or undef on failure.
sub tcp_connected {
    my ($socket, $resolved_ip, $target, $port, $family, $start_time) = @_;
    
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
    
    # Confirm the kernel connected where we aimed before trusting the
    # result.
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

# Probe one monitor pollcount times and roll up the numbers. Returns
# the stats hashref; the caller tags the monitor id on.
sub pinghost {
    my ($monitor) = @_;
    
    my $target = $monitor->{address};
    my $protocol = uc($monitor->{protocol} // 'ICMP');
    my $port = $monitor->{port} // 0;
    my $pollcount = $monitor->{pollcount} // 5;
    my $dscp = $monitor->{dscp} // 'BE';
    
    # Resolve once up front; the probe subs match replies against it.
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
        
        # 10ms breather between probes.
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

# Roll loss/median/min/max/stddev up from the collected RTTs.
sub calculate_stats {
    my ($loss, $rtts) = @_;
    
    # Nothing came back: zeros across the board except loss.
    if ($loss == 100 || !@$rtts) {
        return {
            loss => $loss,
            median => 0,
            min => 0,
            max => 0,
            stddev => 0
        };
    }

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

# LWP client. SSL certificate verification is off on purpose: agents
# are allowed to talk to a server with a self-signed certificate.
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
    
    # Never debug_log the plaintext shared secret: log a masked copy.
    my $log_payload = { %$payload };
    $log_payload->{password} = 'REDACTED';
    debug_log("Submit payload (password redacted):");
    debug_log(encode_json($log_payload));
    
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

# Fetch the monitors, fan them out over the ForkManager pool, collect
# the results and submit them.
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
    
    # Children hand their stats back through run_on_finish.
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

    foreach my $mon (@$monitors) {
        $pm->start and next;
        
        eval {
            debug_log("Processing monitor: " . $mon->{id});
            
            my $stats = pinghost($mon);
            
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
    
    $pm->wait_all_children;
    
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

# Run main(); anything it fails to catch dies here.
eval {
    exit main();
};

if ($@) {
    my $error = $@;
    debug_log("Fatal error: $error");
    die "Fatal error: $error\n";
}
