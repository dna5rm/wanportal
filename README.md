# wanportal (NetPing)

A small Docker Compose stack for ICMP (and related) monitoring: Perl Mojolicious CGI API, Vue 3 SPA, classic PHP console, MariaDB, and RRD files per monitor.

Agents pull their assignment list, ping the targets, and post loss/latency back. The UI is a Vue 3 single-page app served at the site root `/` (source under `ui/`); the classic PHP console (Bootstrap 5.3, dark-mode toggle) stays reachable at `/classic`. Public topology endpoints (`/agents`, `/targets`, `/monitors`, `/rrd`) are unauthenticated on purpose; mutating and credential routes need a JWT.

Default HTTP port on the host is 3385 (`HTTP_PORT`). Inside the container Apache still listens on 80.

## Layout

```
cgi-bin/          Perl API (api dispatcher + *.pm modules)
htdocs/           Document root: SPA shell (index.html + hashed bundles under
                  spa/), classic PHP console (lib/page.php is the shared
                  chrome), site config (config.json), assets/
api-docs/         OpenAPI + Swagger UI + operator guides (*.md),
                  branding/ assets + architecture.svg
ui/               Vue 3 SPA source; builds into htdocs/
tests/            Test suites (see TESTING.md)
chart/            Optional Helm chart (Kubernetes)
Dockerfile        Alpine image (Apache + PHP 8.4 + Perl)
Dockerfile.agent  Optional agent image
docker-compose.yml
netping-agent.pl  Probe client (cron/systemd)
```

There is no `entrypoint.sh`. Schema tables are created by the Perl modules on first use.

## Run it

Copy `.env` from your secrets store (never commit it). Compose has built-in fallbacks for `MYSQL_PASSWORD`, `JWT_SECRET`, and `APP_SECRET` so the stack boots without them; set real values before anything faces a network.

```sh
docker compose up --build -d
```

Health check:

```sh
curl -sS http://127.0.0.1:3385/cgi-bin/api/health
```

UI (Vue SPA): `http://127.0.0.1:3385/` — classic PHP console: `http://127.0.0.1:3385/classic/`

The `netops` compose network is dual-stack (IPv4 plus ULA `fd42:172:22::/64`) so IPv6 monitors can leave the container. Recreate the network after changing IPAM (`docker compose down && up -d`). Restart is not enough.

First login: user `admin`, password = `MYSQL_PASSWORD`. LDAP is optional (`AUTH_LDAP_ENABLED`). Valid LDAP logins are treated as admins.

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

## API sketch

Agent (password in JSON body, not JWT):

- `GET /cgi-bin/api/agent/:id/monitors`
- `POST /cgi-bin/api/agent/:id/monitors`

CRUD (JWT on the mutating ones): `/monitor`, `/target`, `/agent`, `/users`, `/credentials`

Login:

```sh
curl -sS -X POST http://127.0.0.1:3385/cgi-bin/api/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"YOUR_MYSQL_PASSWORD"}'
```

Send the returned token in the Authorization header (bearer scheme). Classic PHP pages call `window.proxyRequest(...)` so the JWT stays on the server (`htdocs/proxy.php`); the SPA calls the API directly and sends the Bearer header from its own session (`ui/src/api.js`, `credentials: 'omit'`).

RRD files live at `/var/rrd/<monitor_id>.rrd` in the container (named volume `rrd`). Deleting a monitor/target/agent through the API removes the matching RRDs.

## Agent

```sh
export AGENT_ID=...
export PASSWORD=...
export SERVER=http://127.0.0.1:3385/cgi-bin/api
./netping-agent.pl
```

TLS certs are not verified by default (self-signed / lab).

The same agent also ships as a cron container. `build_agent.sh` builds
`Dockerfile.agent` into `netping:<date>` + `netping:latest` and packages it to
`htdocs/assets/netping_latest.tar.gz` (gitignored; downloadable from the
app at `/assets/netping_latest.tar.gz`). Only `netping-agent.pl` and `run-agent.sh` are in the image —
`netping-legacy.pl` and `socket-agent.pl` are not packaged. Run it with
`--network host` and `SERVER`/`PASSWORD`/`AGENT_ID` env vars. See
[api-docs/agent-image.md](api-docs/agent-image.md) for details.

## Tests

Two entry points, both from the repo root:

```sh
bash tests/validate.sh   # syntax, live smoke, audit gates, unit tests
bash tests/run.sh          # unit tests only
```

`tests/validate.sh` is the gate to run before any commit: it compiles the Perl API and every PHP page inside the container, hits the live health endpoint, replays the fixed audit regressions, and finishes with `tests/run.sh`. Both need the `wanportal` container up. The SPA has its own vitest suite (`npm test` in `ui/`), separate from these drivers. See [TESTING.md](TESTING.md) for what the suites cover and how to add tests.

## Security notes (by design)

- Session cookies: HttpOnly, SameSite=Lax, strict mode; Secure when the request is HTTPS (including `X-Forwarded-Proto`).
- PHP forms: CSRF via `wanportal_csrf_valid()`.
- `htdocs/.htaccess`: nosniff, DENY framing, CSP allowlisting just the CDN hosts the classic console's libraries load from (Bootstrap, DataTables, jQuery, Prism); the SPA ships its bundles locally from `/spa/` and pulls nothing from a CDN.
- Apache `ServerTokens Prod`.

## Docs

OpenAPI: `api-docs/openapi.yaml`, served at `/api-docs/openapi.yaml`. Swagger UI: `/api-docs/` (redirects to `/api-docs/swagger.php`) and in-app at `/#/api`. The operator guides under `api-docs/*.md` render inside the SPA at `/#/guides/<file>`.