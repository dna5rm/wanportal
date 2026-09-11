# Field probe agent

This directory is the **netping field agent** — the cron container that
runs on monitored hosts and posts results to wanportal. It is not the
in-container alerter; that lives in `../notify/`.

## Layout

| File | Role |
|---|---|
| `Dockerfile` | Image: Alpine + crond + `netping-agent.pl`. Built from the **repo root**. |
| `build_agent.sh` | Tags `netping:<YYYYMMDD>` and `netping:latest`, writes `htdocs/assets/netping_latest.tar.gz` (gitignored). |
| `netping-agent.pl` | What ships. HTTPS client, `Net::Ping`. |
| `run-agent.sh` | Cron wrapper inside the image (`/srv/agent/run-agent.sh`). |
| `socket-agent.pl` | Raw-socket / DSCP variant. **Not** in the image; run from a checkout. |
| `netping-legacy.pl` | Old `Net::Ping` fallback. **Not** in the image. |
| `run-notify.sh` | Compat shim only. Real notify is `../notify/run-notify.sh`. Do not add alert logic here. |

The name `Dockerfile` is fine here: it no longer sits next to the
wanportal image Dockerfile at the repo root. (It used to be
`Dockerfile.agent` for that reason.)

## Build

From the **repository root** (COPY paths are `agent/…`):

```sh
./agent/build_agent.sh
```

or:

```sh
docker build -f agent/Dockerfile -t netping:latest .
```

Build on the **same CPU architecture** as the host that will run the
container. Loading an arm64 image on amd64 (or the reverse) fails or
warns that the platform does not match. For a mixed fleet, build on
each architecture (or use `docker build --platform` with a working
emulator).

## Run

```sh
docker run -d --name netping-agent --network host --restart unless-stopped \
  -e SERVER="https://<host>/cgi-bin/api" \
  -e PASSWORD="<agent password>" \
  -e AGENT_ID="<uuid>" \
  netping:latest
```

`--network host` so probes use the host stack. Cron does not inherit
container env; `run-agent.sh` reads `SERVER` / `PASSWORD` / `AGENT_ID`
from PID 1’s environ.

If the dashboard shows a Docker bridge address for the agent instead of
the host, the probe is reaching the API through a v4-only reverse proxy
on an IPv6 connection. Point `SERVER` at an IPv4 URL or a path that does
not hairpin through that proxy.

## Image contents

See [api-docs/agent-image.md](../api-docs/agent-image.md). Tests:
`tests/perl/agent_image_pkgs.t` maps every `use` in `netping-agent.pl`
to an apk package in this Dockerfile.
