#!/usr/bin/env perl

use strict;
use warnings;
use LWP::UserAgent;
use IO::Socket::SSL qw( SSL_VERIFY_NONE );
use JSON qw(decode_json encode_json);
use Net::Ping;
use Time::HiRes qw(sleep);

#----- Config from ENV -----
my $agent_id = $ENV{AGENT_ID}   or die "ERROR: AGENT_ID env not set\n";
my $password = $ENV{PASSWORD}   or die "ERROR: PASSWORD env not set\n";
my $api      = $ENV{SERVER}     or die "ERROR: SERVER env not set (e.g. http://host/cgi-bin/api)\n";

# ----- UserAgent with SSL ignore -----
$ENV{PERL_LWP_SSL_VERIFY_HOSTNAME} = 0;
my $ua = LWP::UserAgent->new(
    timeout         => 30,
    ssl_opts        => {
        SSL_verify_mode => SSL_VERIFY_NONE,
        verify_hostname => 0,
    }
);

# ----- GET MONITOR ASSIGNMENTS -----
my $mon_url = "$api/agent/$agent_id/monitors";
my $get_body = encode_json({ password => $password });
my $get_resp = $ua->request(
    HTTP::Request->new(
        'GET',
        $mon_url,
        [ 'Content-Type' => 'application/json' ],
        $get_body
    )
);

die "ERROR fetching monitors: " . $get_resp->status_line . "\n"
    unless $get_resp->is_success;

my $get_data = decode_json($get_resp->decoded_content);
die "ERROR from server: $get_data->{message}\n"
    if defined $get_data->{status} && $get_data->{status} ne 'success';

my $monitors = $get_data->{monitors} || [];
if (!@$monitors) {
    print "No monitors assigned for agent $agent_id.\n";
    exit 0;
}

# ----- PING EACH MONITOR -----
my @results;
for my $mon (@$monitors) {
    my $target    = $mon->{address};
    my $protocol  = $mon->{protocol} // 'icmp';
    my $port      = $mon->{port} // 0;
    my $pollcount = ($mon->{pollcount} && $mon->{pollcount} > 0) ? $mon->{pollcount} : 20;

    my ($loss, $rtts) = pinghost($target, $protocol, $port, $pollcount);

    my ($median, $min, $max, $stddev) = rtt_stats($rtts);

    push @results, {
        monitor_id   => $mon->{id},
        loss         => $loss,
        rtts         => $rtts,
        median       => $median,
        min          => $min,
        max          => $max,
        stddev       => $stddev
    };
    printf "Monitor %s [%s]: loss %.1f%% median=%.1f ms min=%.1f ms max=%.1f ms stddev=%.2f ms\n",
        $mon->{id}, $target, $loss, $median, $min, $max, $stddev;
}

# ----- POST RESULTS -----
my $post_url = "$api/agent/$agent_id/monitors";
my $postbody = encode_json({ password => $password, results => \@results });
my $postresp = $ua->post($post_url, 'Content-Type' => 'application/json', Content => $postbody);

unless ($postresp->is_success) {
    die "ERROR: Could not POST results: " . $postresp->status_line . "\n";
}
my $pres = decode_json($postresp->decoded_content);
die "ERROR posting results: $pres->{message}\n"
    unless $pres->{status} && $pres->{status} eq 'success';

print "Results submitted successfully.\n";
exit 0;

# -------- PING FUNCTION -------
sub pinghost {
    my ($target, $protocol, $port, $count) = @_;
    $protocol = lc($protocol // 'icmp');
    $count //= 20;
    $port //= 0;

    my $p = Net::Ping->new($protocol, 2, 56);
    $p->{port_num} = $port if $protocol eq 'tcp' || $protocol eq 'udp';
    $p->hires(1);

    my @rtts;
    my $n = 0;
    for (1 .. $count) {
        my @res = $p->ping($target);
        if ($res[0]) {
            push @rtts, $res[1] * 1000;
            $n++;
        }
        sleep(0.25);
    }
    $p->close;
    my $loss = $count > 0 ? ($count - $n) / $count * 100 : 100;
    return ($loss, \@rtts);
}

# -------- PING STATISTICS -------
sub rtt_stats {
    my $rtts = shift;
    return ("U", "U", "U", "U") unless @$rtts;
    my @s = sort { $a <=> $b } @$rtts;
    my $n = @s;
    my $med = $n % 2 ? $s[$n/2] : ($s[$n/2-1]+$s[$n/2])/2;
    my $min = $s[0];
    my $max = $s[-1];
    my $avg = 0; $avg+=$_ for @s; $avg /= $n;
    my $stddev = 0;
    $stddev += ($_-$avg)**2 for @s;
    $stddev = sqrt($stddev/$n);
    return ($med, $min, $max, $stddev);
}