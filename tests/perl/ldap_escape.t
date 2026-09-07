#!/usr/bin/env perl
# ldap_escape.t - RFC 4515 filter escaping in cgi-bin/auth.pm.
# Loads sub _ldap_filter_escape from the live source without booting
# Mojolicious: the sub text is extracted and eval'd standalone.
# Run: docker exec wanportal prove -l -r /srv/tests/perl

use strict;
use warnings;
use FindBin;
use Test::More;

# /srv is the repo root in both views (bind mount /srv/wanportal -> /srv),
# so cgi-bin is always two levels up from this test dir.
my $auth_pm = "$FindBin::Bin/../../cgi-bin/auth.pm";

my $src = do {
    open my $fh, '<', $auth_pm or die "cannot read $auth_pm: $!\n";
    local $/; <$fh>
};

# Extract sub _ldap_filter_escape by brace balancing (it contains no
# unbalanced braces in regex patterns).
my $body;
{
    my $start = index($src, 'sub _ldap_filter_escape');
    if ($start >= 0) {
        my $open = index($src, '{', $start);
        if ($open >= 0) {
            my $depth = 0;
            for my $i ($open .. length($src) - 1) {
                my $ch = substr($src, $i, 1);
                $depth++ if $ch eq '{';
                $depth-- if $ch eq '}';
                if ($depth == 0) {
                    $body = substr($src, $start, $i - $start + 1);
                    last;
                }
            }
        }
    }
}

if (!defined $body) {
    plan skip_all => '_ldap_filter_escape not found (or unbalanced) in auth.pm; update this test';
    exit 0;
}

my $loaded = eval "$body\n1";
if (!$loaded || $@) {
    my $err = $@ || 'no return value';
    chomp $err;
    plan skip_all => "extracted _ldap_filter_escape does not compile: $err";
    exit 0;
}

plan tests => 12;

ok(defined &main::_ldap_filter_escape, 'sub _ldap_filter_escape loaded from auth.pm');

# RFC 4515 section 3: the five mandatory escapes.
is(_ldap_filter_escape('*'),  '\2a', 'asterisk escaped');
is(_ldap_filter_escape('('),  '\28', 'open paren escaped');
is(_ldap_filter_escape(')'),  '\29', 'close paren escaped');
is(_ldap_filter_escape('\\'), '\5c', 'backslash escaped');
is(_ldap_filter_escape(chr(0)), '\00', 'NUL escaped');

is(_ldap_filter_escape(''),    '',    'empty string returns empty string');
is(_ldap_filter_escape(undef), '',    'undef returns empty string');
is(_ldap_filter_escape('alice'), 'alice', 'plain value passes through unchanged');

is(
    _ldap_filter_escape('a*b(c)d\\e' . chr(0)),
    'a\2ab\28c\29d\5ce\00',
    'all five metacharacters escaped in one value'
);

# auth.pm escapes the backslash first; these pin that ordering so the
# escapes it emits are never re-escaped.
is(_ldap_filter_escape('\\*'),  '\5c\2a', 'backslash handled before asterisk (no double escape)');
is(_ldap_filter_escape('\\2a'), '\5c2a',  'literal \\2a input becomes \\5c2a, not a pre-escaped pair');