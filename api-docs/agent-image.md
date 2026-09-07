# Agent image

The probe agent ships as a self-contained cron container: `Dockerfile.agent`,
built by `build_agent.sh` from the repo root. The container pings its assigned
monitors once a minute and posts loss/latency back to a wanportal server.

## What is in the image

- `alpine:3.21` (pinned to 3.21.3 in the Dockerfile) with `cronie` and `tzdata`
- Perl plus `perl-libwww` (LWP with HTTPS), `perl-io-socket-ssl`, and
  `perl-json`
- Two files from the repo: `netping-agent.pl` and its cron wrapper
  `run-agent.sh`

Nothing else ships. `netping-legacy.pl` and `socket-agent.pl` are not in the
image — run those from a checkout with a host perl if you need them
(`socket-agent.pl` is the one that can mark packets with DSCP/TOS
reliably). There is no `curl` or `jq` in the image either; poke the API
from outside the container if you need to.

The agent does not verify TLS certificates: `netping-agent.pl` sets
`SSL_VERIFY_NONE` on purpose, because agents are allowed to talk to a
server with a self-signed certificate.

## Build and distribute

`./build_agent.sh` (repo root) tags the image `netping:<YYYYMMDD>` and
`netping:latest`, then writes `htdocs/assets/netping_latest.tar.gz` from the
`:latest` tag. The archive is gitignored and offered as a download on the
dashboard's netping page (`/assets/netping_latest.tar.gz`).

On the target host:

```sh
gunzip -c netping_latest.tar.gz | docker load
```

## Run

```sh
docker run -d --name netping-agent --network host --restart unless-stopped \
    -e SERVER="https://<SERVER>/cgi-bin/api" \
    -e PASSWORD="<PASSWORD>" -e AGENT_ID="<AGENT_ID>" \
    netping:latest
```

- `--network host`: probes run from the host's own network stack, so the
  numbers reflect what the host sees and the agent's source address is the
  host's. Create the agent in the dashboard first; `AGENT_ID` is its id.
- Configuration is environment-only: `SERVER` (API base URL), `PASSWORD`,
  `AGENT_ID`, plus `DEBUG=1` for verbose logging. All three of the required
  ones must be set — without an `AGENT_ID` the agent exits nonzero.
- `crond` runs `run-agent.sh` every minute. The wrapper picks the variables
  up from a mounted `/srv/.env` when present, otherwise from
  `/proc/1/environ` (cron jobs do not inherit the container environment).
  It also holds a single-instance lock, so an overlapping cron tick exits
  quietly and the next tick takes over.
- A healthcheck confirms that `crond` is alive and the agent script is
  executable; there is deliberately no HTTP endpoint in the container to
  poke.

Verify with `docker ps | grep netping-agent`. Cron output is redirected to
PID 1's stdout, so `docker logs netping-agent` shows every probe cycle.