#!/usr/bin/env perl

use strict;
use warnings;
use LWP::Simple;
use JSON;
use Email::MIME;
use Email::Simple;
use HTML::Entities;
use MIME::Base64;
use Socket;
use POSIX qw(strftime);
use Date::Parse qw(str2time);
use Storable qw(store retrieve);
use Data::Dumper;
# use Encode qw(encode);

# Configuration from environment variables
my $API_URL = $ENV{'API_URL'} || 'http://localhost/cgi-bin/api/monitors';
my $SERVICE_API_URL = $ENV{'SERVICE_API_URL'} || 'http://localhost/cgi-bin/api/services';
my $SITE_URL = $ENV{'SITE_URL'};
my $STATE_FILE = $ENV{'STATE_FILE'} || '/tmp/monitor_state.dat';
my $SERVICE_STATE_FILE = $ENV{'SERVICE_STATE_FILE'} || '/tmp/service_state.dat';
my $SMTP_SERVER = $ENV{'SMTP_SERVER'};
my $SMTP_PORT = $ENV{'SMTP_PORT'} || 25;
my $FROM_EMAIL = $ENV{'FROM_EMAIL'};
my $TO_EMAIL = $ENV{'TO_EMAIL'};
my $DOWN_THRESHOLD = $ENV{'DOWN_THRESHOLD'} || 300;  # Default 5 minutes in seconds
my $SEND_CLEAR_NOTIFICATIONS = $ENV{'SEND_CLEAR_NOTIFICATIONS'} || 0;  # Set to 1 to enable clear notifications
my $EXCLUDE_TRIGGER_WORD = $ENV{'EXCLUDE_TRIGGER_WORD'} || 'exclude';  # Word to trigger exclusion
my $debug = $ENV{DEBUG} || 0;

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

# Function to fetch current monitors state
sub get_monitors_state {
    my $json = get("$API_URL?current_loss=100&is_active=1");
    die "Failed to fetch data from API" unless defined $json;

    my $data = decode_json($json);
    return unless $data->{status} eq 'success';

    my %down_hosts;
    my $current_time = time();

    foreach my $monitor (@{$data->{monitors}}) {
        my $down_epoch = str2time($monitor->{last_down});
        
        # Ensure last_down is not in the future
        $down_epoch = $current_time if $down_epoch > $current_time;

        if ($monitor->{description} !~ /^\s*$EXCLUDE_TRIGGER_WORD/i) {
            my $unique_key = $monitor->{id};
            $down_hosts{$unique_key} = {
                description => $monitor->{description},
                agent_name => $monitor->{agent_name},
                last_down => $monitor->{last_down},
                id => $monitor->{id},
                agent_id => $monitor->{agent_id},
                target_id => $monitor->{target_id},
                target_address => $monitor->{target_address},
                down_duration => 0,
                clear_duration => undef,
                notified => 0,
                last_check_time => $current_time,
            };
        }
    }

    return \%down_hosts;
}

# Function to load previous state
sub load_previous_state {
    return {} unless -f $STATE_FILE;
    return retrieve($STATE_FILE) || {};
}

# Function to save current state
sub save_current_state {
    my ($state) = @_;
    store($state, $STATE_FILE);
}

# Function to load previous services state (fail-soft: a missing or
# unreadable services state file only starts a fresh state)
sub load_previous_service_state {
    return {} unless -f $SERVICE_STATE_FILE;
    my $state = eval { retrieve($SERVICE_STATE_FILE) };
    if (!defined $state || $@) {
        info_log("Could not read services state file $SERVICE_STATE_FILE, starting fresh");
        return {};
    }
    return $state;
}

# Function to save current services state (fail-soft: a failed write is
# logged and the run continues - monitor alerting is not affected)
sub save_current_service_state {
    my ($state) = @_;
    my $ok = eval { store($state, $SERVICE_STATE_FILE) };
    if (!$ok || $@) {
        info_log("Could not write services state file $SERVICE_STATE_FILE: $@");
    }
}

# Function to fetch current services state. Fail-soft: returns undef when
# the services API is unreachable or absent (e.g. an older API without a
# services endpoint), so services never block the monitor alerting path.
sub get_services_state {
    my $json = eval { get($SERVICE_API_URL) };
    if (!defined $json || $@) {
        info_log("Services API at $SERVICE_API_URL unavailable: skipping services notifications");
        return undef;
    }

    my $data = eval { decode_json($json) };
    if (!defined $data || $@ || !ref($data) || ($data->{status} // '') ne 'success') {
        info_log("Services API at $SERVICE_API_URL returned an unexpected response: skipping services notifications");
        return undef;
    }

    my %down_services;
    my $current_time = time();

    foreach my $service (@{$data->{services} || []}) {
        # Mirror the dashboard rule: DOWN and DISABLED both count as down
        my $state = $service->{last_state} // '';
        next unless $state eq 'DOWN' || $state eq 'DISABLED';

        # Respect the exclude trigger word on the description
        next if ($service->{description} // '') =~ /^\s*$EXCLUDE_TRIGGER_WORD/i;

        # Down-since comes from last_change, with the same guard the
        # monitor side uses on its timestamps: unparseable or in the
        # future counts from now
        my $down_epoch = str2time($service->{last_change} // '');
        if (!defined $down_epoch || $down_epoch <= 0 || $down_epoch > $current_time) {
            $down_epoch = $current_time;
        }

        my $unique_key = $service->{id};
        $down_services{$unique_key} = {
            description => $service->{description} // '',
            agent_name => $service->{agent_name} // '',
            id => $service->{id},
            agent_id => $service->{agent_id},
            target_id => $service->{target_id},
            last_state => $state,
            last_reason => $service->{last_reason} // '',
            last_down => $service->{last_change},   # raw down-since, like the monitors' last_down
            first_down_time => $down_epoch,         # parsed down-since (guarded)
            uri => build_service_uri($service),
            down_duration => 0,
            clear_duration => undef,
            notified => 0,
            last_check_time => $current_time,
        };
    }

    return \%down_services;
}

# Build a service's display URI (scheme://host:port/uri_path[?uri_query]).
# Same rendering the SPA uses: :port omitted when 0 (scheme default) and
# the query appended only when uri_query is non-empty.
sub build_service_uri {
    my ($service) = @_;
    my $uri = ($service->{scheme} // '') . '://' . ($service->{target_address} // '');
    my $port = $service->{port} // 0;
    $uri .= ':' . $port if $port && $port =~ /^\d+$/;
    $uri .= ($service->{uri_path} // '') || '/';
    $uri .= '?' . $service->{uri_query} if defined $service->{uri_query} && length $service->{uri_query};
    return $uri;
}

# Function to create HTML email content
sub create_email_content {
    my ($all_down_hosts, $new_down_count, $cleared_count, $is_clear_notification, $cleared_ups,
        $all_down_services, $new_service_down_count, $service_cleared_count, $cleared_services,
        $services_ok) = @_;
    
    # Get current time
    my $current_time = time();
    
    my $html = <<'HTML';
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    body { 
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 20px;
        background-color: #f8f9fa;
    }
    .container {
        width: 100%;
        max-width: 1000px;
        margin-left: auto;
        margin-right: auto;
        background-color: #fff;
        padding: 5%;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    h2 {
        color: #333;
        text-align: center;
        margin: 0 0 15px 0;
        font-size: 20px;
        padding: 0;
    }
    .summary {
        text-align: center;
        margin-bottom: 20px;
        padding: 10px;
        background-color: #fff3cd;
        border-radius: 5px;
        font-weight: bold;
    }
    .table-wrapper {
        width: 80%;
        margin-left: auto;
        margin-right: auto;
        margin-bottom: 20px;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        background-color: #fff;
        margin: 0 auto;
    }
    th {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        padding: 8px 12px;
        text-align: left;
        font-weight: bold;
        color: #333;
        font-size: 14px;
    }
    td {
        border: 1px solid #dee2e6;
        padding: 8px 12px;
        vertical-align: middle;
        font-size: 13px;
    }
    .table-danger {
        background-color: #f8d7da;
    }
    .table-warning {
        background-color: #fff3cd;
    }
    .table-info {
        background-color: #cff4fc;
    }
    .table-success {
        background-color: #d4edda;
    }
    a {
        color: #0066cc;
        text-decoration: none;
    }
    a:hover {
        text-decoration: underline;
    }
</style>
</head>
<body>
<div class="container">
HTML

    $html .= "<h2>Monitor & Service Status Alert</h2>\n";
    
    my $total_down = scalar(keys %$all_down_hosts);
    my $total_down_services = $services_ok ? scalar(keys %$all_down_services) : 0;
    my $summary_msg;
    if ($services_ok) {
        $summary_msg = sprintf("%d new monitor(s) down, %d monitor(s) cleared, %d new service(s) down, %d service(s) cleared - Total: %d monitors, %d services down",
                               $new_down_count, $cleared_count, $new_service_down_count, $service_cleared_count,
                               $total_down, $total_down_services);
    } else {
        # Services data unavailable (API unreachable or absent): monitors-only summary
        $summary_msg = sprintf("%d new monitor(s) down, %d monitor(s) cleared - Total: %d monitors currently down (services data unavailable)",
                               $new_down_count, $cleared_count, $total_down);
    }
    
    $html .= sprintf("<div class='summary'>%s</div>\n", $summary_msg);
    
    if (keys %$all_down_hosts) {
        $html .= "<h3>Down Monitors</h3>\n";
        $html .= "<div class='table-wrapper'>\n";
        $html .= "<table>\n";
        $html .= "<thead>\n";
        $html .= "<tr>\n";
        $html .= "<th>Monitor</th>\n";
        $html .= "<th>Agent</th>\n";
        $html .= "<th>Target</th>\n";
        $html .= "<th>Down Duration</th>\n";
        $html .= "</tr>\n";
        $html .= "</thead>\n";
        $html .= "<tbody>\n";

        for my $host (
            sort { 
                $all_down_hosts->{$b}->{down_duration} <=> $all_down_hosts->{$a}->{down_duration}
            } keys %$all_down_hosts
        ) {
            my $monitor = $all_down_hosts->{$host};
            my $row_class = '';
            
            my $hours_down = $monitor->{down_duration} / 3600;
            
            if ($hours_down >= 24) {
                $row_class = 'table-danger';
            } elsif ($hours_down >= 12) {
                $row_class = 'table-warning';
            } elsif ($hours_down >= 1) {
                $row_class = 'table-info';
            }

            $html .= sprintf(
                "<tr class='%s'><td><a href='$SITE_URL/monitor.php?id=%s'>%s</a></td>" .
                "<td><a href='$SITE_URL/agent.php?id=%s'>%s</a></td>" .
                "<td><a href='$SITE_URL/target.php?id=%s'>%s</a></td>" .
                "<td>%s</td></tr>\n",
                $row_class,
                $monitor->{id},
                $monitor->{description},
                $monitor->{agent_id},
                $monitor->{agent_name},
                $monitor->{target_id},
                $monitor->{target_address},
                format_duration($monitor->{down_duration})
            );
        }
        
        $html .= "</tbody>\n</table>\n</div>\n";
    }
    
    if ($cleared_count > 0 && $cleared_ups) {
        $html .= "<h3>Cleared Monitors</h3>\n";
        $html .= "<div class='table-wrapper'>\n";
        $html .= "<table>\n";
        $html .= "<thead>\n";
        $html .= "<tr>\n";
        $html .= "<th>Monitor</th>\n";
        $html .= "<th>Agent</th>\n";
        $html .= "<th>Target</th>\n";
        $html .= "<th>Down Duration</th>\n";
        $html .= "</tr>\n";
        $html .= "</thead>\n";
        $html .= "<tbody>\n";

        for my $host (keys %$cleared_ups) {
            my $monitor = $cleared_ups->{$host};
            $html .= sprintf(
                "<tr class='table-success'><td><a href='$SITE_URL/monitor.php?id=%s'>%s</a></td>" .
                "<td><a href='$SITE_URL/agent.php?id=%s'>%s</a></td>" .
                "<td><a href='$SITE_URL/target.php?id=%s'>%s</a></td>" .
                "<td>%s</td></tr>\n",
                $monitor->{id},
                $monitor->{description},
                $monitor->{agent_id},
                $monitor->{agent_name},
                $monitor->{target_id},
                $monitor->{target_address},
                format_duration($monitor->{down_duration})
            );
        }
        
        $html .= "</tbody>\n</table>\n</div>\n";
    }
    
    # Services sections (same table style as the monitor tables)
    if ($services_ok && $all_down_services && keys %$all_down_services) {
        $html .= "<h3>Down Services</h3>\n";
        $html .= "<div class='table-wrapper'>\n";
        $html .= "<table>\n";
        $html .= "<thead>\n";
        $html .= "<tr>\n";
        $html .= "<th>Service</th>\n";
        $html .= "<th>Agent</th>\n";
        $html .= "<th>URI</th>\n";
        $html .= "<th>Reason</th>\n";
        $html .= "<th>Down Duration</th>\n";
        $html .= "</tr>\n";
        $html .= "</thead>\n";
        $html .= "<tbody>\n";

        for my $svc (
            sort {
                $all_down_services->{$b}->{down_duration} <=> $all_down_services->{$a}->{down_duration}
            } keys %$all_down_services
        ) {
            my $service = $all_down_services->{$svc};
            my $row_class = '';
            
            my $hours_down = $service->{down_duration} / 3600;
            
            if ($hours_down >= 24) {
                $row_class = 'table-danger';
            } elsif ($hours_down >= 12) {
                $row_class = 'table-warning';
            } elsif ($hours_down >= 1) {
                $row_class = 'table-info';
            }

            $html .= sprintf(
                "<tr class='%s'><td><a href='$SITE_URL/#/services/%s'>%s</a></td>" .
                "<td>%s</td>" .
                "<td>%s</td>" .
                "<td>%s</td>" .
                "<td>%s</td></tr>\n",
                $row_class,
                $service->{id},
                $service->{description},
                $service->{agent_name},
                $service->{uri},
                $service->{last_reason},
                format_duration($service->{down_duration})
            );
        }
        
        $html .= "</tbody>\n</table>\n</div>\n";
    }
    
    if ($services_ok && $service_cleared_count > 0 && $cleared_services) {
        $html .= "<h3>Cleared Services</h3>\n";
        $html .= "<div class='table-wrapper'>\n";
        $html .= "<table>\n";
        $html .= "<thead>\n";
        $html .= "<tr>\n";
        $html .= "<th>Service</th>\n";
        $html .= "<th>Agent</th>\n";
        $html .= "<th>URI</th>\n";
        $html .= "<th>Reason</th>\n";
        $html .= "<th>Down Duration</th>\n";
        $html .= "</tr>\n";
        $html .= "</thead>\n";
        $html .= "<tbody>\n";

        for my $svc (keys %$cleared_services) {
            my $service = $cleared_services->{$svc};
            $html .= sprintf(
                "<tr class='table-success'><td><a href='$SITE_URL/#/services/%s'>%s</a></td>" .
                "<td>%s</td>" .
                "<td>%s</td>" .
                "<td>%s</td>" .
                "<td>%s</td></tr>\n",
                $service->{id},
                $service->{description},
                $service->{agent_name},
                $service->{uri},
                $service->{last_reason},
                format_duration($service->{down_duration})
            );
        }
        
        $html .= "</tbody>\n</table>\n</div>\n";
    }
    
    $html .= "</div>\n</body>\n</html>";
    return $html;
}

# Helper function to format duration
sub format_duration {
    my ($seconds) = @_;
    my $hours = int($seconds / 3600);
    my $minutes = int(($seconds % 3600) / 60);
    my $remaining_seconds = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $remaining_seconds);
}

# Function to send email via direct SMTP
sub send_smtp_email {
    my ($to, $from, $subject, $html_content) = @_;
    
    my $message = Email::MIME->create(
        header_str => [
            From    => $from,
            To      => $to,
            Subject => $subject,
        ],
        parts => [
            Email::MIME->create(
                attributes => {
                    content_type => "text/html",
                    charset     => "UTF-8",
                    encoding    => "quoted-printable",
                },
                body_str => $html_content,
            ),
        ],
    );

    # Connect to SMTP server
    my $socket;
    socket($socket, PF_INET, SOCK_STREAM, getprotobyname('tcp')) or die "Socket creation failed: $!";
    connect($socket, sockaddr_in($SMTP_PORT, inet_aton($SMTP_SERVER))) or die "Connect failed: $!";
    
    # Set autoflush
    select($socket); $| = 1; select(STDOUT);
    
    # Read greeting
    my $response = <$socket>;
    die "SMTP greeting failed: $response" unless $response =~ /^2/;
    
    # SMTP conversation
    print $socket "HELO localhost\r\n";
    $response = <$socket>;
    die "HELO failed: $response" unless $response =~ /^2/;
    
    print $socket "MAIL FROM: <$from>\r\n";
    $response = <$socket>;
    die "MAIL FROM failed: $response" unless $response =~ /^2/;
    
    print $socket "RCPT TO: <$to>\r\n";
    $response = <$socket>;
    die "RCPT TO failed: $response" unless $response =~ /^2/;
    
    print $socket "DATA\r\n";
    $response = <$socket>;
    die "DATA failed: $response" unless $response =~ /^3/;
    
    print $socket $message->as_string, "\r\n.\r\n";
    $response = <$socket>;
    die "Message sending failed: $response" unless $response =~ /^2/;
    
    print $socket "QUIT\r\n";
    close($socket);
}

# Shared down-state diff algorithm - both monitors and services run
# through this so the tracking rules stay identical: first-seen =>
# first_down_time, down_duration accumulates until notified past
# $DOWN_THRESHOLD, and a gone entry accumulates clear_duration until it
# is deleted past the threshold.
# Updates $previous_state in place; returns (\%new_downs, \%cleared_ups).
sub diff_states {
    my ($current_state, $previous_state, $label) = @_;
    my $alarm_noun = (defined $label && $label eq 'service') ? ' service' : '';

    my %new_downs;
    my %cleared_ups;
    my $current_time = time();
    
    # Process current state and update previous state
    for my $host (keys %$current_state) {
        if (!exists $previous_state->{$host}) {
            # New down monitor / service
            $previous_state->{$host} = $current_state->{$host};
            $previous_state->{$host}->{first_down_time} = $current_state->{$host}->{first_down_time} // $current_time;
            $previous_state->{$host}->{down_duration} = 0;
            $previous_state->{$host}->{notified} = 0;
        } else {
            # Update existing monitor
            $previous_state->{$host}->{first_down_time} //= str2time($previous_state->{$host}->{last_down});
            $previous_state->{$host}->{down_duration} = $current_time - $previous_state->{$host}->{first_down_time};
        }
        $previous_state->{$host}->{last_check_time} = $current_time;
        $previous_state->{$host}->{clear_duration} = undef;
        
        if ($previous_state->{$host}->{down_duration} > $DOWN_THRESHOLD && !$previous_state->{$host}->{notified}) {
            $new_downs{$host} = $previous_state->{$host};
        }
    }
    
    # Process previous state and identify cleared ups
    for my $host (keys %$previous_state) {
        if (!exists $current_state->{$host}) {
            if (!defined $previous_state->{$host}->{clear_duration}) {
                $previous_state->{$host}->{clear_duration} = 0;
            }
            $previous_state->{$host}->{clear_duration} += $current_time - $previous_state->{$host}->{last_check_time};
            
            if ($previous_state->{$host}->{clear_duration} > $DOWN_THRESHOLD) {
                if ($previous_state->{$host}->{notified} && $SEND_CLEAR_NOTIFICATIONS) {
                    $cleared_ups{$host} = $previous_state->{$host};
                }
                delete $previous_state->{$host};
                info_log("Removed cleared$alarm_noun alarm: $host");
            } else {
                info_log("Alarm$alarm_noun $host in clear state, duration: $previous_state->{$host}->{clear_duration}");
            }
        }
    }
    
    return (\%new_downs, \%cleared_ups);
}

# Main execution
sub main {
    info_log("NetPing Email Notification...");

    # Check for required environment variables
    my @missing_vars;
    push @missing_vars, 'SITE_URL' unless $SITE_URL;
    push @missing_vars, 'SMTP_SERVER' unless $SMTP_SERVER;
    push @missing_vars, 'FROM_EMAIL' unless $FROM_EMAIL;
    push @missing_vars, 'TO_EMAIL' unless $TO_EMAIL;

    if (@missing_vars) {
        warn "Missing required environment variables: " . join(', ', @missing_vars) . "\n";
        warn "Script will not run without these variables set.\n";
        exit 0;  # Exit silently without error
    }

    debug_log("Starting main function.");
    my $current_state = get_monitors_state();
    debug_log("Current state: " . Dumper($current_state));
    my $previous_state = load_previous_state();
    debug_log("Previous state: " . Dumper($previous_state));

    my ($new_downs, $cleared_ups) = diff_states($current_state, $previous_state);

    # Services: separate state file, same threshold, same diff algorithm.
    # Fail-soft: an unreachable or 404ing services API (older API without
    # services) logs and continues with the monitors only - never dies,
    # never sends a broken service section.
    my $current_services = get_services_state();
    my $services_ok = defined $current_services;
    my ($previous_services, $new_service_downs, $service_cleared_ups);
    if ($services_ok) {
        debug_log("Services current state: " . Dumper($current_services));
        $previous_services = load_previous_service_state();
        debug_log("Services previous state: " . Dumper($previous_services));
        ($new_service_downs, $service_cleared_ups) = diff_states($current_services, $previous_services, 'service');
    } else {
        debug_log("Services unavailable, continuing with monitors only.");
    }
    
    # Determine if notification should be sent
    my $should_notify = keys %$new_downs || keys %$cleared_ups ||
        ($services_ok && (keys %$new_service_downs || keys %$service_cleared_ups));
    
    if ($should_notify) {
        debug_log("Sending notification.");
        my $new_down_count = scalar(keys %$new_downs);
        my $cleared_count = scalar(keys %$cleared_ups);
        my $new_service_down_count = $services_ok ? scalar(keys %$new_service_downs) : 0;
        my $service_cleared_count = $services_ok ? scalar(keys %$service_cleared_ups) : 0;
        
        # Include all current down monitors that exceed the threshold
        my %all_downs = map { $_ => $previous_state->{$_} } 
                        grep { $previous_state->{$_}->{down_duration} > $DOWN_THRESHOLD } 
                        keys %$current_state;
        
        my $total_down = scalar(keys %all_downs);
        
        # Include all current down services that exceed the threshold
        my %all_down_services;
        if ($services_ok) {
            %all_down_services = map { $_ => $previous_services->{$_} }
                                 grep { $previous_services->{$_}->{down_duration} > $DOWN_THRESHOLD }
                                 keys %$current_services;
        }
        
        my $html_content = create_email_content(
            \%all_downs, $new_down_count, $cleared_count, 0, $cleared_ups,
            $services_ok ? \%all_down_services : undef,
            $new_service_down_count, $service_cleared_count,
            $services_ok ? $service_cleared_ups : undef, $services_ok
        );
        send_smtp_email(
            $TO_EMAIL,
            $FROM_EMAIL,
            "Monitor & Service Status Change Alert",
            $html_content
        );
        
        # Mark notified monitors
        for my $host (keys %$new_downs) {
            $previous_state->{$host}->{notified} = 1;
        }
        # Mark notified services
        if ($services_ok) {
            for my $svc (keys %$new_service_downs) {
                $previous_services->{$svc}->{notified} = 1;
            }
        }
    } else {
        debug_log("No changes detected, not sending notification.");
    }
    
    debug_log("Saving current state.");
    save_current_state($previous_state);
    if ($services_ok) {
        debug_log("Saving services state.");
        save_current_service_state($previous_services);
    }
    debug_log("Main function completed.");
}

# Execute main routine when run as a script. Skipped when the file is
# required by a test harness, so tests can exercise the subs without
# triggering a run.
unless (caller) {
    eval {
        main();
    };
    if ($@) {
        warn "Error executing script: $@";
        exit 1;
    }
    exit 0;
}

1;  # require() of this file for testing needs a true final value
