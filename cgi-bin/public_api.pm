package public_api;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use JSON qw(encode_json);
use RRDs;
use File::Temp;

our @EXPORT_OK = qw(register_public_endpoints);

sub register_public_endpoints {
    my ($db_config) = @_;

    # GET /agents - List agents (without passwords)
    main::get '/agents' => sub {
        my $c = shift;
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        my $sth = $dbh->prepare(q{
            SELECT 
                id, name, address, description, last_seen, is_active
            FROM agents 
            ORDER BY name
        });
        $sth->execute();
        my $agents = $sth->fetchall_arrayref({});
        $dbh->disconnect;
        
        return $c->render(json => {
            status => 'success',
            agents => $agents
        });
    };

    # GET /targets - List targets
    main::get '/targets' => sub {
        my $c = shift;
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        my $sth = $dbh->prepare(q{
            SELECT 
                id, address, description, is_active
            FROM targets 
            ORDER BY address
        });
        $sth->execute();
        my $targets = $sth->fetchall_arrayref({});
        $dbh->disconnect;
        
        return $c->render(json => {
            status => 'success',
            targets => $targets
        });
    };

    # GET /monitors - List monitors
    main::get '/monitors' => sub {
        my $c = shift;
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        
        my $sth = $dbh->prepare(q{
            SELECT 
                m.id, m.description, m.agent_id, m.target_id,
                m.protocol, m.port, m.dscp, m.is_active,
                m.current_loss, m.current_median,
                m.avg_loss, m.avg_median,
                m.last_update,
                a.name as agent_name,
                t.address as target_address
            FROM monitors m
            JOIN agents a ON m.agent_id = a.id
            JOIN targets t ON m.target_id = t.id
            ORDER BY m.description
        });
        $sth->execute();
        my $monitors = $sth->fetchall_arrayref({});
        $dbh->disconnect;
        
        return $c->render(json => {
            status => 'success',
            monitors => $monitors
        });
    };

    # GET /rrd - RRD data access and graph generation
    main::get '/rrd' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $cmd = $c->param('cmd') // '';
        my $ds = $c->param('ds') // 'rtt';  # Default to RTT if not specified
        my $timeframe = $c->param('timeframe') // '7h';  # Default 7 hours if not specified
        
        # Validate monitor ID
        return $c->render(json => {
            status => 'error',
            message => 'Monitor ID required'
        }, status => 400) unless $id;

        my $rrdfile = "/var/rrd/$id.rrd";
        
        # Check if RRD file exists
        return $c->render(json => {
            status => 'error',
            message => 'RRD file not found'
        }, status => 404) unless -f $rrdfile;

        # If cmd=graph, generate and return PNG
        if ($cmd eq 'graph') {
            my ($tmpfh, $tmpfile) = File::Temp::tempfile(
                'rrdgraph_XXXXXX',
                DIR => '/tmp',
                SUFFIX => '.png',
                UNLINK => 1
            );

            my @graph_opts = (
                $tmpfile,
                '--start', "-$timeframe",
                '--width', '600',
                '--height', '100',
                '--title', ($ds eq 'rtt' ? 'Response Time' : 'Packet Loss'),
                '--vertical-label', ($ds eq 'rtt' ? 'milliseconds' : 'percent'),
                '--slope-mode',
                '--alt-autoscale',
                '--rigid',
                '--lower-limit', '0',
                'DEF:data=' . $rrdfile . ':' . $ds . ':LAST',
                'LINE3:data#FF0000:' . ($ds eq 'rtt' ? 'Latency' : 'Loss')
            );

            # Add upper limit for loss graphs
            push @graph_opts, '--upper-limit', '100' if $ds eq 'loss';

            RRDs::graph(@graph_opts);
            if (my $err = RRDs::error()) {  # Fixed error check
                return $c->render(json => {
                    status => 'error',
                    message => "Failed to generate graph: $err"
                }, status => 500);
            }

            # Read the generated PNG
            open my $fh, '<', $tmpfile or die "Cannot open temp file: $!";
            binmode $fh;
            my $png_data = do { local $/; <$fh> };
            close $fh;

            # Return PNG image
            return $c->render(
                data => $png_data,
                format => 'png'
            );
        }
        
        # If no cmd specified, dump RRD data as JSON
        my ($start, $step, $names, $data) = RRDs::fetch(
            $rrdfile,
            'LAST',
            '--start', "-$timeframe"
        );
        
        if (my $err = RRDs::error()) {  # Fixed error check
            return $c->render(json => {
                status => 'error',
                message => "Failed to fetch RRD data: $err"
            }, status => 500);
        }

        # Format data for JSON response
        my @formatted_data;
        my $time = $start;
        foreach my $line (@$data) {
            push @formatted_data, {
                timestamp => $time,
                rtt => $line->[0],
                loss => $line->[1]
            };
            $time += $step;
        }

        return $c->render(json => {
            status => 'success',
            start_time => $start,
            step => $step,
            data => \@formatted_data
        });
    };
}

1;