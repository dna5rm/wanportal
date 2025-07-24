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
# use Encode qw(encode);

# Configuration from environment variables
my $API_URL = $ENV{'API_URL'} || 'http://localhost/cgi-bin/api/monitors';
my $SITE_URL = $ENV{'SITE_URL'};
my $STATE_FILE = $ENV{'STATE_FILE'} || '/tmp/monitor_state.dat';
my $SMTP_SERVER = $ENV{'SMTP_SERVER'};
my $SMTP_PORT = $ENV{'SMTP_PORT'} || 25;
my $FROM_EMAIL = $ENV{'FROM_EMAIL'};
my $TO_EMAIL = $ENV{'TO_EMAIL'};
my $DOWN_THRESHOLD = $ENV{'DOWN_THRESHOLD'} || 300;  # Default 5 minutes in seconds

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

        # Only include if down more than threshold
        if (($current_time - $down_epoch) > $DOWN_THRESHOLD) {
            # Use a combination of id and target_address as the unique key
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

# Function to create "all clear" HTML email content
sub create_all_clear_content {
    my ($cleared_count) = @_;
    
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
        color: #28a745;
        text-align: center;
        margin: 0 0 15px 0;
        font-size: 24px;
        padding: 0;
    }
    .summary {
        text-align: center;
        margin-bottom: 20px;
        padding: 20px;
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        border-radius: 5px;
        font-weight: bold;
        font-size: 18px;
        color: #155724;
    }
</style>
</head>
<body>
<div class="container">
HTML

    $html .= "<h2>All Clear - No Down Monitors</h2>\n";
    $html .= sprintf("<div class='summary'>%d monitor(s) have been restored.<br>All monitors are now operational!</div>\n", $cleared_count);
    $html .= "</div>\n</body>\n</html>";
    return $html;
}

# Function to create HTML email content
sub create_email_content {
    my ($all_down_hosts, $new_down_count, $cleared_count) = @_;
    
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

    $html .= "<h2>Monitor Status Alert</h2>\n";
    
    # Create summary message based on what changed
    my $summary_msg = "";
    if ($new_down_count > 0 && $cleared_count > 0) {
        $summary_msg = sprintf("%d new monitor(s) down, %d monitor(s) cleared - Total: %d monitors currently down", 
                              $new_down_count, $cleared_count, scalar(keys %$all_down_hosts));
    } elsif ($new_down_count > 0) {
        $summary_msg = sprintf("%d new monitor(s) down - Total: %d monitors currently down", 
                              $new_down_count, scalar(keys %$all_down_hosts));
    } elsif ($cleared_count > 0) {
        $summary_msg = sprintf("%d monitor(s) cleared - Total: %d monitors currently down", 
                              $cleared_count, scalar(keys %$all_down_hosts));
    }
    
    $html .= sprintf("<div class='summary'>%s</div>\n", $summary_msg);
    $html .= "<div class='table-wrapper'>\n";
    $html .= "<table>\n";
    $html .= "<thead>\n";
    $html .= "<tr>\n";
    $html .= "<th>Monitor</th>\n";
    $html .= "<th>Agent</th>\n";
    $html .= "<th>Target</th>\n";
    $html .= "<th>Down Since</th>\n";
    $html .= "</tr>\n";
    $html .= "</thead>\n";
    $html .= "<tbody>\n";

    for my $host (
        sort { 
            str2time($all_down_hosts->{$b}->{last_down}) 
            <=> 
            str2time($all_down_hosts->{$a}->{last_down})
        } keys %$all_down_hosts
    ) {
        my $down_time = $all_down_hosts->{$host}->{last_down};
        my $row_class = '';
        
        my $down_epoch = str2time($down_time);
        my $hours_down = ($current_time - $down_epoch) / 3600;
        
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
            $all_down_hosts->{$host}->{id},
            $all_down_hosts->{$host}->{description},
            $all_down_hosts->{$host}->{agent_id},
            $all_down_hosts->{$host}->{agent_name},
            $all_down_hosts->{$host}->{target_id},
            $all_down_hosts->{$host}->{target_address},
            strftime("%m/%d %H:%M:%S", localtime($down_epoch))
        );
    }
    
    $html .= "</tbody>\n</table>\n</div>\n";
    $html .= "</div>\n</body>\n</html>";
    return $html;
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

# Main execution
sub main {
    my $current_state = get_monitors_state();
    my $previous_state = load_previous_state();
    
    my %new_downs;
    my %cleared_ups;
    
    # Find new down hosts
    for my $host (keys %$current_state) {
        if (!exists $previous_state->{$host}) {
            $new_downs{$host} = $current_state->{$host};
        }
    }
    
    # Find hosts that came back up
    for my $host (keys %$previous_state) {
        if (!exists $current_state->{$host}) {
            $cleared_ups{$host} = $previous_state->{$host};
        }
    }
    
    # Send notification if there are any changes (new downs or cleared ups)
    if (%new_downs || %cleared_ups) {
        my $new_down_count = scalar(keys %new_downs);
        my $cleared_count = scalar(keys %cleared_ups);
        my $total_down = scalar(keys %$current_state);
        
        # Special case: if no monitors are down, send "all clear" message
        if ($total_down == 0) {
            my $html_content = create_all_clear_content($cleared_count);
            send_smtp_email(
                $TO_EMAIL,
                $FROM_EMAIL,
                "Monitor Status Alert - All Clear",
                $html_content
            );
        } else {
            # Send normal update with current down monitors
            my $html_content = create_email_content($current_state, $new_down_count, $cleared_count);
            send_smtp_email(
                $TO_EMAIL,
                $FROM_EMAIL,
                "Monitor Status Change Alert - Down Hosts",
                $html_content
            );
        }
    }
    
    save_current_state($current_state);
}

# Execute main routine
eval {
    main();
};
if ($@) {
    warn "Error executing script: $@";
    exit 1;
}

# Test the all clear function
if ($ARGV[0] && $ARGV[0] eq 'test-clear') {
    my $html_content = create_all_clear_content(3);
    send_smtp_email(
        $TO_EMAIL,
        $FROM_EMAIL,
        "TEST - Monitor Status Alert - All Clear",
        $html_content
    );
    print "Test all clear email sent\n";
    exit 0;
}

exit 0;
