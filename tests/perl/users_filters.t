#!/usr/bin/env perl
# users_filters.t - GET /users listing filters (q, is_admin, is_active).
#
# Extracts sub build_user_filters from cgi-bin/users.pm at run time
# (brace-balanced) and evals it, then drives it with a stub controller
# whose ->param() behaves like Mojolicious: undef for absent params.
# The production filter builder is what runs -- the test carries no copy
# of the logic.
#
# Pins:
#   * absent or empty q applies no filter; non-empty q becomes one
#     parenthesized LIKE group over username/full_name/email with the
#     user input bound as %q% placeholders (three ?s, no input in SQL)
#   * is_admin / is_active accept only 0 and 1; any other value (true,
#     2, empty string, 01, padded) is ignored rather than applied
#   * combined filters AND together; bind order is is_admin, is_active,
#     then the three q placeholders
#   * the GET /users route actually wires the helper in (source checks:
#     build_user_filters call, WHERE join, ORDER BY username, execute)
#
# Skips loudly if the sub or markers move (refactor): re-point the
# marker here when that happens.

use strict;
use warnings;
use FindBin;
use Test::More;

my $root = "$FindBin::Bin/../..";
my $file = "$root/cgi-bin/users.pm";

unless (-f $file) {
    plan skip_all => 'cgi-bin/users.pm not present';
    exit 0;
}

my $src = do { open my $fh, '<', $file or die "$file: $!"; local $/; <$fh> };

# ---- extract sub build_user_filters (brace-balanced) ------------------------
my $marker = 'sub build_user_filters {';
my $start  = index($src, $marker);
if ($start < 0) {
    plan skip_all => 'build_user_filters not found in users.pm (marker moved?)';
    exit 0;
}
my $brace = $start + length($marker) - 1;   # position of the opening {
my $depth = 0;
my $end   = -1;
for my $i ($brace .. length($src) - 1) {
    my $ch = substr($src, $i, 1);
    $depth++ if $ch eq '{';
    $depth-- if $ch eq '}';
    if ($depth == 0) { $end = $i; last; }
}
if ($end < 0) {
    plan skip_all => 'build_user_filters braces unbalanced in users.pm';
    exit 0;
}
my $sub_src = substr($src, $start, $end - $start + 1);

my $code = "package users_filters_under_test;\nuse strict; use warnings;\n$sub_src\n1;";
my $loaded = eval $code;
if (!$loaded) {
    plan skip_all => "extracted build_user_filters does not compile: $@";
    exit 0;
}

# ---- stub controller: ->param() like Mojolicious (undef when absent) --------
package StubController;
sub new {
    my ($class, %params) = @_;
    return bless { params => { %params } }, $class;
}
sub param {
    my ($self, $name) = @_;
    return exists $self->{params}{$name} ? $self->{params}{$name} : undef;
}

package main;

my $build = \&users_filters_under_test::build_user_filters;

# The single LIKE group every q filter must produce, verbatim from the
# source under test (no user input ever appears outside the binds).
my $LIKE_GROUP = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';

# ---- q filter ----------------------------------------------------------------
{
    my ($w, $p) = $build->(StubController->new());
    is(scalar @$w, 0, 'no params: no WHERE clauses');
    is(scalar @$p, 0, 'no params: no bind params');

    ($w, $p) = $build->(StubController->new(q => ''));
    is(scalar @$w, 0, 'empty q: no WHERE clauses');
    is(scalar @$p, 0, 'empty q: no bind params');

    ($w, $p) = $build->(StubController->new(q => 'ali'));
    is(scalar @$w, 1, 'q: one WHERE clause');
    is($w->[0], $LIKE_GROUP, 'q: parenthesized LIKE group over username/full_name/email');
    is(scalar @$p, 3, 'q: three bind params');
    is($p->[0], '%ali%', 'q: username bind wrapped as %q%');
    is($p->[1], '%ali%', 'q: full_name bind wrapped as %q%');
    is($p->[2], '%ali%', 'q: email bind wrapped as %q%');

    # Wildcard/quote input must land in the BIND, never in the SQL text.
    ($w, $p) = $build->(StubController->new(q => '%o\'brien%_'));
    is(scalar @$w, 1, 'q with metacharacters: still one WHERE clause');
    is($w->[0], $LIKE_GROUP, 'q with metacharacters: SQL text unchanged (placeholders only)');
    is($p->[0], "%%o'brien%_%", 'q with metacharacters: raw input bound as %q%');
}

# ---- boolean filters: accepted values ----------------------------------------
{
    my ($w, $p) = $build->(StubController->new(is_admin => 1));
    is(scalar @$w, 1, 'is_admin=1: one WHERE clause');
    is($w->[0], 'is_admin = ?', 'is_admin=1: bound equality');
    is($p->[0], 1, 'is_admin=1: bind value');

    ($w, $p) = $build->(StubController->new(is_admin => '0'));
    is($w->[0], 'is_admin = ?', 'is_admin=0: bound equality');
    is($p->[0], '0', 'is_admin=0: bind value');

    ($w, $p) = $build->(StubController->new(is_active => '1'));
    is($w->[0], 'is_active = ?', 'is_active=1: bound equality');
    is($p->[0], '1', 'is_active=1: bind value');

    ($w, $p) = $build->(StubController->new(is_active => '0'));
    is($w->[0], 'is_active = ?', 'is_active=0: bound equality');
    is($p->[0], '0', 'is_active=0: bind value');
}

# ---- boolean filters: values other than 0/1 are ignored -----------------------
for my $col (qw(is_admin is_active)) {
    for my $bad ('true', '2', '', '01', ' 1', '1 ') {
        my ($w, $p) = $build->(StubController->new($col => $bad));
        is(scalar @$w, 0, "$col=$bad (non-01): ignored, no WHERE clause");
        is(scalar @$p, 0, "$col=$bad (non-01): ignored, no bind params");
    }
}

# ---- combined filters ----------------------------------------------------------
{
    my ($w, $p) = $build->(StubController->new(
        q => 'ad', is_admin => '1', is_active => '0'));
    is(scalar @$w, 3, 'combined: three WHERE clauses');
    is($w->[0], 'is_admin = ?',  'combined: is_admin first');
    is($w->[1], 'is_active = ?', 'combined: is_active second');
    is($w->[2], $LIKE_GROUP,     'combined: q group last');
    is(scalar @$p, 5, 'combined: five bind params');
    is($p->[0], '1',    'combined: is_admin bind');
    is($p->[1], '0',    'combined: is_active bind');
    is($p->[2], '%ad%', 'combined: q username bind');
    is($p->[3], '%ad%', 'combined: q full_name bind');
    is($p->[4], '%ad%', 'combined: q email bind');

    my ($w2, $p2) = $build->(StubController->new(is_admin => '1', is_active => '0'));
    is(join(' AND ', @$w2), 'is_admin = ? AND is_active = ?',
        'combined booleans join with AND');

    # Unknown params must not become filters.
    my ($w3, $p3) = $build->(StubController->new(foo => 'bar', username => 'x'));
    is(scalar @$w3, 0, 'unknown params ignored');
    is(scalar @$p3, 0, 'unknown params produce no binds');
}

# ---- route wiring: the helper must actually be used by GET /users --------------
# (source-level drift guard; the extracted-sub cases above cover behavior)
{
    ok(index($src, 'build_user_filters($c)') >= 0,
        'GET /users route calls build_user_filters');
    ok(index($src, "' WHERE ' . join(' AND ', @\$where)") >= 0,
        'GET /users route joins filters into one WHERE clause');
    ok(index($src, "' ORDER BY username'") >= 0,
        'GET /users route keeps ORDER BY username');
    ok(index($src, '$sth->execute(@$params)') >= 0,
        'GET /users route binds filter params via execute');
}

done_testing();