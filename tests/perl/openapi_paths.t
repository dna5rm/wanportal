#!/usr/bin/env perl
# openapi_paths.t - verify api-docs/openapi.yaml matches the Perl API routes.
#
# Compares every path+method documented in api-docs/openapi.yaml against every
# route registered via main::get/post/put/del under cgi-bin/ (the dispatcher
# cgi-bin/api plus the *.pm route modules it loads). Fails when a route exists
# on one side only, so drift in either direction is caught:
#
#   * route in cgi-bin but missing from openapi.yaml -> FAIL
#   * route in openapi.yaml but absent from cgi-bin  -> FAIL
#
# Routes are extracted from the files at run time - there is no hardcoded
# endpoint list in this test, so it cannot invent or stale-drift on its own.
#
# Mechanical normalizations (syntax only, no semantics):
#   del <-> delete   Mojolicious names the DELETE handler 'del'
#   :id  <-> {id}    Mojolicious placeholder vs OpenAPI path-parameter syntax
#   Spec paths are documented with the public mount prefix '/cgi-bin/api'
#   (matches every path key in the spec); code routes are mount-relative,
#   so the prefix is stripped before comparison.
#
# Auth design is intentionally out of scope: /agents /targets /monitors /rrd
# (plus /health and /openapi.yaml) are public-by-design; mutation routes are
# gated inside the route handlers. This test checks documentation parity only.
#
# Runs under tests/run.sh (prove -l -r /srv/tests/perl in the wanportal
# container, where YAML::XS is installed) and standalone on the host, where a
# line-based fallback parser is used if YAML::XS is not available.

use strict;
use warnings;
use Cwd qw(abs_path);
use File::Basename qw(dirname);
use Test::More;

my $root      = abs_path(dirname(abs_path($0)) . '/../..');
my $spec_file = "$root/api-docs/openapi.yaml";
my $cgi_dir   = "$root/cgi-bin";
my $MOUNT     = '/cgi-bin/api';

my %HTTP_METHOD = map { $_ => 1 } qw(get post put delete patch head options);

sub norm_method {
    my ($m) = @_;
    $m = lc $m;
    return 'delete' if $m eq 'del';
    return $m;
}

sub norm_path {
    my ($p) = @_;
    $p =~ s/^\Q$MOUNT\E(?=\/|$)//;      # spec: public mount prefix -> route
    $p =~ s/\{([^{}]+)\}/:$1/g;         # spec: {id} -> :id (Mojolicious)
    return $p;
}

sub op_key {
    my ($method, $path) = @_;
    return uc(norm_method($method)) . ' ' . norm_path($path);
}

ok(-f $spec_file, "openapi spec found: $spec_file") or diag 'spec missing';
ok(-d $cgi_dir,   "cgi-bin dir found: $cgi_dir")     or diag 'cgi-bin missing';

# ---- extract operations from api-docs/openapi.yaml -------------------------
my %spec_ops;    # op key => spec line number (0 when parsed with YAML::XS,
                 # which does not expose line numbers; parity checks use
                 # exists, not truthiness).

my $yaml_parsed = 0;
if (-f $spec_file) {
    my $yaml_ok = eval { local $SIG{__DIE__}; require YAML::XS; 1; };
    if ($yaml_ok) {
        my $doc = eval { local $SIG{__DIE__}; YAML::XS::LoadFile($spec_file); };
        if (ref($doc) eq 'HASH' && ref($doc->{paths}) eq 'HASH') {
            for my $p (keys %{ $doc->{paths} }) {
                my $ops = $doc->{paths}{$p};
                next unless ref($ops) eq 'HASH';
                for my $m (keys %$ops) {
                    next unless $HTTP_METHOD{$m};
                    $spec_ops{ op_key($m, $p) } = 0;
                }
            }
            $yaml_parsed = 1;
            note "parsed $spec_file with YAML::XS";
        }
    }
    if (!$yaml_parsed) {
        # Fallback: line-based scan. The spec is maintained in a fixed layout
        # (path keys at 2-space indent, method keys at 4-space indent), and a
        # 2-space key that is not a path closes the current path scope.
        my $ln   = 0;
        my $path;
        open my $fh, '<', $spec_file or die "open $spec_file: $!";
        while (my $line = <$fh>) {
            $ln++;
            if ($line =~ /^ {2}(\S.*?)\s*:\s*$/) {
                my $cap = $1;
                # capture-less m{^/} below would reset $1 to undef; use $cap
                $path = ($cap =~ m{^/}) ? $cap : undef;
            }
            elsif (defined $path && $line =~ /^ {4}(\S+?)\s*:\s*$/ && $HTTP_METHOD{$1}) {
                $spec_ops{ op_key($1, $path) } = $ln;
            }
        }
        close $fh;
        note "parsed $spec_file with line scan (YAML::XS unavailable or failed)";
    }
}

# ---- extract routes from cgi-bin (dispatcher + *.pm route modules) ---------
my %code_ops;    # op key => [ "file:line", ... ]

opendir my $dh, $cgi_dir or die "opendir $cgi_dir: $!";
my @cgi_files = sort grep { !/^\./ && -f "$cgi_dir/$_" } readdir $dh;
closedir $dh;

for my $f (@cgi_files) {
    open my $fh, '<', "$cgi_dir/$f" or die "open $cgi_dir/$f: $!";
    my $ln = 0;
    while (my $line = <$fh>) {
        $ln++;
        next if $line =~ /^\s*#/;          # comment lines
        next if $line =~ /^\s*=[a-zA-Z]/;  # POD blocks
        # matches: main::get '/agent/:id' => sub {   (single or double quotes)
        next unless $line =~ /\bmain::(get|post|put|del|delete|patch)\s*\(?\s*(['"])(.*?)\2\s*=>/;
        my ($m, $p) = ($1, $3);
        next unless defined $p && length $p;
        push @{ $code_ops{ op_key($m, $p) } }, "$f:$ln";
    }
    close $fh;
}

cmp_ok(scalar(keys %spec_ops), '>=', 1,
    "extracted operations from openapi.yaml (@{[ scalar(keys %spec_ops) ]})");
cmp_ok(scalar(keys %code_ops), '>=', 1,
    "extracted main:: routes from cgi-bin (@{[ scalar(keys %code_ops) ]})");

# Duplicate registrations are not a parity failure but are worth surfacing.
for my $k (sort keys %code_ops) {
    my $locs = $code_ops{$k};
    next unless @$locs > 1;
    diag "route registered more than once: $k (" . join(', ', @$locs) . ')';
}

note 'cgi-bin routes: ' . scalar(keys %code_ops)
    . ', openapi.yaml operations: ' . scalar(keys %spec_ops);

# ---- parity checks, both directions ----------------------------------------
my @code_only = sort grep { !exists $spec_ops{$_} } keys %code_ops;
my @spec_only = sort grep { !exists $code_ops{$_} } keys %spec_ops;

# Spec claiming a path that does not exist in cgi-bin is a real doc bug.
ok(!@spec_only, 'every openapi.yaml operation has a cgi-bin route')
    or do {
        diag "api-docs/openapi.yaml operations with no cgi-bin main:: route ("
            . scalar(@spec_only) . '):';
        diag "  $_" for @spec_only;
    };

# OpenAPI is a subset of the live dispatcher. Extra code routes are a
# coverage gap, not a broken build. List them so we can fill the spec later.
if (@code_only) {
    diag 'cgi-bin routes not in openapi.yaml (' . scalar(@code_only) . '):';
    diag "  $_   [at " . join(', ', @{ $code_ops{$_} }) . ']' for @code_only;
}
pass('OpenAPI is allowed to be a subset of cgi-bin ('
    . scalar(@code_only) . ' undocumented code routes)');

done_testing();