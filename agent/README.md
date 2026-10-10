# Field probe agent

This directory is the **netping field agent** — the cron container that
runs on monitored hosts and posts results to wanportal. It is not the
in-container alerter; that lives in `../notify/`.

## Layout

| File | Role |
|---|---|
| `Dockerfile` | Image: Alpine + crond + `netping-agent.pl`. Built from the **repo root**. |
| `build_agent.sh` | Tags `netping:<agent version>` (read from `netping-agent.pl`), `netping:<YYYYMMDD>` and `netping:latest`, writes `htdocs/assets/netping_latest.tar.gz` (gitignored). |
| `netping-agent.pl` | What ships. HTTPS client, `Net::Ping`. |
| `run-agent.sh` | Cron wrapper inside the image (`/srv/agent/run-agent.sh`). |
| `socket-agent.pl` | Raw-socket / DSCP variant. **Not** in the image; run from a checkout. |
| `netping-legacy.pl` | Old `Net::Ping` fallback. **Not** in the image. |
| `run-notify.sh` | Compat shim only. Real notify is `../notify/run-notify.sh`. Do not add alert logic here. |

## Build

From the **repository root** (COPY paths are `agent/…`):

```sh
./agent/build_agent.sh
```

or:

```sh
docker build -f agent/Dockerfile -t netping:<agent version> .
```

A bare `docker build` cannot read the version, so the hand command
carries the tag itself — e.g. `-t netping:0.2.0` when the script
declares `our $VERSION = '0.2.0';`. `latest` is the convenience alias,
not the one that says what you are running.

`build_agent.sh` reads the agent version from `netping-agent.pl`
(`our $VERSION = '…';`) at build time and tags the image
`netping:<version>` — e.g. `netping:0.2.0` — alongside
`netping:<YYYYMMDD>` and `netping:latest`, so `docker images` shows
which agent version a host runs. The download archive carries the
version tag plus `latest`, so `docker load` restores both tags.

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
  netping:<agent version>
```

Run the version tag rather than the `latest` alias: the version is
what states which agent the container carries, and `docker ps` echoes
it in the IMAGE column. The dashboard's netping page renders this same
command with the version parsed from the script it serves.

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
