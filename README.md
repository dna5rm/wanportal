# wanportal (NetPing)

A small Docker Compose stack for ICMP (and related) monitoring: Perl
Mojolicious CGI API, Vue 3 SPA, MariaDB, and RRD files per monitor.

**The product is the Vue 3 single-page app served at the site root `/`**
(source under `ui/`). The classic PHP console (Bootstrap 5.3, dark-mode
toggle) is **archive-only**: it still works at `/classic`, but it is kept
only until it is removed — no new features; parity work happens in the SPA.
There is no `/legacy` URL (the old redirect was removed; the path 404s) and
no `/app` URL (the SPA moved from `/app/` to the site root).

Agents pull their assignment list, ping the targets, and post loss/latency
back. Public topology endpoints (`/agents`, `/targets`, `/monitors`,
`/rrd`) are unauthenticated on purpose; mutating and credential routes need
a JWT.

Default HTTP port on the host is 3385 (`HTTP_PORT`). Inside the container
Apache still listens on 80.

## Layout

```
ui/                Vue 3 SPA source — the product. npm run build emits
                   htdocs/index.html plus hashed bundles into htdocs/spa/.
                   Vitest specs in ui/src/__tests__/.
htdocs/            Apache docroot (bind-mounted live at /srv/htdocs):
                   the SPA (index.html + spa/), the operator site config
                   (config.json), runtime assets/ (logo.png, agent image
                   tarball) — and the classic PHP console, archive-only,
                   under classic/.
cgi-bin/           Perl CGI API: api (Mojolicious::Lite dispatcher) plus
                   one *.pm module per resource. Aliased at /cgi-bin/.
api-docs/          The docs tree, served at /api-docs: openapi.yaml,
                   Swagger UI, operator guides (*.md), Parsedown.php,
                   branding/ (project logo), architecture.svg.
agent/             Field probe image: netping-agent.pl, socket-agent.pl,
                   netping-legacy.pl, run-agent.sh, build_agent.sh,
                   Dockerfile.agent.
notify/            In-container alert jobs (not field agents):
                   notify-email.pl, notify-ntfy.pl, run-notify.sh.
tests/             validate.sh (commit gate) + run.sh; perl/ *.t and php/
                   suites (see TESTING.md).
chart/             Optional Helm chart (Kubernetes).
tmp/               Gitignored scratch (old tree copies, harnesses).
Dockerfile         Lab image: Alpine 3.21, Apache + PHP 8.4 + Perl CGI +
                   MariaDB client + RRDtool + cron. No CryFS, Ansible, or
                   WebDAV.
docker-compose.yml wanportal + wandb (MariaDB); dual-stack netops network.
.env               Secrets (gitignored; never commit).
```

There is no `entrypoint.sh`: schema tables are created by the Perl modules
on first use.

### htdocs/ — one docroot, two tenants

- **SPA (the product):** `index.html` plus hashed bundles under `spa/`.
  `DirectoryIndex` lists only `index.html`, so `/` is the SPA.
- **`classic/` — the classic PHP console archive.** The pages
  (`index.php`, `login.php`, `monitors.php`, …), the shared chrome in
  `classic/lib/` (`page.php`, `api_proxy.php`, `site_config.php`,
  `monitor_metrics.php`), its own `assets/` (`base.css`,
  `js/listings.js`, `js/proxy.js`), `config.php`, `proxy.php` (keeps the
  JWT server-side for classic pages) and `.user.ini`. Served straight from
  `htdocs/classic/` under `/classic/...` — only `/classic`,
  `/classic/index.html` are pinned to `classic/index.php` by
  `htdocs/.htaccess`, which also 302s pre-cutover root-level `*.php`
  bookmarks to their `/classic/` location. Classic is archive-only until
  removed; do not grow it.
- **`config.json`:** operator site config (brand logo + nav menu). Read by
  BOTH parsers — `ui/src/siteConfig.js` and
  `htdocs/classic/lib/site_config.php` — extend them together when config
  keys change.
- **`assets/`:** runtime files only — `logo.png` (gitignored operator brand
  file referenced by `config.json`) and `netping_latest.tar.gz`
  (gitignored agent image download). Served at `/assets/...`.
- **`.htaccess`:** security headers (nosniff, frame DENY, CSP, referrer/
  permissions policy) plus the `/classic` pins and 302s above.

### agent/ — the field probe

| File | Role |
| --- | --- |
| `netping-agent.pl` | The probe agent (Net::Ping): pulls its monitor list, pings targets, posts loss/latency. Env `SERVER` / `AGENT_ID` / `PASSWORD`. TLS certs are not verified by design (self-signed lab). |
| `socket-agent.pl` | Raw-socket variant with working DSCP/TOS marking (Net::Ping's TOS support never reaches the packets). |
| `netping-legacy.pl` | Legacy fallback agent. Neither this nor `socket-agent.pl` ships in the agent image; run them from a checkout. |
| `run-agent.sh` | Cron wrapper. The image's `/usr/local/sbin/cron-run-agent` calls it every minute (the agent needs raw sockets → root). |
| `run-notify.sh` | Two-line compat shim that execs `notify/run-notify.sh`, for older images whose `cron-run-notify` wrapper still points at `/srv/agent/run-notify.sh`. |

### notify/ — in-container alert jobs (not field agents)

| File | Role |
| --- | --- |
| `notify-email.pl` | Down/clear email via SMTP (`SMTP_SERVER` / `FROM_EMAIL` / `TO_EMAIL`; `DOWN_THRESHOLD` seconds before notifying). `run-notify.sh` runs it only when the SMTP vars are set. |
| `notify-ntfy.pl` | Down/clear push via an ntfy server (`NTFY_SERVER` / `NTFY_TOPIC`). Standalone — not wired into the cron wrappers. |
| `run-notify.sh` | Cron wrapper. The image's `/usr/local/sbin/cron-run-notify` calls it every 5 minutes after dropping to `apache`. |

The probe agent also ships as a cron container: `Dockerfile.agent`, built
by `build_agent.sh` into `netping:<date>` + `netping:latest` and packaged
to `htdocs/assets/netping_latest.tar.gz` (downloadable at
`/assets/netping_latest.tar.gz`). The image carries only
`netping-agent.pl` + `run-agent.sh` and deliberately omits `curl` and
`jq`. Run it with `--network host` and the `SERVER`/`PASSWORD`/`AGENT_ID`
env vars. See [api-docs/agent-image.md](api-docs/agent-image.md).

### api-docs/ — the docs tree (served at /api-docs)

- `openapi.yaml` — the spec. `tests/perl/openapi_paths.t` compares every
  route in `cgi-bin` against it, so update the spec in the same commit as
  a route change. `bootstrap_openapi.pl` regenerates the skeleton.
- Swagger UI at `/api-docs/` (`redirect.html` bounces to
  `swagger.php`); the raw spec is served at `/api-docs/openapi.yaml`.
- Operator guides (`*.md`: `agent-image.md`, `db_schema.md`,
  `tcpdump.md`, `test_*.md`) render server-side via the bundled
  `Parsedown.php` (`/api-docs/index.php?file=...`) and inside the SPA at
  `/#/guides/<file>` via its bundled marked.
- `branding/` (`kinetic.svg` / `kinetic.png` — the project logo) and
  `architecture.svg`. There is no separate `docs/` tree.

## Build the SPA (ui/ → htdocs/)

```sh
cd ui && npm test && npm run build
```

`vite.config.js` uses `base: '/'`, `outDir: '../htdocs'`,
`assetsDir: 'spa'`, and `emptyOutDir: false` — the outDir IS the live
docroot (it also holds `classic/`, `config.json`, `assets/`), so Vite only
ever writes `index.html` and `spa/` bundles. Prune orphaned
`htdocs/spa/index-*.{js,css}` hashes from old builds by hand.

The build is live immediately (the container bind-mounts the repo), but
"done" means served, not built: `curl -sS -o /dev/null -w '%{http_code}'
http://127.0.0.1:3385/` must be 200, the served `index.html` must
reference the new bundle hash, and the served JS must contain the feature
marker. `localhost:80` has no listener on the host — always check :3385.

## Run it

Copy `.env` from your secrets store (never commit it). Compose has
built-in fallbacks for `MYSQL_PASSWORD`, `JWT_SECRET`, and `APP_SECRET` so
the stack boots without them; set real values before anything faces a
network.

```sh
docker compose up --build -d
curl -sS http://127.0.0.1:3385/cgi-bin/api/health
```

UI (Vue SPA): `http://127.0.0.1:3385/` — classic PHP console (archive):
`http://127.0.0.1:3385/classic/`

The `netops` compose network is dual-stack (IPv4 plus ULA
`fd42:172:22::/64`) so IPv6 monitors can leave the container. Recreate the
network after changing IPAM (`docker compose down && up -d`). Restart is
not enough.

First login: user `admin`, password = `MYSQL_PASSWORD`. LDAP is optional
(`AUTH_LDAP_ENABLED`). Valid LDAP logins are treated as admins.

## Config

Environment only. Typical keys:

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

`.env` is gitignored.

## API

`cgi-bin/api` (Mojolicious::Lite) registers routes in two tiers:
unauthenticated — the public read-only topology endpoints, the agent
polling endpoints (agent-password auth lives inside that module), and
`/login`; JWT-protected — `users`, `credentials`, `agent`, `target`,
`monitor`, the token test and `/session`, all behind the auth middleware,
writes admin-only.

Agent (password in JSON body, not JWT):

- `GET /cgi-bin/api/agent/:id/monitors`
- `POST /cgi-bin/api/agent/:id/monitors`

CRUD (JWT on the mutating ones): `/monitor`, `/target`, `/agent`,
`/users`, `/credentials`

Login:

```sh
curl -sS -X POST http://127.0.0.1:3385/cgi-bin/api/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"YOUR_MYSQL_PASSWORD"}'
```

Send the returned token in the Authorization header (bearer scheme).
Classic PHP pages call `window.proxyRequest(...)` so the JWT stays on the
server (`htdocs/classic/proxy.php`); the SPA calls the API directly and
sends the Bearer header from its own session (`ui/src/api.js`,
`credentials: 'omit'`).

RRD files live at `/var/rrd/<monitor_id>.rrd` in the container (named
volume `rrd`). Deleting a monitor/target/agent through the API removes the
matching RRDs.

When you add a route, update `api-docs/openapi.yaml` in the same commit —
`tests/perl/openapi_paths.t` fails on spec-versus-routes drift.

## Tests

Two entry points, both from the repo root:

```sh
bash tests/validate.sh   # syntax, live smoke, audit gates, unit tests
bash tests/run.sh        # unit tests only
```

`tests/validate.sh` is the gate to run before any commit. Both drivers
`docker exec` into the running `wanportal` container. The SPA has its own
vitest suite (`npm test` in `ui/`), separate from these drivers. See
[TESTING.md](TESTING.md) for what the suites cover and how to add tests.

## Security notes (by design)

- Session cookies: HttpOnly, SameSite=Lax, strict mode; Secure when the
  request is HTTPS (including `X-Forwarded-Proto`).
- PHP forms: CSRF via `wanportal_csrf_valid()`.
- `htdocs/.htaccess`: nosniff, DENY framing, CSP allowlisting just the CDN
  hosts the classic console's libraries load from (Bootstrap, DataTables,
  jQuery, Prism); the SPA ships its bundles locally from `/spa/` and pulls
  nothing from a CDN.
- Apache `ServerTokens Prod`; agent TLS verification off by design.

## Docs

OpenAPI: `api-docs/openapi.yaml`, served at `/api-docs/openapi.yaml`.
Swagger UI: `/api-docs/` and in-app at `/#/api`. The operator guides under
`api-docs/*.md` render inside the SPA at `/#/guides/<file>`.

## Deliberately absent

- **No `/legacy` URL** — the Apache redirect was removed and nothing
  re-adds it; the path 404s.
- **No `/app` URL** — the SPA lives at the site root.
- **No `docs/` tree** — everything doc-like lives under `api-docs/`.
- **No agent scripts at the repo root** — netping + notify all live in
  `agent/`.
- **No `htdocs/classic` growth** — the classic console is archive-only
  until removed; the SPA is the product.
- **No CryFS, Ansible, or WebDAV** in the lab image.
- **No `entrypoint.sh`** — schema tables are created by the Perl modules
  on first use.