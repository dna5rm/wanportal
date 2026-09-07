# wanportal (NetPing)

A small Docker Compose stack for ICMP (and related) monitoring: Perl Mojolicious CGI API, PHP dashboard, MariaDB, and RRD files per monitor.

Agents pull their assignment list, ping the targets, and post loss/latency back. The UI is Bootstrap 5.3 with a dark-mode toggle. Public topology endpoints (`/agents`, `/targets`, `/monitors`, `/rrd`) are unauthenticated on purpose; mutating and credential routes need a JWT.

Default HTTP port on the host is 3385 (`HTTP_PORT`). Inside the container Apache still listens on 80.

## Layout

```
cgi-bin/          Perl API (api dispatcher + *.pm modules)
htdocs/           PHP dashboard (lib/page.php is the shared chrome)
api-docs/         OpenAPI + Swagger UI
Dockerfile        Alpine image (Apache + PHP 8.4 + Perl)
Dockerfile.agent  Optional agent image
docker-compose.yml
netping-agent.pl  Probe client (cron/systemd)
```

There is no `entrypoint.sh`. Schema tables are created by the Perl modules on first use.

## Run it

Copy `.env` from your secrets store (never commit it). Compose falls back to `MYSQL_PASSWORD=netops` and a built-in JWT/APP secret if you omit those keys. Change them before anything faces a network.

```sh
docker compose up --build -d
```

Health check:

```sh
curl -sS http://127.0.0.1:3385/cgi-bin/api/health
```

Dashboard: `http://127.0.0.1:3385/`

The `netops` compose network is dual-stack (IPv4 plus ULA `fd42:172:22::/64`) so IPv6 monitors can leave the container. Recreate the network after changing IPAM (`docker compose down && up -d`). Restart is not enough.

First login: user `admin`, password = `MYSQL_PASSWORD`. LDAP is optional (`AUTH_LDAP_ENABLED`). Valid LDAP logins are treated as admins.

## Config

Environment only. Typical keys:

```
MYSQL_HOST=wandb
MYSQL_PORT=3306
MYSQL_USER=root
MYSQL_PASSWORD=netops
MYSQL_DB=netops
HTTP_PORT=3385
JWT_SECRET=   # set this
APP_SECRET=   # set this
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

Use the returned token as `Authorization: Bearer ...`. Browser pages should call `window.proxyRequest(...)` so the JWT stays on the server (`htdocs/proxy.php`).

RRD files live at `/var/rrd/<monitor_id>.rrd` in the container (named volume `rrd`). Deleting a monitor/target/agent through the API removes the matching RRDs.

## Agent

```sh
export AGENT_ID=...
export PASSWORD=...
export SERVER=http://127.0.0.1:3385/cgi-bin/api
./netping-agent.pl
```

TLS certs are not verified by default (self-signed / lab).

## Security notes (by design)

- Session cookies: HttpOnly, SameSite=Lax, strict mode; Secure when the request is HTTPS (including `X-Forwarded-Proto`).
- PHP forms: CSRF via `wanportal_csrf_valid()`.
- `htdocs/.htaccess`: nosniff, DENY framing, CSP limited to the CDNs this UI actually uses.
- Apache `ServerTokens Prod`.

## Docs

OpenAPI: `api-docs/openapi.yaml`. Swagger UI: `/api-docs/` on the same host.
