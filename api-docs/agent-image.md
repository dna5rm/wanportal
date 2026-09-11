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

## Building and distributing the image

`agent/build_agent.sh`, run from the repository root, tags the image
`netping:<YYYYMMDD>` and `netping:latest`, then writes
`htdocs/assets/netping_latest.tar.gz` from the `:latest` tag. The archive
is gitignored and is offered as a download on the dashboard's netping page
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
    netping:latest
```

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