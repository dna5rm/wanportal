# Agent image

The probe agent ships as a self-contained cron container: `agent/Dockerfile`,
built by `agent/build_agent.sh` from the repository root. The container probes its
assigned monitors once per minute and posts loss and latency measurements
back to a wanportal server.

## Image contents

- `alpine:3.21` (pinned to 3.21.3 in the Dockerfile) with `cronie` and
  `tzdata`
- Perl plus `perl-libwww` (LWP), `perl-lwp-protocol-https` (HTTPS support
  for LWP), `perl-io-socket-ssl`, and `perl-json`
- Two files from the repository: `agent/netping-agent.pl` and its cron
  wrapper, `agent/run-agent.sh` (installed at `/srv/agent/` in the image)

Nothing else ships. `agent/netping-legacy.pl` and `agent/socket-agent.pl` are
not included in the image; both run from a repository checkout with the host
Perl installation when needed (`agent/socket-agent.pl` is the variant that marks
packets with DSCP/TOS reliably). The image also omits `curl`
and `jq`; API requests are issued from outside the container.

The agent does not verify TLS certificates: `netping-agent.pl` sets
`SSL_VERIFY_NONE` intentionally, because agents are permitted to
communicate with a server that presents a self-signed certificate.

## Service checks

The image carries the agent's services capability: after the
reachability cycle it polls `GET /agent/:id/services` for due HTTP(S)
checks and POSTs the results to the same path, authenticated with the
same agent password as the monitor endpoints. Both request bodies
declare `supports_services` and the agent `version`; the portal
records the declaration only from the result POST - the services GET
never records it. Against an older portal the services fetch answers
404: the agent logs it and finishes the cycle, leaving reachability
polling untouched.

HTTPS service checks depend on `perl-lwp-protocol-https` (already
declared in `agent/Dockerfile` beside `perl-libwww`): without the
protocol handler LWP fails every `https://` probe with a bogus 501
rather than a TLS error. The wanportal stack image ships the same pair
because the LOCAL agent runs inside it, so rebuilding the agent image
needs no extra packages for service checks.

## Building and distributing the image

`agent/build_agent.sh`, run from the repository root, tags the image
`netping:<agent version>` (read from the `our $VERSION` line of
`agent/netping-agent.pl` at build time), `netping:<YYYYMMDD>`, and
`netping:latest`, then writes `htdocs/assets/netping_latest.tar.gz`
from the version tag together with `latest`. The archive carries both
tags, so `docker load` restores both. The archive is gitignored and is
offered as a download on the dashboard's netping page
(`/assets/netping_latest.tar.gz`).

On the target host:

```sh
gunzip -c netping_latest.tar.gz | docker load
```

## Running the container

```sh
docker run -d --name netping-agent --network host --restart unless-stopped \
    -e SERVER="https://<SERVER>/cgi-bin/api" \
    -e PASSWORD="<PASSWORD>" -e AGENT_ID="<AGENT_ID>" \
    netping:<agent version>
```

Run the version tag: `docker load` above prints exactly which tags it
restored, and the version one states which agent the container
carries. `netping:latest` (also in the archive, as the alias) works
but hides the version; the dashboard's netping page renders its run
command with the version tag it parses from the agent script.

- `--network host`: probes originate from the host's own network stack, so
  the measurements reflect what the host observes and the agent's source
  address is the host's. The agent must be created in the dashboard first;
  `AGENT_ID` is that agent's identifier.
- Configuration is environment-only: `SERVER` (the API base URL),
  `PASSWORD`, and `AGENT_ID`, plus `DEBUG=1` for verbose logging.
  `AGENT_ID` is the only variable without a default: the wrapper exits
  nonzero when it is unset. When `PASSWORD` or `SERVER` are unset, the
  wrapper falls back to `LOCAL` and `http://localhost/cgi-bin/api`,
  respectively.
- `crond` runs `run-agent.sh` every minute. The wrapper reads the variables
  from a mounted `/srv/.env` when present, and otherwise from
  `/proc/1/environ` (cron jobs do not inherit the container environment).
  It also holds a single-instance lock, so an overlapping cron tick exits
  quietly and the next tick takes over.
- A healthcheck confirms that `crond` is running and that the agent script
  is executable; the container provides no HTTP endpoint for
  health probes.

Verify the container with `docker ps | grep netping-agent`. Cron output is
redirected to PID 1's stdout and stderr, so `docker logs netping-agent`
shows every probe cycle.