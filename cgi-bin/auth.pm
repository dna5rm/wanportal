package auth;
use strict;
use warnings;
use Exporter 'import';
use Crypt::JWT qw(encode_jwt decode_jwt);
use Digest::SHA qw(sha256_hex);
use DBI;
use users qw(validate_user);

our @EXPORT_OK = qw(auth_middleware register_login);

sub auth_middleware {
    my ($config) = @_;
    my $jwt_secret = $config->{jwt_secret};
    return sub {
        my $c = shift;
        my $auth_header = $c->req->headers->authorization;
        unless ($auth_header && $auth_header =~ /^Bearer\s+(.+)/) {
            $c->render(json => {status => 'error', message => 'Missing or invalid token'}, status => 401);
            return undef;
        }
        my $token = $1;
        my $payload;
        eval {
            $payload = decode_jwt(token => $token, key => $jwt_secret, alg => 'HS256');
        };
        if ($@ || $payload->{exp} < time) {
            $c->render(json => {status => 'error', message => 'Invalid or expired token'}, status => 401);
            return undef;
        }
        $c->stash(jwt_payload => $payload);
        return 1;
    };
}

sub register_login {
    my ($db_config) = @_;
    main::post '/login' => sub {
        my $c = shift;
        $c->app->log->debug("Received request for /login");

        my $data = $c->req->json;
        unless (defined $data && exists $data->{username} && exists $data->{password}) {
            return $c->render(json => {status => 'error', message => 'Invalid input'}, status => 400);
        }
        my ($username, $password) = ($data->{username}, $data->{password});
        my $dbh = DBI->connect(
            $db_config->{dsn},
            $db_config->{username},
            $db_config->{password},
            { RaiseError => 1, AutoCommit => 1 }
        );

        my $valid = validate_user($dbh, $username, $password);
        $dbh->disconnect if $dbh;

        if ($valid) {
            my $jwt_secret = $c->app->defaults('jwt_secret');
            my $token = encode_jwt(
                payload => { username => $username, exp => time + 3600 },
                key     => $jwt_secret,
                alg     => 'HS256'
            );
            return $c->render(json => {status => 'success', token => $token});
        }
        return $c->render(json => {status => 'error', message => 'Invalid username or password'}, status => 401);
    };
}

1;