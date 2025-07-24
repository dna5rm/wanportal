#!/usr/bin/env perl

use strict;
use warnings;
use LWP::UserAgent;
use HTTP::Request::Common qw(POST);
use JSON;
use POSIX qw(strftime);
use Date::Parse qw(str2time);
use Storable qw(store retrieve);

# Configuration from environment variables
my $API_URL = $ENV{'API_URL'} || 'http://localhost/cgi-bin/api/monitors';
my $STATE_FILE = $ENV{'STATE_FILE'} || '/tmp/ntfy_state.dat';
my $DOWN_THRESHOLD = $ENV{'DOWN_THRESHOLD'} || 300;  # Default 5 minutes in seconds
my $NTFY_SERVER = $ENV{'NTFY_SERVER'} || die "NTFY_SERVER environment variable is required";
my $NTFY_TOPIC = $ENV{'NTFY_TOPIC'} || die "NTFY_TOPIC environment variable is required";
my $DEBUG = $ENV{'DEBUG'} || 0;  # Set to 1 to enable debug output

# Debug function
sub debug {
    my ($message) = @_;
    print "DEBUG: $message\n" if $DEBUG;
}

# Function to fetch current monitors state
sub get_monitors_state {
    my $ua = LWP::UserAgent->new(ssl_opts => { verify_hostname => 0 });
    my $response = $ua->get("$API_URL?current_loss=100&is_active=1");
    die "Failed to fetch data from API: " . $response->status_line unless $response->is_success;

    my $data = decode_json($response->content);
    return unless $data->{status} eq 'success';

    my %down_hosts;
    my $current_time = time();

    foreach my $monitor (@{$data->{monitors}}) {
        my $down_epoch = str2time($monitor->{last_down});

        if (($current_time - $down_epoch) > $DOWN_THRESHOLD) {
            my $unique_key = $monitor->{id};
            $down_hosts{$unique_key} = {
                description => $monitor->{description},
                agent_name => $monitor->{agent_name},
                last_down => $monitor->{last_down},
                total_down => $monitor->{total_down},
                id => $monitor->{id},
                agent_id => $monitor->{agent_id},
                target_id => $monitor->{target_id},
                target_address => $monitor->{target_address}
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

# Function to send NTFY notification
sub send_ntfy_notification {
    my ($message, $is_test) = @_;
    my $ua = LWP::UserAgent->new(
        ssl_opts => { verify_hostname => 0 },
        agent => 'curl/7.88.1'  # Mimic curl's User-Agent
    );

    my $full_message = $is_test ? "$message" : $message;

    debug("Sending notification to https://$NTFY_SERVER/$NTFY_TOPIC");
    debug("Message content: $full_message");

    my $request = POST "https://$NTFY_SERVER/$NTFY_TOPIC",
        'Content-Type' => 'application/x-www-form-urlencoded',
        Content => $full_message;

    # Remove any automatically added headers
    $request->remove_header('Content-Length');

    my $response = $ua->request($request);

    if (!$response->is_success) {
        debug("Request method: " . $request->method);
        debug("Request URI: " . $request->uri);
        debug("Request headers:");
        debug($_. ": ". $request->header($_)) for $request->header_field_names;
        debug("Request content: " . $request->content);
        debug("Response status: " . $response->status_line);
        debug("Response content: " . $response->content);
        die "Failed to send NTFY notification: " . $response->status_line;
    }

    print "Notification sent successfully" . ($is_test ? " (TEST MODE)" : "") . ".\n";
    debug("Response content: " . $response->content);
}

# Main execution
sub main {
    my ($is_test) = @_;
    my $current_state = $is_test ? generate_test_state() : get_monitors_state();
    my $previous_state = $is_test ? {} : load_previous_state();

    my %new_downs;
    my %cleared_ups;

    # Find new down hosts and hosts that came back up
    for my $host (keys %$current_state) {
        $new_downs{$host} = $current_state->{$host} if !exists $previous_state->{$host};
    }
    for my $host (keys %$previous_state) {
        $cleared_ups{$host} = $previous_state->{$host} if !exists $current_state->{$host};
    }

    # Prepare notifications
    my @notifications;

    # New down hosts
    if (%new_downs) {
        my $down_message = "🔴 New Down Monitors:\n";
        for my $host (keys %new_downs) {
            $down_message .= sprintf("- %s (%s)\n", $new_downs{$host}->{description}, $new_downs{$host}->{target_address});
        }
        push @notifications, $down_message;
    }

    # Cleared up hosts
    if (%cleared_ups) {
        my $up_message = "🟢 Monitors Back Up:\n";
        for my $host (keys %cleared_ups) {
            $up_message .= sprintf("- %s (%s)\n", $cleared_ups{$host}->{description}, $cleared_ups{$host}->{target_address});
        }
        push @notifications, $up_message;
    }

    # Send grouped notification
    if (@notifications) {
        my $total_down = scalar(keys %$current_state);
        my $full_message = join('\n', @notifications);
        send_ntfy_notification($full_message, $is_test);
    }

    save_current_state($current_state) unless $is_test;
}

# Test function to generate a mock state
sub generate_test_state {
    return {
        '1' => {
            description => 'Test Monitor 1',
            target_address => '192.168.1.1',
            last_down => strftime("%Y-%m-%d %H:%M:%S", localtime(time() - 3600)),
        },
        '2' => {
            description => 'Test Monitor 2',
            target_address => '192.168.1.2',
            last_down => strftime("%Y-%m-%d %H:%M:%S", localtime(time() - 7200)),
        },
    };
}

# Check if we're in test mode
if (@ARGV && $ARGV[0] eq 'test') {
    eval {
        main(1);  # Pass 1 to indicate test mode
    };
    if ($@) {
        warn "Error during test: $@";
        exit 1;
    }
    exit 0;
}

# Normal execution
eval {
    main();
};
if ($@) {
    warn "Error executing script: $@";
    exit 1;
}

exit 0;
