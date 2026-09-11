# wanportal

A small Docker Compose stack for ICMP (and related) monitoring: field agents
pull their monitor assignments, ping the targets, and post loss/latency back
to a Perl (Mojolicious) CGI API, which stores state in MariaDB and RRD files
per monitor. The operator UI is a Vue 3 single-page app.

- **SPA (the product)** — served at the site root `/`, source in `ui/`.
- **Classic console** — a PHP console kept as an archive under
  `htdocs/classic/` and served at `/classic`. Archive-only: no new features;
  parity work happens in the SPA.
- **API** — `cgi-bin/api`, aliased at `/cgi-bin/`. The public topology
  endpoints (`/agents`, `/targets`, `/monitors`, `/rrd`) and the agent
  polling endpoints are unauthenticated; every mutating and credential route
  needs a JWT. The spec is `api-docs/openapi.yaml`, browsable in Swagger UI
  at `/api-docs/` and in-app at `/#/api`; operator guides render at
  `/#/guides/<file>`.

## Layout

```
ui/                 Vue 3 SPA source — the product. npm run build emits
                    htdocs/index.html plus hashed bundles into htdocs/spa/.
                    Vitest specs live in ui/src/__tests__/.
htdocs/             Apache docroot: the SPA (index.html + spa/), the operator
                    config.json, runtime assets/ (logo, agent image tarball),
                    and the classic PHP console archive under classic/.
cgi-bin/            Perl CGI API: api (Mojolicious::Lite dispatcher) plus one
                    *.pm module per resource.
api-docs/           Docs tree served at /api-docs: openapi.yaml, Swagger UI,
                    operator guides (*.md), branding, architecture.svg.
agent/              Field probe agent (cron container): netping-agent.pl,
                    run-agent.sh, build_agent.sh, Dockerfile — see
                    agent/README.md.
notify/             In-container alert jobs (not field agents):
                    notify-email.pl, notify-ntfy.pl, run-notify.sh.
tests/              validate.sh (commit gate) + run.sh, with Perl and PHP
                    suites under perl/ and php/. What the suites cover and
                    how to add tests: tests/README.md.
chart/              Optional Helm chart for Kubernetes.
Dockerfile          Stack image: Alpine with Apache + PHP + Perl CGI +
                    MariaDB client + RRDtool + cron.
docker-compose.yml  wanportal (app) + wandb (MariaDB).
.env                Secrets. Gitignored; never commit.
```

Schema tables are created by the Perl modules on first use. RRD files live in
the `rrd` named volume (`/var/rrd` in the container); deleting a
monitor/target/agent through the API removes the matching RRDs.

## Run

```sh
docker compose up --build -d
curl -sS http://localhost:3385/cgi-bin/api/health
```

- The app is published on localhost, port `3385` by default (`HTTP_PORT`);
  Apache listens on 80 inside the container.
- First login: user `admin`, password = `MYSQL_PASSWORD`. LDAP is optional
  (`AUTH_LDAP_ENABLED`); valid LDAP logins are treated as admins.
- The compose network is dual-stack so IPv6 targets are reachable from the
  container.
- Extra port binds belong in `docker-compose.override.yml` (gitignored).

## Build the SPA

```sh
cd ui && npm test && npm run build
```

Vite builds straight into the live docroot (`base: '/'`, `outDir:
'../htdocs'`, `assetsDir: 'spa'`, `emptyOutDir: false`): `htdocs/` also holds
`classic/`, `config.json` and `assets/`, so the build only ever writes
`index.html` and `spa/` bundles. Prune orphaned `htdocs/spa/index-*.{js,css}`
hashes from old builds by hand.

The repo is bind-mounted into the container, so a finished build is served
immediately — verify served, not just built:

```sh
curl -sS -o /dev/null -w '%{http_code}' http://localhost:3385/   # expect 200
```

and check that the served `index.html` references the new bundle hash.

## Field agent

The probe agent ships as a cron container (Alpine + crond +
`netping-agent.pl`). Build it from the repo root with `./agent/build_agent.sh`
— that tags `netping:<date>` + `netping:latest` and packages
`htdocs/assets/netping_latest.tar.gz` for download. Run it with `--network
host` and the `SERVER` / `PASSWORD` / `AGENT_ID` env vars. Build/run details
and variants: [agent/README.md](agent/README.md).

`notify/` is a separate thing: alert jobs — down/clear email via SMTP (when
the SMTP vars are set) and push via an ntfy server — that run inside the
wanportal container, not field agents.

## Tests

```sh
bash tests/validate.sh   # syntax, live smoke, audit gates, unit tests — the commit gate
bash tests/run.sh        # unit tests only (Perl prove + PHP)
```

Both drivers `docker exec` into the running `wanportal` container, so the
stack must be up. The SPA has its own vitest suite (`npm test` in `ui/`).
See [tests/README.md](tests/README.md).

`tests/perl/openapi_paths.t` compares every route in `cgi-bin` against
`api-docs/openapi.yaml`, so update the spec in the same commit as a route
change.

## Configuration

Environment only; `.env` is gitignored.

```
MYSQL_HOST=wandb
MYSQL_PORT=3306
MYSQL_USER=root
MYSQL_PASSWORD=change-me
MYSQL_DB=netops
HTTP_PORT=3385
JWT_SECRET=
APP_SECRET=
AUTH_LDAP_ENABLED=false
```

Compose has built-in fallbacks for `MYSQL_PASSWORD`, `JWT_SECRET`, and
`APP_SECRET` so the stack boots without them; set real values before anything
faces a network.

## config.json

`htdocs/config.json` is the operator site config: the brand logo and the nav
menu. `logo` points at `assets/logo.png` and
`menu` drives the SPA navigation. It is read by two parsers —
`ui/src/siteConfig.js` and `htdocs/classic/lib/site_config.php` — so extend
both when you add a config key.