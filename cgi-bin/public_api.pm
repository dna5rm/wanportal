package public_api;
use strict;
use warnings;
use Exporter 'import';
use DBI;
use JSON qw(encode_json);
use RRDs;
use File::Temp;
use Time::Local;
use Date::Parse qw(str2time);
use POSIX qw(strftime);

our @EXPORT_OK = qw(register_public_endpoints);
my $datadir = '/var/rrd';  # RRD storage directory

sub register_public_endpoints {
    my ($db_config) = @_;

    # @summary List all agents
    # @description Returns a list of all monitoring agents in the system without sensitive information.
    # Passwords are never included in the response.
    # @tags Public API
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

    # @summary List all targets
    # @description Returns a list of all monitoring targets in the system.
    # @tags Public API
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

    # @summary List all monitors
    # @description Returns a list of all monitors with their current status and configuration.
    # Supports filtering by status and activity state.
    # @tags Public API
    main::get '/monitors' => sub {
        my $c = shift;
        
        # Get query parameters
        my $current_loss = $c->param('current_loss');
        my $is_active = $c->param('is_active');
        
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });

        # Start building the SQL query
        my $sql = q{
            SELECT
                m.id, m.description, m.agent_id, m.target_id,
                m.protocol, m.port, m.dscp, m.pollcount, m.pollinterval,
                CASE 
                    WHEN m.is_active = 0 OR a.is_active = 0 OR t.is_active = 0 THEN 0 
                    ELSE 1 
                END as is_active,
                m.sample, m.current_loss, m.current_median,
                m.current_min, m.current_max, m.current_stddev,
                m.avg_loss, m.avg_median, m.avg_min, m.avg_max,
                m.avg_stddev, m.prev_loss, m.last_clear, m.last_down,
                m.last_update, m.total_down,
                a.name as agent_name,
                a.is_active as agent_is_active,
                t.address as target_address,
                t.is_active as target_is_active
            FROM monitors m
            JOIN agents a ON m.agent_id = a.id
            JOIN targets t ON m.target_id = t.id
            WHERE 1=1
        };

        my @params;

        # Add query conditions if parameters are provided
        if (defined $current_loss) {
            $sql .= " AND m.current_loss = ?";
            push @params, $current_loss;
        }
        
        if (defined $is_active) {
            $sql .= " AND (CASE 
                WHEN m.is_active = 0 OR a.is_active = 0 OR t.is_active = 0 THEN 0 
                ELSE 1 
            END) = ?";
            push @params, $is_active;
        }

        $sql .= " ORDER BY m.description";

        my $sth = $dbh->prepare($sql);
        $sth->execute(@params);
        my $monitors = $sth->fetchall_arrayref({});
        $dbh->disconnect;

        return $c->render(json => {
            status => 'success',
            monitors => $monitors
        });
    };

    # @summary Get RRD data
    # @description Retrieves monitoring data from RRD files. Can return raw data or generate graphs.
    # Supports both RTT and loss metrics with customizable time ranges.
    # @tags Public API
    main::get '/rrd' => sub {
        my $c = shift;
        my $id = $c->param('id');
        my $cmd = $c->param('cmd') // '';
        my $ds = $c->param('ds') // 'rtt';
        my $start = $c->param('start');
        my $end = $c->param('end');
        
        # Validate monitor ID
        return $c->render(json => {
            status => 'error',
            message => 'Monitor ID required'
        }, status => 400) unless $id;

        # Get monitor description from database
        my $dbh = DBI->connect(@{$db_config}{qw/dsn username password/}, { RaiseError => 1, AutoCommit => 1 });
        my $sth = $dbh->prepare("SELECT description FROM monitors WHERE id = ?");
        $sth->execute($id);
        my $monitor = $sth->fetchrow_hashref();
        $dbh->disconnect;
        
        my $metric_type = $ds eq 'rtt' ? 'Response Time: ' : 'Packet Loss: ';
        my $monitor_name = ($monitor && $monitor->{description}) ? $monitor->{description} : $id;
        my $title = $metric_type . $monitor_name;
        my $rrdfile = "$datadir/$id.rrd";
        
        # Check if RRD file exists
        return $c->render(json => {
            status => 'error',
            message => 'RRD file not found'
        }, status => 404) unless -f $rrdfile;

        # Convert datetime parameters to epoch
        if ($start) {
            $start =~ s/T/ /;
            $start = str2time($start);
        } else {
            $start = time() - (3 * 3600); # Default 3 hours ago
        }
        
        if ($end) {
            $end =~ s/T/ /;
            $end = str2time($end);
        } else {
            $end = time(); # Default to now
        }

        # If cmd=graph, generate and return PNG
        if ($cmd eq 'graph') {
            my ($tmpfh, $tmpfile) = File::Temp::tempfile(
                'rrdgraph_XXXXXX',
                DIR => '/tmp',
                SUFFIX => '.png',
                UNLINK => 1
            );

            # Use UTC for all operations
            my $hours = int(($end - $start) / 3600);
            
            # Format time in UTC
            my $display_time = strftime("%Y/%m/%d %H\\:%M UTC", gmtime($start));

            my @graph_opts = (
                $tmpfile,
                '--start', $start,
                '--end', $end,
                '--width', '600',
                '--height', '105',
                '--color', 'BACK#F3F3F3',
                '--color', 'CANVAS#FDFDFD',
                '--color', 'SHADEA#CBCBCB',
                '--color', 'SHADEB#999999',
                '--color', 'FONT#000000',
                '--color', 'AXIS#2C4D43',
                '--color', 'ARROW#2C4D43',
                '--color', 'FRAME#2C4D43',
                '--font', 'TITLE:10:Arial',
                '--font', 'AXIS:8:Arial',
                '--font', 'LEGEND:9:Courier',
                '--font', 'UNIT:8:Arial',
                '--font', 'WATERMARK:7:Arial',
                '--border', '1',
                '--title', $title,
                '--vertical-label', ($ds eq 'rtt' ? 'milliseconds' : 'percent'),
                '--slope-mode',
                '--alt-autoscale',
                '--rigid',
                '--lower-limit', '0'
            );

            # Add upper limit for loss graphs
            push @graph_opts, '--upper-limit', '100' if $ds eq 'loss';

            push @graph_opts, (
                'DEF:data=' . $rrdfile . ':' . $ds . ':LAST',
                'LINE3:data#FF0000:' . ($ds eq 'rtt' ? 'Latency' : 'Loss'),
                'GPRINT:data:AVERAGE:Avg\\: %6.2lf',
                'GPRINT:data:MIN:Min\\: %6.2lf',
                'GPRINT:data:MAX:Max\\: %6.2lf',
                'GPRINT:data:LAST:Last\\: %6.2lf\\j',
                'COMMENT:' . $display_time . ' (+' . $hours . ' hours)\\r'
            );

            RRDs::graph(@graph_opts);
            if (my $err = RRDs::error()) {
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
        my ($fetch_start, $step, $names, $data) = RRDs::fetch(
            $rrdfile,
            'LAST',
            '--start', $start,
            '--end', $end
        );
        
        if (my $err = RRDs::error()) {
            return $c->render(json => {
                status => 'error',
                message => "Failed to fetch RRD data: $err"
            }, status => 500);
        }

        # Format data for JSON response
        my @formatted_data;
        my $time = $fetch_start;
        foreach my $line (@$data) {
            push @formatted_data, {
                timestamp => $time,
                datetime => strftime("%Y-%m-%d %H:%M:%S", localtime($time)),
                loss => $line->[0],
                rtt => $line->[1]
            };
            $time += $step;
        }

        return $c->render(json => {
            status => 'success',
            start_time => $fetch_start,
            end_time => $time - $step,
            step => $step,
            data => \@formatted_data
        });
    };
}

# Helper function to validate datetime format
sub is_valid_datetime {
    my $dt = shift;
    return $dt =~ /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
}

1;