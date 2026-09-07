=head1 NAME

test - JWT smoke-test endpoint

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, inside the JWT group
    use test qw(register_test);

=head1 DESCRIPTION

C<POST /test> does nothing except require the JWT middleware and
echo the decoded token payload back. Handy for checking that a
token is valid and what flags it carries.

=cut

package test;
use strict;
use warnings;
use Exporter 'import';

our @EXPORT_OK = qw(register_test);

sub register_test {

    # @summary Verify authentication
    # @description Test endpoint to verify if a JWT token is valid.
    # @tags Authentication
    # @security bearerAuth
    main::post '/test' => sub {
        my $c = shift;
        my $payload = $c->stash('jwt_payload');
        return $c->render(json => {status => 'success', message => 'Token is valid', data => $payload});
    };
}

1;