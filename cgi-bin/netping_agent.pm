=head1 NAME

netping_agent - serve the netping-agent.pl source to JWT callers (SPA)

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, inside the JWT group
    use netping_agent qw(register_netping_script);
    register_netping_script();

=head1 DESCRIPTION

htdocs/classic/netping.php hands the remote agent source to classic users by
reading /srv/agent/netping-agent.pl straight off disk (file_get_contents).
The SPA cannot read server paths, so GET /netping-script returns the
same file to authenticated API clients as JSON:

    { "status": "success", "filename": "netping-agent.pl", "content": "..." }

The route is registered inside the JWT group in cgi-bin/api, so the
bearer-token gate is enforced by auth_middleware (401 without a valid
token), the API counterpart of the classic session gate on
netping.php. A missing or unreadable script answers 404 with a plain
error message.

The file body is never logged: the handler contains no app->log call
at all, so the source can only ever reach the authenticated caller.

=cut

package netping_agent;
use strict;
use warnings;
use Exporter 'import';

our @EXPORT_OK = qw(register_netping_script);

sub register_netping_script {
    # Optional argument: script path override (tests inject a temp
    # file). Default is the same on-disk path htdocs/classic/netping.php
    # reads - /srv/agent/netping-agent.pl, outside the webroot.
    my ($filename) = @_;
    $filename = '/srv/agent/netping-agent.pl' unless defined $filename && length $filename;

    # @summary Get netping-agent.pl source
    # @description Returns the source of the remote ping agent script for display in the SPA. Requires a valid bearer token.
    # @tags Agents
    # @security bearerAuth
    main::get '/netping-script' => sub {
        my $c = shift;

        unless (-f $filename) {
            return $c->render(
                json   => { status => 'error', message => 'netping-agent.pl not found' },
                status => 404,
            );
        }

        open my $fh, '<', $filename or do {
            return $c->render(
                json   => { status => 'error', message => 'netping-agent.pl not readable' },
                status => 404,
            );
        };
        binmode $fh, ':raw';
        local $/;
        my $content = <$fh>;
        close $fh;
        $content = '' unless defined $content;

        # The script file is UTF-8 text. The slurp above reads raw octets,
        # so decode them in place as UTF-8 before handing the string to
        # the JSON layer: an octet string is treated as Latin-1 characters
        # by the encoder, which would double-encode every non-ASCII byte
        # (C2 A7 for section sign leaves the server as C3 82 C2 A7).
        # Decoding first makes the JSON re-encode each character exactly
        # once, so the served bytes match the file on disk. If the body
        # is not valid UTF-8 utf8::decode leaves it untouched and the
        # response simply degrades to the historical byte-as-Latin-1
        # behaviour; pure ASCII is unaffected either way.
        utf8::decode($content);

        # No logging here, deliberately: the file body is secret-ish
        # source material and must never land in container logs - it
        # is only ever rendered to this authenticated caller.

        return $c->render(
            json => {
                status   => 'success',
                filename => 'netping-agent.pl',
                content  => $content,
            }
        );
    };
}

1;