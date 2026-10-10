=head1 NAME

agent_checkin - parse the agent version declared at an agent check-in

=head1 SYNOPSIS

    # imported by the agent-facing modules, not the dispatcher
    use agent_checkin qw(parse_agent_ua_version);
    my $version = parse_agent_ua_version($c->req->headers->user_agent);
    # $version is the validated, storage-ready version or undef

=head1 DESCRIPTION

The version an agent reports is a property of the check-in envelope, not
of any payload: every agent generation sends its version in the HTTP
User-Agent header on every request it makes ("NetPing-Agent/<version>",
set once at LWP construction). _validate_agent in both agent modules
(cgi-bin/agent_monitors.pm, cgi-bin/agent_services.pm) parses it here and
records it on the agent row it just authenticated, wherever the agent's
identity is validated. Nothing from a request body is ever recorded.

Grammar (accepted header line, case-sensitive, full-string anchored):

    NetPing-Agent/<version>

=over

=item * Surrounding HTTP OWS whitespace is trimmed before matching;
interior whitespace, a second token, or anything else rejects.

=item * Version charset: first character [0-9A-Za-z], then
[0-9A-Za-z._~-], 1 to 64 characters. No interior whitespace; the full
match is the version.

=item * The returned value is pre-truncated to 32 characters: the
agents.agent_version column is varchar(32).

=back

An absent header, an unparsable one, or a non-agent User-Agent (curl, a
script, a browser) yields undef, which the caller must treat as "write
nothing": a stored version is refreshed, never cleared - never written ''
or NULL. This mirrors the address rule in _validate_agent ("with no valid
address, only last_seen is refreshed"): a transient bad request cannot
erase a known-good version.

Strictness is deliberate. A future agent library that appends platform
suffixes ("NetPing-Agent/0.3.0 (linux)") stops updating and keeps its
last good value - the failure mode is stale, never erased. Relaxing to a
prefix match later changes nothing already stored, so start strict. Case
is not guessed: the agents emit exact casing; do not accept variants.

The I1 amendment this module embodies: agent_monitors.pm remains
response-identical and behaviorally untouched except for the additive,
envelope-derived, set-only-when-present version clause inside
_validate_agent - the only way a legacy 0.1.0 monitor-only agent can ever
report a version, since /agent/:id/monitors is the only endpoint it
contacts. Absent header degrades byte-for-byte to the pre-amendment
behavior; the monitor endpoint's responses never change.

=head1 SEE ALSO

cgi-bin/agent_monitors.pm, cgi-bin/agent_services.pm,
/home/deaves/wanportal-agent-version-contract.md (sections 4-6).

=cut

package agent_checkin;
use strict;
use warnings;
use Exporter 'import';

our @EXPORT_OK = qw(parse_agent_ua_version);

# The whole rule in one pattern: anchored (\A...\z), case-sensitive, first
# char alphanumeric, then the token charset, 1-64 chars total. Kept as one
# shared copy on purpose (contract D5): two private copies of a protocol
# constant drift, and a split-brain charset along the monitor/services
# line would produce exactly that. Returns the version truncated to 32
# (varchar(32)) or undef - undef means "record nothing", never "clear".
sub parse_agent_ua_version {
    my ($ua) = @_;
    return undef unless defined $ua;
    $ua =~ s/^\s+|\s+$//g;
    return undef
        unless $ua =~ /\ANetPing-Agent\/([0-9A-Za-z][0-9A-Za-z._~-]{0,63})\z/;
    return substr($1, 0, 32);
}

1;