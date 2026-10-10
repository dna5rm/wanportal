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
  endpoints (`/agents`, `/targets`, `/monitors`, `/services`, `/rrd`) and the
  agent polling endpoints are unauthenticated; every mutating and credential
  route needs a JWT, as does the gated `/runtime-stats` readout (host
  metrics and monitoring freshness) the deliberately minimal public
  `/health` must not expose. The spec is `api-docs/openapi.yaml`, browsable
  in Swagger UI at `/api-docs/` and in-app at `/#/api`; operator guides
  render at `/#/guides/<file>`.

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

- The app is published on `HTTP_ADDR` (default `127.0.0.1`), port
  `HTTP_PORT` (default `3385`); Apache listens on 80 inside the container.
  Set `HTTP_ADDR=0.0.0.0` to listen on all interfaces.
- First login: user `admin`, password = `MYSQL_PASSWORD`. LDAP is optional
  (`AUTH_LDAP_ENABLED`); valid LDAP logins are treated as admins.
- The compose network is dual-stack so IPv6 targets are reachable from the
  container. To run IPv4-only, set `enable_ipv6: false` on the `netops`
  network in `docker-compose.override.yml` and recreate the network.
- Extra port binds belong in `docker-compose.override.yml` (gitignored).
- The repo is bind-mounted at `/srv`. Apache in the container must be able
  to traverse every host directory on that path (execute bit). A 403 with
  AH00035 ("search permissions are missing") is a host directory mode
  problem, not an app bug.
- Sidecar addons (`/nb/`, `/ipc/`, `/catalog/`): see
  [api-docs/addons.md](api-docs/addons.md). Always `docker compose up`
  from this directory, never from an addon tree.

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
— that tags `netping:<agent version>` (read from the script at build time),
`netping:<date>` and `netping:latest`, and packages
`htdocs/assets/netping_latest.tar.gz` for download. Run it with `--network
host` and the `SERVER` / `PASSWORD` / `AGENT_ID` env vars. Build/run details
and variants: [agent/README.md](agent/README.md).

`notify/` is a separate thing: alert jobs — down/clear email via SMTP (when
the SMTP vars are set) and push via an ntfy server — that run inside the
wanportal container, not field agents. The email alerting covers services
alongside reachability monitors: services track their own down/clear state
in a separate state file, under the same DOWN_THRESHOLD and exclude-word
rules, and get their own sections in the email with links into the SPA's
`#/services/<id>` routes; the ntfy push stays monitor-only.

## Services

Alongside the reachability monitors the agent can run **service checks**
against the same targets: an HTTP(S) probe defined by a URI
(scheme/port/path/query on the target host), a request method, headers,
a body, an expected-status range and F5-style send/receive/disable
assertion strings (substring match by default, regex opt-in). A monitor
answers "is the host reachable and how far away is it"; a service
answers "did the application return the right thing". Services live in
their own `services` table and RRD layout — no monitor code path is
touched.

The agent contract is a second endpoint pair, deliberately separate
from `/agent/:id/monitors`:

- An upgraded agent polls `GET /agent/:id/services` for due checks and
  POSTs results to the same path, authenticated with the same agent
  password as the monitor endpoints, after its monitor cycle.
- The agent's version rides the `NetPing-Agent/<version>` User-Agent
  header on every request (set once at LWP construction, so even a
  0.1.0 agent needs no upgrade), and the portal records it on every
  authenticated check-in - monitor or services, GET or POST. A request
  without a valid header writes nothing, so a stored version is
  refreshed, never cleared. Capability is derived, not declared: an
  agent's first authenticated contact with the services module sets
  `supports_services=1`. Body keys `supports_services` / `version` are
  accepted and ignored.
- An un-upgraded agent never calls the services endpoint, so old agents
  keep working unchanged — version skew is handled by the endpoint's
  existence, not a capability gate. A service assigned to an agent that
  has not yet contacted the services module never polls: it stays
  UNKNOWN, and the UI explains it as `agent_unsupported`.
- A new agent against an older portal gets a 404 on the services fetch
  and finishes its reachability cycle anyway; a result POST carrying
  unknown extra fields is ignored, not rejected.

HTTPS service checks need `LWP::Protocol::https` (packages
`perl-libwww` + `perl-lwp-protocol-https`): without the protocol
handler LWP fails every `https://` probe with a bogus 501, not a TLS
error. The stack image ships both because the LOCAL polling agent runs
inside the wanportal container, and `agent/Dockerfile` already declares
them for the field agent image — no package work is needed when you
rebuild it with `./agent/build_agent.sh`.

Service authentication is reference-only: a service stores
`auth_credential_id`, a pointer into the same credentials vault the
rest of the API uses — never an inline secret. The portal resolves the
credential when the agent fetches its services and delivers the secret
over the same HTTPS channel the agent password already travels; the
agent uses it only to build request headers and scrubs every form of
it (raw string, base64, Basic pair, Bearer payload) out of its logs.
Attaching a HIGH or CRITICAL sensitivity credential to a service
requires an admin (403).

The read/write split mirrors the topology endpoints: `GET /services`
and `GET /service/:id` are public reads, with anonymous responses
reduced to a display-safe allow-list (the credential reference,
assertion strings, headers and request body are stripped). Creating,
updating, deleting and resetting services are JWT routes, admin-only
(403 otherwise). All routes are in `api-docs/openapi.yaml`.

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
HTTP_ADDR=127.0.0.1
HTTP_PORT=3385
JWT_SECRET=
APP_SECRET=
AUTH_LDAP_ENABLED=false
AUTH_LDAP_SERVER_URI=
AUTH_LDAP_BIND_DN=
AUTH_LDAP_BIND_PASSWORD=
AUTH_LDAP_USER_SEARCH_BASEDN=
AUTH_LDAP_USER_SEARCH_ATTR=uid
AUTH_LDAP_REQUIRE_GROUPS=
LDAP_IGNORE_CERT_ERRORS=true
```

Compose has built-in fallbacks for `MYSQL_PASSWORD`, `JWT_SECRET`, and
`APP_SECRET` so the stack boots without them; set real values before anything
faces a network.

LDAP supports an optional group allowlist: set `AUTH_LDAP_REQUIRE_GROUPS` to
pipe-separated group DNs, e.g.
`AUTH_LDAP_REQUIRE_GROUPS=CN=Netops,OU=Groups,DC=example,DC=com|CN=Ops,DC=example,DC=com`
(a DN contains commas, so the pipe is the separator). Quote the whole DN
(and the whole bind DN) as one `.env` value — do not quote only the CN.
A non-empty value restricts LDAP logins to users in at least one listed
group (Active Directory nested `memberOf` first, then plain `memberOf` if
the server rejects the matching rule). Empty or unset keeps the default:
any user able to bind can log in. Valid LDAP logins are still treated as
admins.

`AUTH_LDAP_REQUIRE_GROUPS` is passed into CGI via Apache `PassEnv`. A
compose env change is not enough until the image is rebuilt so that
directive exists. LDAP failures return a normal login error, not HTTP 500.
A pre-LDAP `users` row whose `password_hash` is not bcrypt (`$2a$` / `$2y$` /
`$2b$`) is a local miss, not HTTP 500: login falls through to LDAP when LDAP
is enabled. Do not delete those rows to get past a 500; deactivate them
(`is_active=0`) only on a build that still dies inside `bcrypt()`.

## config.json

`htdocs/config.json` is the operator site config: the brand logo and the nav
menu. `logo` points at `assets/logo.png` and
`menu` drives the SPA navigation. It is read by two parsers —
`ui/src/siteConfig.js` and `htdocs/classic/lib/site_config.php` — so extend
both when you add a config key.