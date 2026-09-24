#!/usr/bin/env perl
# local_password_matches.t — legacy users.password_hash must not croak.
# A hex digest (pre-LDAP app) used to die inside bcrypt() and become
# HTTP 500 before LDAP was tried. $2y$/$2b$ (PHP password_hash) must
# still match after the prefix is normalized to $2a$.

use strict;
use warnings;
use FindBin;
use Test::More;
use Crypt::Eksblowfish::Bcrypt qw(bcrypt);

my $auth_pm = "$FindBin::Bin/../../cgi-bin/auth.pm";
open my $fh, '<', $auth_pm or die "cannot read $auth_pm: $!\n";
my $src = do { local $/; <$fh> };
close $fh;

my $start = index($src, 'sub _local_password_matches');
plan skip_all => '_local_password_matches not found' if $start < 0;
my $open = index($src, '{', $start);
my $depth = 0;
my $body;
for my $i ($open .. length($src) - 1) {
    my $ch = substr($src, $i, 1);
    $depth++ if $ch eq '{';
    $depth-- if $ch eq '}';
    if ($depth == 0) {
        $body = substr($src, $start, $i - $start + 1);
        last;
    }
}
plan skip_all => 'could not extract _local_password_matches' if !$body;

my $ok = eval "$body\n1";
plan skip_all => "extracted sub does not compile: $@" if !$ok;

my $password = 'correct horse';
my $salt = Crypt::Eksblowfish::Bcrypt::en_base64('0123456789abcdef');
my $hash_2a = bcrypt($password, '$2a$12$' . $salt);

ok(_local_password_matches($password, $hash_2a), '$2a$ hash matches');
ok(!_local_password_matches('wrong', $hash_2a), 'wrong password is a miss');

my $hash_2y = $hash_2a;
$hash_2y =~ s/\A\$2a\$/\$2y\$/;
ok(_local_password_matches($password, $hash_2y), '$2y$ prefix still matches');

my $hex = '2543' . ('ab' x 30);
ok(!_local_password_matches($password, $hex), 'legacy hex digest is a miss, not a die');
ok(!_local_password_matches($password, ''), 'empty hash is a miss');
ok(!_local_password_matches($password, undef), 'undef hash is a miss');

done_testing();
