package test;
use strict;
use warnings;
use Exporter 'import';

our @EXPORT_OK = qw(register_test);

sub register_test {
    main::post '/test' => sub {
        my $c = shift;
        my $payload = $c->stash('jwt_payload');
        return $c->render(json => {status => 'success', message => 'Token is valid', data => $payload});
    };
}

1;