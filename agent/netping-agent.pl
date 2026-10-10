#!/usr/bin/env perl

=head1 NAME

netping-agent.pl - wanportal remote ping agent

=head1 SYNOPSIS

    SERVER=https://wanportal.example.com PASSWORD=... AGENT_ID=... \
        /srv/agent/netping-agent.pl

=head1 DESCRIPTION

This is the default remote agent. It fetches the monitor list for its
AGENT_ID from the wanportal API, forks a child per monitor to ping it,
and posts the results back in chunks of 100. Each chunk gets up to
three submission attempts; chunks that still fail are logged and
dropped, and the agent exits normally.

Each monitor is probed at most five times (pollcount can lower that)
with ICMP by default, or TCP/UDP when the monitor calls for it. An
address containing a colon is pinged with icmpv6 for ICMP monitors;
TCP and UDP monitors keep their own protocol on IPv6 addresses. The
monitor's DSCP name maps to a TOS byte for Net::Ping, and unrecognized
names fall back to 0, but Net::Ping's TOS support does not reliably
mark packets: monitors requesting a DSCP other than Best Effort probe
without dependable marking and log a warning pointing at
socket-agent.pl, which implements DSCP/TOS with raw sockets. Probing
stops early after three misses in a row. Results carry loss percent,
median, min, max and standard deviation.

SSL certificate verification is off on purpose: agents are allowed to
talk to a server with a self-signed certificate.

Alongside reachability the agent runs service checks: HTTP/S probes
with F5-style send/receive/disable assertions. After monitor results
are submitted it fetches /agent/:id/services, probes each service in
a forked child and posts the results in chunks of 100 with the same
three-attempt policy. HTTP children are capped at 20 concurrent
probes, well below the ping cap: an HTTP check can hold a child for
an entire timeout window against a foreign server. A service result
carries loss/median for the existing uptime + RRD model plus the HTTP
state, status code, a closed reason token and a short message.
Assertion strings and regexes match at most the first 256 KB of the
transfer-decoded body. If the portal does not answer the services
fetch (404 from an older portal), the agent logs it and finishes the
cycle; nothing in the services phase changes the exit status.

Both fetches and the services result POST carry a self-declaration in
their body: the agent announces supports_services and its version
(I3 - capability is self-declaring, never gating). The portal records
the declaration from the result POST and keeps its services read
safe; the fetches still announce it so an older portal that records
from a GET body is covered too. One announcement, two places, so
version skew is covered whichever side is older. The resolved auth
secret for a service is used only to build request headers; it is
never logged.

=head1 ENVIRONMENT

SERVER      Base URL of the wanportal API. Required.
PASSWORD    Shared secret for this agent. Required.
AGENT_ID    UUID assigned by the server (8-4-4-4-12 hex). Required.
DEBUG       Enables debug output on stdout. Optional.

=head1 EXIT STATUS

Exits nonzero when SERVER, PASSWORD or AGENT_ID is missing, when
AGENT_ID is not a UUID, when the monitor fetch fails, or when a child
cannot be forked. Failed result submissions are logged but do not
change the exit status.

The services phase is deliberately fail-soft (I6): a failed or 404
services fetch, a lost probe child or a failed service submission is
logged and skipped, and never changes the exit status.

=cut

use strict;
use warnings;
use Net::Ping;
use Time::HiRes qw(sleep time);
use LWP::UserAgent;
use JSON qw(decode_json encode_json);
use IO::Socket::SSL qw(SSL_VERIFY_NONE);
use POSIX qw(strftime WNOHANG);
use List::Util qw(min max sum);

our $VERSION = '0.2.0';

# Get environment variables
my $AGENT_ID = $ENV{AGENT_ID} || die("ERROR: AGENT_ID env not set\n");
# AGENT_ID is a server-assigned UUID that gets interpolated into API
# URLs; refuse anything else up front.
$AGENT_ID =~ /\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/
    or die("ERROR: AGENT_ID must be a UUID (8-4-4-4-12 hex)\n");
my $PASSWORD = $ENV{PASSWORD} || die("ERROR: PASSWORD env not set\n");
my $SERVER = $ENV{SERVER} || die("ERROR: SERVER env not set\n");
my $debug = $ENV{DEBUG} || 0;

# DSCP name to TOS byte (Net::Ping takes a TOS, not a DSCP code):
# TOS byte = DSCP value << 2.
my $DSCP_MAP = {
    'BE' => 0x00,    # Best Effort
    'EF' => 0xB8,    # Expedited Forwarding
    'AF11' => 0x28,  # Assured Forwarding 11
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

# HTTP client. SSL verification is off on purpose: agents may talk to a
# server with a self-signed certificate.
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
# Capability rides on the fetch the agent already makes (6.2). The
# monitor handler reads only the password key from the body, so these
# extra keys are recorded by the portal (or ignored), and the monitor
# cycle itself is unchanged (I1/I2); capability is declared, not gated
# (I3).
my $response = $ua->get(
    "$SERVER/agent/$AGENT_ID/monitors",
    'Content-Type' => 'application/json',
    'Content' => encode_json({
        password          => $PASSWORD,
        supports_services => 1,
        version           => $VERSION,
    })
);

die "API Error: " . $response->status_line . "\n" unless $response->is_success;

my $data = eval { decode_json($response->decoded_content) };
die "Invalid JSON response from API: $@\n" if $@;
die "Invalid response format from API\n" unless ref $data eq 'HASH';
die "No monitors array in API response\n" unless ref $data->{monitors} eq 'ARRAY';

my @hosts = @{$data->{monitors}};
info_log("Processing " . scalar(@hosts) . " monitors");

# One child per monitor, up to MAX_PROCESSES at a time. The count is
# owned by the parent: children are only counted after a successful
# fork in the parent, and the reaper only removes counted children.
# Counting in both places races (a child can exit between fork() and
# the parent counting it), so reaps that land before the count are
# reconciled when the parent counts the spawn.
my $MAX_PROCESSES = min(120, scalar(@hosts));
my %spawned;        # pid => 1 for children the parent has counted
my %reaped_early;   # pids reaped by the CHLD handler before being counted
my @results;

# Set up child reaper
$SIG{CHLD} = sub {
    while ((my $pid = waitpid(-1, WNOHANG)) > 0) {
        if (exists $spawned{$pid}) {
            delete $spawned{$pid};
        } else {
            $reaped_early{$pid} = 1;
        }
    }
};

foreach my $monitor (@hosts) {
    # Skip entries with no id or address.
    next unless $monitor->{id} && $monitor->{address};

    # Wait if we've hit the process limit
    while (scalar(keys %spawned) >= $MAX_PROCESSES) {
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
        $spawned{$pid} = 1;
        # The child already exited and was reaped before it was counted.
        delete $spawned{$pid} if delete $reaped_early{$pid};
        push @results, {
            pid => $pid,
            reader => $reader
        };
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
                my $submit_response = $ua->post(
                    "$SERVER/agent/$AGENT_ID/monitors",
                    'Content-Type' => 'application/json',
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
    }

    if ($success) {
        info_log("Successfully submitted all results");
    } else {
        info_log("Some submissions failed - check debug log for details");
    }
} else {
    info_log("No results to submit");
}

# Services run after the monitor cycle is fully submitted, as a second
# self-contained loop (6.3): own endpoint, own children, own books.
# Keeping them separate is the point of the design: the monitor path
# above is untouched code, which is what makes I1/I2 a structural
# guarantee rather than a behavior to keep proving. The eval is the
# last line of defense for the fail-soft rule (I6): a bug in this
# phase must log and leave, never turn a good monitor cycle into a
# failed agent run.
eval { run_services_cycle(); };
if ($@) {
    my $svc_err = "$@";
    $svc_err =~ s/[\r\n]+/ /g;
    info_log("Services cycle error: " . substr($svc_err, 0, 200));
}

exit 0;

sub ping {
    my $monitor = shift;
    
    my $protocol = lc($monitor->{protocol} || 'icmp');
    my $port = $monitor->{port} || 0;
    my $dscp = $monitor->{dscp} || 'BE';
    my $tos = $DSCP_MAP->{$dscp} // 0x00;
    my $count = min(5, $monitor->{pollcount} || 5);

    # Net::Ping's TOS support is broken: it does not reliably mark
    # packets, and a non-zero TOS on an icmpv6 socket croaks. Only
    # Best Effort is dependable here; socket-agent.pl builds raw ICMP
    # and can actually apply DSCP/TOS.
    if ($monitor->{dscp} && uc($dscp) ne 'BE') {
        warn "monitor $monitor->{id}: DSCP '$dscp' requested but Net::Ping TOS support is broken; probing without dependable DSCP marking - use socket-agent.pl when DSCP matters\n";
    }

    # IPv6 addresses use icmpv6 only for ICMP probes; TCP/UDP keep
    # their own protocol so the port-based check still applies.
    my $netproto = $protocol;
    $netproto = 'icmpv6' if $protocol eq 'icmp' && $monitor->{address} =~ /:/;

    my $p = Net::Ping->new($netproto, 1, 56, undef, $tos);

    if ($protocol eq "tcp" || $protocol eq "udp") {
        $p->{port_num} = $port;
    }
    $p->hires(1);
    
    my @rtts;
    my $consecutive_fails = 0;
    
    for my $i (1..$count) {
        # Three misses in a row: stop probing early.
        last if $consecutive_fails >= 3;
        
        my @result = $p->ping($monitor->{address});
        if ($result[0]) {
            my $rtt = sprintf("%.3f", $result[1]) * 1000;
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

# ---------------------------------------------------------------------------
# Services: HTTP/S checks with F5-style send/receive/disable assertions
# (6.3). This loop is deliberately separate from the monitor machinery
# above - the monitor path is what I1/I2 protect, and "untouched" is a
# stronger guarantee than "re-tested after a refactor", so services get
# their own endpoint, their own child cap and their own fork/collect/
# submit books. Anything failing in here is logged and skipped (I6):
# a services problem may never turn a working reachability cycle into
# a failed agent run.
# ---------------------------------------------------------------------------

sub run_services_cycle {
    my %svc_spawned;      # pid => 1 for children the parent has counted
    my %svc_reaped_early; # pids reaped by the CHLD handler before counting

    # Same reaper contract as the monitor loop: the parent counts only
    # forked children, and reaps that land before the count are
    # reconciled when the count happens. Scoped with `local` so the
    # monitor handler is restored untouched when this returns; monitors
    # are fully collected by now, so the two books never overlap.
    local $SIG{CHLD} = sub {
        while ((my $pid = waitpid(-1, WNOHANG)) > 0) {
            if (exists $svc_spawned{$pid}) {
                delete $svc_spawned{$pid};
            } else {
                $svc_reaped_early{$pid} = 1;
            }
        }
    };

    # Fetch the due services. The body still announces the capability
    # (same keys as the monitor fetch, 6.2): an older portal records
    # it from this GET, the newer portal records it from the result
    # POST below and keeps this read safe. Both are sent so version
    # skew in either direction is covered. A 404 here is an older
    # portal (I2/I6):
    # the endpoint's existence is the version-skew gate, so absence
    # means "no service contract", not an error. Log and skip - dying
    # would fail a reachability cycle that is working fine.
    debug_log("Fetching services from API...");
    my $svc_response = $ua->get(
        "$SERVER/agent/$AGENT_ID/services",
        'Content-Type' => 'application/json',
        'Content' => encode_json({
            password          => $PASSWORD,
            supports_services => 1,
            version           => $VERSION,
        })
    );

    unless ($svc_response->is_success) {
        info_log("Services fetch failed (" . $svc_response->status_line .
            "); skipping services this cycle");
        return;
    }

    my $svc_data = eval { decode_json($svc_response->decoded_content) };
    unless (ref $svc_data eq 'HASH' && ref $svc_data->{services} eq 'ARRAY') {
        info_log("No services array in API response; skipping services");
        return;
    }

    my @services = @{$svc_data->{services}};
    info_log("Processing " . scalar(@services) . " services");
    return unless @services;

    # At most 20 concurrent HTTP children (11). Deliberately not the
    # ping cap: an HTTP check blocks on a foreign server and can hold a
    # child for a whole timeout window, so unbounded fanout would stack
    # up 120 wedged probes per cycle. A single small queue keeps this
    # phase predictable, and it shares nothing with the ping cap anyway.
    my $svc_max = min(20, scalar(@services));

    # One child per service, same ownership rules as the monitor loop.
    my @svc_probes;

    foreach my $service (@services) {
        # Skip entries that cannot name a check or a host.
        my $svc_host = $service->{address} // $service->{target_address} // '';
        unless ($service->{id} && $svc_host) {
            debug_log("Skipping service with no id or host: " .
                ($service->{id} // '(no id)'));
            next;
        }

        # The portal's due gate delivers only active services. If an
        # inactive row ever arrives, not probing is the honest answer: a
        # check the operator disabled must stay UNKNOWN rather than show
        # a possibly stale verdict, and the closed reason set (7) has
        # no token for an agent-side "disabled here".
        if (defined $service->{is_active} && !$service->{is_active}) {
            debug_log("Skipping inactive service " . ($service->{id} // '?'));
            next;
        }

        # Wait if we've hit the process limit
        while (scalar(keys %svc_spawned) >= $svc_max) {
            sleep(0.01);
        }

        pipe(my $reader, my $writer) or do {
            info_log("Pipe failed for service " .
                ($service->{id} // '?') . ": $!; skipping it");
            next;
        };

        my $pid = fork();
        if (!defined $pid) {
            # Not the monitor path's die: monitor results are already
            # submitted by now, and one lost fork cycle costs a few
            # service polls, not the run (I6).
            close $reader; close $writer;
            info_log("Fork failed: $!; stopping service probes this cycle");
            last;
        } elsif ($pid == 0) { # Child
            close $reader;
            # LWP enforces the service timeout, but a child must never
            # wedge holding the pipe: the alarm backstop converts a
            # stuck request into a timeout result the parent can read.
            # Without it a wedged child would hang the collect loop.
            my $svc_watch = ($service->{timeout} && $service->{timeout} > 0
                ? $service->{timeout} : 10) + 30;
            $svc_watch = 150 if $svc_watch > 150;
            local $SIG{ALRM} = sub {
                eval {
                    print {$writer} encode_json({
                        id          => $service->{id},
                        loss        => 100,
                        median      => 0,
                        state       => 'DOWN',
                        status_code => 0,
                        reason      => 'timeout',
                        message     => 'probe watchdog hit; request aborted',
                    }), "\n";
                };
                close $writer;
                exit 0;
            };
            alarm($svc_watch);

            # A probe that dies still reports: a missing line would hang
            # the parent's collect loop and silently drop the service.
            my $probe = eval { http_probe($service) };
            unless (ref $probe eq 'HASH') {
                $probe = {
                    id          => $service->{id},
                    loss        => 100,
                    median      => 0,
                    state       => 'DOWN',
                    status_code => 0,
                    reason      => 'transport_error',
                    message     => svc_safe_message($service,
                        "probe exception: $@"),
                };
            }
            alarm(0);
            print {$writer} encode_json($probe), "\n";
            close $writer;
            exit 0;
        } else { # Parent
            close $writer;
            $svc_spawned{$pid} = 1;
            # The child already exited and was reaped before it was
            # counted (same race as the monitor loop).
            delete $svc_spawned{$pid} if delete $svc_reaped_early{$pid};
            push @svc_probes, { pid => $pid, reader => $reader };
        }
    }

    # Collect: one JSON line per child. A service result has more fields
    # than a monitor's space-split line, so JSON it is. Children are
    # built to always emit one line (eval-wrapped probe plus the alarm
    # backstop), so a blocked readline here behaves exactly like the
    # monitor collect loop; a child killed before printing leaves EOF
    # and its service is skipped, never faked.
    my @svc_final;
    foreach my $probe (@svc_probes) {
        my $line = readline($probe->{reader});
        close $probe->{reader};

        next unless defined $line && $line =~ /\S/;
        chomp $line;
        my $result = eval { decode_json($line) };
        unless (ref $result eq 'HASH' && defined $result->{id}
            && length $result->{id}) {
            info_log("Discarding a malformed service result line");
            next;
        }
        push @svc_final, $result;
    }

    # Submit results if we have any
    if (@svc_final) {
        debug_log("Final Service Results:");
        debug_log(sprintf("  %-36s %7s %-8s %5s %-17s %s",
            "Service ID", "Loss%", "State", "Code", "Reason", "Message"));
        debug_log("  " . "-" x 100);

        foreach my $result (@svc_final) {
            my $msg = defined $result->{message} ? $result->{message} : '';
            debug_log(sprintf("  %-36s %6.0f%% %-8s %5.0f %-17s %s",
                $result->{id},
                $result->{loss} // 0,
                substr($result->{state} // '?', 0, 8),
                $result->{status_code} // 0,
                substr($result->{reason} // '?', 0, 17),
                $msg));
        }

        debug_log("Preparing to submit " . scalar(@svc_final) .
            " service results");

        # Same chunk/retry machinery as the monitor submissions, pointed
        # at the services endpoint: chunks of 100, three attempts each,
        # failures logged and dropped (unknown extra fields in the
        # results are ignored by the portal, per I6).
        #
        # The portal records the capability declaration from this POST
        # (6.2: a result submission is unambiguously agent traffic);
        # its services GET stayed read-only, so this is where
        # supports_services and version actually land. The GETs still
        # announce both for older portals that read a GET body - see
        # the services fetch above.
        my $chunk_size  = 100;
        my $retry_count = 3;
        my $retry_delay = 2;
        my $success     = 1;

        while (my @chunk = splice(@svc_final, 0, $chunk_size)) {
            debug_log("Processing chunk of " . scalar(@chunk) .
                " service results");

            my $attempt   = 0;
            my $submitted = 0;
            while ($attempt < $retry_count && !$submitted) {
                $attempt++;
                if ($attempt > 1) {
                    debug_log("Service retry attempt $attempt of $retry_count");
                    sleep($retry_delay);
                }
                eval {
                    my $submit_response = $ua->post(
                        "$SERVER/agent/$AGENT_ID/services",
                        'Content-Type' => 'application/json',
                        Content => encode_json({
                            password          => $PASSWORD,
                            supports_services => 1,
                            version           => $VERSION,
                            results           => \@chunk
                        })
                    );

                    if ($submit_response->is_success) {
                        my $result = eval {
                            decode_json($submit_response->decoded_content);
                        };
                        if (ref $result eq 'HASH'
                            && ($result->{status} // '') eq 'success') {
                            $submitted = 1;
                            debug_log("Successfully submitted service chunk");
                        } else {
                            die "API Error: " . (ref $result eq 'HASH'
                                ? ($result->{message} // 'Unknown error')
                                : 'Unexpected response');
                        }
                    } else {
                        die "Submit Error: " . $submit_response->status_line .
                            "\n" . "Response content: " .
                            $submit_response->decoded_content;
                    }
                };

                if ($@) {
                    # The rejected chunk text is the portal's own reply,
                    # not a secret carrier; logged exactly like the
                    # monitor submit path does.
                    debug_log("Service submission attempt failed: $@");
                    if ($attempt == $retry_count) {
                        info_log("Failed to submit a service chunk after " .
                            "$retry_count attempts");
                        $success = 0;
                    }
                }
            }
        }

        if ($success) {
            info_log("Successfully submitted all service results");
        } else {
            info_log("Some service submissions failed - check debug log for details");
        }
    } else {
        info_log("No service results to submit");
    }

    return;
}

# Probe one service over HTTP/S and return the 6.4 result: id, loss,
# median, state, status_code, reason, message. Never dies (the child
# eval-wraps it anyway) and never puts the resolved secret in a
# message or a log line.
sub http_probe {
    my ($service) = @_;

    my $down = sub {
        my ($reason, $message, $status_code) = @_;
        return {
            id          => $service->{id} // '',
            loss        => 100,
            median      => 0,
            state       => 'DOWN',
            status_code => defined $status_code ? $status_code : 0,
            reason      => $reason,
            message     => svc_safe_message($service, $message),
        };
    };

    # URL from scheme + target host + port + path (+ query). The host is
    # the target's (I8: services reference targets, never copy them) and
    # arrives in the payload as `address`. Port 0 means the scheme
    # default (80/443): the URL then carries no port and LWP applies the
    # default on its own.
    my $scheme = lc($service->{scheme} // 'http');
    my $host = $service->{address} // $service->{target_address} // '';

    unless ($scheme eq 'http' || $scheme eq 'https') {
        return $down->('transport_error', "unsupported scheme '$scheme'");
    }
    unless (length $host) {
        return $down->('transport_error', 'no target address in service payload');
    }

    # IPv6 literals need brackets, or the colon between host and port is
    # unreadable inside the URL.
    my $hostpart = $host =~ /:/ ? "[$host]" : $host;
    if ($service->{port} && $service->{port} =~ /\A\d+\z/ && $service->{port} > 0) {
        $hostpart .= ":$service->{port}";
    }

    my $uri_path = $service->{uri_path};
    $uri_path = '/' unless defined $uri_path && length $uri_path;
    $uri_path = "/$uri_path" unless $uri_path =~ m{\A/};

    my $uri_query = (defined $service->{uri_query} && length $service->{uri_query})
        ? "?$service->{uri_query}" : '';

    my $url = "$scheme://$hostpart$uri_path$uri_query";

    # TLS follows the service's own verify_tls flag (4): verification is
    # ON unless an operator explicitly disabled it for this check, and
    # the portal records that choice. Per-service SSL posture is separate
    # from the agent's own SSL_VERIFY_NONE connection to the portal.
    # SSL_verify_mode 1 is IO::Socket::SSL's SSL_VERIFY_PEER.
    my $verify_tls = defined $service->{verify_tls}
        ? ($service->{verify_tls} ? 1 : 0) : 1;
    my $svc_timeout = $service->{timeout};
    $svc_timeout = 10 unless defined $svc_timeout && $svc_timeout > 0;
    $svc_timeout = 120 if $svc_timeout > 120;

    my $probe_ua = LWP::UserAgent->new(
        timeout      => $svc_timeout,
        max_redirect => $service->{follow_redirects} ? 7 : 0,
        ssl_opts     => $verify_tls
            ? { SSL_verify_mode => 1,               verify_hostname => 1 }
            : { SSL_verify_mode => SSL_VERIFY_NONE, verify_hostname => 0 },
        agent => "NetPing-Agent/$VERSION",
    );

    # HTTP::Request rides in with LWP::UserAgent; no `use` line for it.
    my $method = uc($service->{http_method} // 'GET');
    $method =~ /\A[A-Z]+\z/
        or return $down->('transport_error', "bad HTTP method '$method'");
    my $request = HTTP::Request->new($method, $url);

    # http_headers is a json column: accept a hash or its raw JSON text,
    # ignore a bad set (fail-soft on skewed payloads). Names stay HTTP
    # tokens and values collapse to a single line - a CRLF in a user
    # value would otherwise split (and inject into) the request line.
    my %svc_headers;
    my $svc_headers_raw = $service->{http_headers};
    if (defined $svc_headers_raw && !ref $svc_headers_raw
        && length $svc_headers_raw) {
        $svc_headers_raw = eval { decode_json($svc_headers_raw) };
        if ($@ || ref $svc_headers_raw ne 'HASH') {
            debug_log("service " . ($service->{id} // '?') .
                ": http_headers is not valid JSON; headers ignored");
            $svc_headers_raw = undef;
        }
    }
    %svc_headers = %{$svc_headers_raw} if ref $svc_headers_raw eq 'HASH';

    foreach my $header_name (keys %svc_headers) {
        next unless defined $header_name && $header_name =~ /\A[A-Za-z0-9\-_]+\z/;
        my $header_value = $svc_headers{$header_name};
        next if ref $header_value;      # multi-value lists are ignored
        next unless defined $header_value && length $header_value;
        $header_value =~ s/[\r\n]+/ /g;
        $request->header($header_name => $header_value);
    }

    # Request body. send_string, when set, IS the body (F5's model, 4):
    # plain bytes, no default Content-Type. Otherwise body travels per
    # body_encoding; 'text' (or an unknown encoding from a skewed portal)
    # goes exactly as delivered and the assertions judge it.
    my $body;
    my $default_ct;
    my $encoding = lc($service->{body_encoding} // '');

    if (defined $service->{send_string} && length $service->{send_string}) {
        $body = _wire_bytes($service->{send_string});
    } elsif (defined $service->{body} && length $service->{body}) {
        if ($encoding eq 'json') {
            $body = _wire_bytes($service->{body}, 1);
            $default_ct = 'application/json';
        } elsif ($encoding eq 'form') {
            $body = _wire_bytes($service->{body});
            $default_ct = 'application/x-www-form-urlencoded';
        } elsif ($encoding eq 'base64') {
            # MIME::Base64 is core Perl, loaded with require rather than
            # use: tests/perl/agent_image_pkgs.t pins every `use` line in
            # this file to an apk package, and the Dockerfile is not a
            # file the agent script owns.
            require MIME::Base64;
            my $decoded = eval {
                MIME::Base64::decode_base64(_wire_bytes($service->{body}));
            };
            $body = defined $decoded ? $decoded : _wire_bytes($service->{body});
        } else {
            $body = _wire_bytes($service->{body});
        }

        # Encoding picks the default Content-Type unless the header map
        # already carries one; an explicit user header wins.
        if (defined $default_ct
            && !grep { lc($_) eq 'content-type' } keys %svc_headers) {
            $request->header('Content-Type' => $default_ct);
        }
    }
    $request->content($body) if defined $body;

    # Auth (5.4): the values the portal resolved and delivered. They
    # exist only to build request headers here and are used nowhere
    # else. No log line in this file ever prints a header name/value
    # pair, and any error text that might carry them goes through
    # svc_safe_message() first - the agent's log path must never emit
    # the resolved secret.
    my $auth_type   = lc($service->{auth_type} // '');
    my $auth_secret = $service->{auth_secret};

    if ($auth_type eq 'basic') {
        if (defined $auth_secret && length $auth_secret) {
            if ($auth_secret =~ /[\r\n]/
                || ($service->{auth_username} // '') =~ /[\r\n]/) {
                return $down->('auth_error',
                    'credential carries line breaks; not sent');
            }
            require MIME::Base64;   # core module; see the require note above
            my $auth_pair = _wire_bytes($service->{auth_username} // '') . ':'
                . _wire_bytes($auth_secret);
            $request->header(Authorization =>
                'Basic ' . MIME::Base64::encode_base64($auth_pair, ''));
        } else {
            # Lapsed credential (5.4): the portal answers the fetch
            # without the secret. Probe unauthenticated; the server's
            # 401/403 will land as auth_error below, which is the honest
            # report for a credential the portal refused to deliver.
            debug_log("service " . ($service->{id} // '?') .
                ": no basic secret delivered");
        }
    } elsif ($auth_type eq 'bearer') {
        if (defined $auth_secret && length $auth_secret) {
            return $down->('auth_error',
                'credential is not a printable single-line token; not sent')
                if $auth_secret =~ /[^\x20-\x7E]/;
            $request->header(Authorization => "Bearer $auth_secret");
        } else {
            debug_log("service " . ($service->{id} // '?') .
                ": no bearer secret delivered");
        }
    } elsif ($auth_type eq 'header') {
        my $header_name = $service->{auth_header_name};
        if (defined $auth_secret && length $auth_secret
            && defined $header_name && length $header_name) {
            # A header value cannot carry control bytes or non-ASCII:
            # refusing beats silently mangling the credential.
            return $down->('auth_error',
                'credential is not a printable single-line header value; not sent')
                if ($auth_secret . $header_name) =~ /[^\x20-\x7E]/;
            $request->header($header_name => $auth_secret);
        } else {
            debug_log("service " . ($service->{id} // '?') .
                ": no auth header name/secret delivered");
        }
    }

    # The request line is the only per-service debug output: method, URL
    # and knobs. Header values are deliberately absent - Authorization
    # and any auth-carrying custom header must never reach the log.
    debug_log(sprintf(
        "Probing service %s: %s %s (timeout=%ds verify_tls=%d redirects=%s)",
        $service->{id} // '?', $method, $url, $svc_timeout, $verify_tls,
        $service->{follow_redirects} ? 'follow' : 'off'));

    my $t0 = time();
    my $response = eval { $probe_ua->request($request) };
    my $elapsed_ms = (time() - $t0) * 1000;

    unless (ref $response) {
        return $down->('transport_error', "request failed: $@");
    }

    my $content = eval { $response->decoded_content };
    $content = $response->content unless defined $content;
    $content = '' unless defined $content;

    my $code = $response->code;
    $code = 0 unless defined $code && $code =~ /\A\d+\z/;

    # LWP reports connection-level failures (DNS, refused, TLS, timeout)
    # as an internal 500 carrying Client-Warning: Internal response; a
    # real server 500 never has that header. Classify with timeout
    # first - a TLS handshake that times out is a timeout - then
    # certificate/SSL text, then generic transport.
    if ($code == 500
        && ($response->header('Client-Warning') // '') eq 'Internal response') {
        my $err = length $content
            ? $content : ($response->message // 'connection failed');
        if ($err =~ /timeout|timed out/i) {
            return $down->('timeout', "timeout after ${svc_timeout}s: $err");
        } elsif ($err =~ /certificate|\bSSL\b|\bTLS\b|handshake/i) {
            return $down->('tls_error', "TLS failure: $err");
        }
        return $down->('transport_error', "connection failed: $err");
    }

    # Status step (4). A 401/403 while auth is configured reports
    # auth_error instead of the plain mismatch: 7 defines the token that
    # way ("distinct from a plain status mismatch so the UI can hint
    # 'check the credential'"), and with the default expected set (any
    # 2xx/3xx) a literal status-then-auth order would make auth_error
    # unreachable. This is the exact same verdict a deferred 401/403
    # would get from the status check - just labeled usefully.
    if (($auth_type ne '' && $auth_type ne 'none')
        && ($code == 401 || $code == 403)) {
        return $down->('auth_error',
            "server answered HTTP $code while auth is configured", $code);
    }

    # expected_status is a comma list of NNN / NNN-NNN; unset means any
    # 2xx/3xx (5.1/11).
    my $status_ok;
    my $expected = $service->{expected_status};
    if (defined $expected && length $expected) {
        $status_ok = _status_in_range($expected, $code);
    } else {
        $status_ok = ($code >= 200 && $code <= 399) ? 1 : 0;
    }
    unless ($status_ok) {
        return $down->('status_mismatch',
            "HTTP $code does not match the expected status", $code);
    }

    # Assertions match the transfer-decoded body (4), capped at its
    # first 256 KB: bigger bodies are truncated for matching, not
    # rejected (5.1/11). Patterns arrive as Unicode strings through
    # the JSON transport; the match window is bytes, so patterns are
    # encoded to bytes too and compared byte on byte - ASCII behaves
    # identically, UTF-8 matches its true bytes rather than its chars.
    my $window = substr($content, 0, 262144);

    my $disable_string = (defined $service->{disable_string}
        && length $service->{disable_string})
        ? _wire_bytes($service->{disable_string}) : undef;
    my $receive_string = (defined $service->{receive_string}
        && length $service->{receive_string})
        ? _wire_bytes($service->{receive_string}) : undef;

    my $up = sub {
        my ($reason, $message) = @_;
        return {
            id          => $service->{id} // '',
            loss        => 0,
            median      => sprintf("%.1f", $elapsed_ms) + 0,
            state       => 'UP',
            status_code => $code,
            reason      => $reason,
            message     => svc_safe_message($service, $message),
        };
    };

    # Disable beats receive (4). The whole point of F5's recv_disable:
    # a "service down" page can legitimately contain the healthy text,
    # and calling that UP would be precisely the false green this
    # branch exists to prevent. Evaluated before any receive clause.
    if (defined $disable_string) {
        if ($service->{disable_regex}) {
            my ($re, $err) = _compile_regex($disable_string);
            # $code rides along: the response DID happen, and a DOWN
            # result must not map to the RRD's "U" (no response).
            return $down->('bad_regex', "disable regex does not compile: $err", $code)
                unless defined $re;
            return $down->('disable_match', 'disable string matched', $code)
                if $window =~ $re;
        } elsif (index($window, $disable_string) >= 0) {
            return $down->('disable_match', 'disable string matched', $code);
        }
    }

    if (defined $receive_string) {
        unless (length $window) {
            # The response carried no body at all: a receive clause
            # cannot be evaluated against nothing, and that failure is
            # its own token rather than a misleading receive_miss.
            return $down->('empty_body',
                'response body empty; receive string cannot match', $code);
        }
        if ($service->{receive_regex}) {
            my ($re, $err) = _compile_regex($receive_string);
            return $down->('bad_regex', "receive regex does not compile: $err", $code)
                unless defined $re;
            return $up->('receive_match', 'receive string matched')
                if $window =~ $re;
            return $down->('receive_miss',
                'receive string not found in body', $code);
        }
        return $up->('receive_match', 'receive string matched')
            if index($window, $receive_string) >= 0;
        return $down->('receive_miss', 'receive string not found in body',
            $code);
    }

    # No receive string configured: reaching here means TLS, transport,
    # timeout, status and auth all passed (4, last line).
    return $up->('status_ok', "HTTP $code");
}

# Compile a receive/disable pattern (4/I5: regex is the only
# interpretation, it is opt-in via the *_regex flag, and an invalid
# regex is a bad_regex result, never a crash). eval-wrapped with
# warnings silenced: some near-legal patterns warn loudly instead of
# dying. Returns ($qr, $error); $error is '' on success.
sub _compile_regex {
    my ($pattern) = @_;
    my $re = eval {
        local $SIG{__WARN__} = sub { };
        qr/$pattern/;
    };
    return ($re, '') if defined $re;
    my $err = "$@";
    $err =~ s/[\r\n]+/ /g;
    $err = substr($err, 0, 120);
    $err = 'regex did not compile' unless length $err;
    return (undef, $err);
}

# Strings go on the wire as bytes, but the JSON transport delivers
# upgraded (Unicode) strings. JSON bodies always encode - JSON on the
# wire is UTF-8 by definition; anything else encodes only when it
# carries characters beyond single-byte range, so plain byte strings
# pass through untouched. Copies its argument; never mutates the
# service config.
sub _wire_bytes {
    my ($string, $always) = @_;
    $string = defined $string ? "$string" : '';
    if (utf8::is_utf8($string) && ($always || $string =~ /[^\x00-\xFF]/)) {
        # utf8::encode is a built-in, so no `use` line and the
        # agent_image_pkgs.t apk-package gate stays closed.
        utf8::encode($string);
    }
    return $string;
}

# Does the code match an expected_status spec - comma list of NNN or
# NNN-NNN (5.1)? Unparseable tokens match nothing (validation refuses
# them at save; a skewed portal must never yield a false green).
sub _status_in_range {
    my ($spec, $code) = @_;
    my $matched = 0;
    foreach my $token (split /,/, $spec) {
        if ($token =~ /\A\s*(\d{1,3})\s*-\s*(\d{1,3})\s*\z/) {
            my ($lo, $hi) = ($1, $2);
            ($lo, $hi) = ($hi, $lo) if $lo > $hi;
            $matched = 1 if $code >= $lo && $code <= $hi;
        } elsif ($token =~ /\A\s*(\d{1,3})\s*\z/) {
            $matched = 1 if $code == $1;
        }
    }
    return $matched;
}

# Message and log hygiene (5.4): the agent's log path must never emit
# the resolved secret. Scrub every form the secret can take - raw
# string, UTF-8 bytes, the Basic user:secret pair and its base64 form,
# the Bearer header payload - then flatten to one short line of
# printable ASCII, which also keeps JSON encoding deterministic.
sub svc_safe_message {
    my ($service, $message) = @_;
    $message = '' unless defined $message;
    my $secret = $service->{auth_secret};
    if (defined $secret && length $secret) {
        my @secret_forms = ($secret);
        my $encoded = "$secret";
        if (utf8::is_utf8($encoded)) {
            utf8::encode($encoded);
            push @secret_forms, $encoded if $encoded ne $secret;
        }
        my $auth_type = lc($service->{auth_type} // '');
        if ($auth_type eq 'basic') {
            my $pair = _wire_bytes($service->{auth_username} // '') . ':'
                . _wire_bytes($secret);
            push @secret_forms, $pair;
            require MIME::Base64;   # core module; see the note in http_probe
            push @secret_forms, MIME::Base64::encode_base64($pair, '');
        } elsif ($auth_type eq 'bearer') {
            push @secret_forms, "Bearer $secret";
        }
        foreach my $form (@secret_forms) {
            next unless length $form;
            $message =~ s/\Q$form\E/[redacted]/g;
        }
    }
    $message =~ s/[\r\n]+/ /g;
    $message =~ s/[^\x20-\x7E]/./g;
    $message = substr($message, 0, 200);
    return $message;
}