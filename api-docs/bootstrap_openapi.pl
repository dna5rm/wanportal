#!/usr/bin/env perl
use strict;
use warnings;
use YAML::XS;
use File::Find;
use Data::Dumper;

# Base OpenAPI structure
my $api_spec = {
    'openapi' => '3.0.0',
    'info' => {
        'title' => 'NetOPS Monitoring API',
        'version' => '1.0.0',
        'description' => 'API for managing network monitoring agents, targets, and measurements'
    },
    'components' => {
        'securitySchemes' => {
            'bearerAuth' => {
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT'
            }
        },
        'schemas' => {
            'Error' => {
                'type' => 'object',
                'properties' => {
                    'status' => {
                        'type' => 'string',
                        'example' => 'error'
                    },
                    'message' => {
                        'type' => 'string'
                    }
                }
            },
            'Success' => {
                'type' => 'object',
                'properties' => {
                    'status' => {
                        'type' => 'string',
                        'example' => 'success'
                    },
                    'message' => {
                        'type' => 'string'
                    }
                }
            }
        }
    },
    'paths' => {}
};

# Find all Perl modules in current directory
sub scan_perl_files {
    my @files;
    find(sub {
        return unless -f && /\.pm$/;
        push @files, $File::Find::name;
    }, '.');
    return @files;
}

# Parse route definitions from Perl code
sub parse_routes {
    my ($file) = @_;
    my @routes;
    
    open my $fh, '<', $file or die "Cannot open $file: $!";
    my $content = do { local $/; <$fh> };
    close $fh;

    print STDERR "\nParsing file: $file\n";

    # Get module name for better descriptions
    my ($module) = $content =~ /package\s+(\w+);/;
    print STDERR "Found module: $module\n";
    
    # Split content into lines for debugging
    my @lines = split /\n/, $content;
    
    for (my $i = 0; $i < scalar @lines; $i++) {
        my $line = $lines[$i];
        
        # If we find a route definition
        if ($line =~ /^\s*main::(get|post|put|del)\s+['"]([^'"]+)['"]\s*=>/) {
            my ($method, $path) = ($1, $2);
            print STDERR "\nFound route at line $i: $method $path\n";
            
            # Look back for comments
            my @comments;
            my $j = $i - 1;
            while ($j >= 0 && $lines[$j] =~ /^\s*#(.*)$/) {
                my $comment = $1;
                $comment =~ s/^\s+//; # trim leading spaces
                print STDERR "Found comment: $comment\n";
                if ($comment =~ /^@(\w+)\s+(.+)$/) {
                    print STDERR "  Parsed \@$1: $2\n";
                }
                unshift @comments, $comment;
                $j--;
            }
            
            # Parse documentation comments
            my %docs;
            foreach my $comment (@comments) {
                next unless $comment =~ /^@(\w+)\s+(.+)$/;
                my ($key, $value) = ($1, $2);
                
                if ($key eq 'param') {
                    $docs{parameters} //= [];
                    push @{$docs{parameters}}, {
                        raw => $value
                    };
                }
                elsif ($key eq 'response') {
                    $docs{responses} //= [];
                    push @{$docs{responses}}, {
                        raw => $value
                    };
                }
                elsif ($key eq 'description' && exists $docs{description}) {
                    $docs{description} .= "\n" . $value;
                }
                else {
                    $docs{$key} = $value;
                }
            }

            push @routes, {
                method => $method,
                path => $path,
                docs => \%docs,
                module => $module
            };
            
            print STDERR "Documentation found:\n";
            print STDERR Data::Dumper->Dump([\%docs], ['docs']);
        }
    }
    
    return @routes;
}

sub routes_to_openapi {
    my (@routes) = @_;
    my $paths = {};

    foreach my $route (@routes) {
        my $path = $route->{path};
        $path =~ s/:(\w+)/{$1}/g;
        $path = '/cgi-bin/api' . $path;
        
        my $method = lc($route->{method});
        $method = 'delete' if $method eq 'del';

        $paths->{$path}{$method} = {
            tags => [$route->{docs}{tags} || $route->{module}],
            summary => $route->{docs}{summary} || "Route: $route->{path}",
            description => $route->{docs}{description} || '',
            parameters => [],
            responses => {
                '200' => {
                    description => 'Successful operation'
                }
            }
        };

        # Add security by default
        $paths->{$path}{$method}{security} = [{ bearerAuth => [] }];
    }

    return $paths;
}

# Helper functions
sub max {
    my ($a, $b) = @_;
    return $a > $b ? $a : $b;
}

sub min {
    my ($a, $b) = @_;
    return $a < $b ? $a : $b;
}

# Main execution
my @perl_files = scan_perl_files();
my @all_routes;

foreach my $file (@perl_files) {
    push @all_routes, parse_routes($file);
}

$api_spec->{paths} = routes_to_openapi(@all_routes);

# Output OpenAPI spec as YAML
print YAML::XS::Dump($api_spec);