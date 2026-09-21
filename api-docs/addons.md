# Addon reverse proxies

Wanportal can front small compose sidecar services ("addons") on the same
Apache port as the portal itself. The core image stays generic: it loads the
Apache proxy modules and overlays one optional configuration file, and the
routes for any given addon live in that file, never in the image.

## How the hook works

The image ships `conf.d/proxy.conf`, which does exactly two things:

```apache
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_http_module modules/mod_proxy_http.so

IncludeOptional /srv/conf/addons-proxy.conf
```

`IncludeOptional` means an install with no addon conf behaves identically to
before: no `ProxyPass` is hardcoded in the image and nothing fails when the
file is absent. The modules ship in the `apache2-proxy` package, installed
alongside `apache2` in the image. The repository tree is bind-mounted at
`/srv`, so the include path resolves to `conf/addons-proxy.conf` next to
`docker-compose.yml` on the host.

## Enabling an addon

Copy the tracked template and edit the copy (it is gitignored; live routes
are host-specific):

```sh
cp conf/addons-proxy.conf.example conf/addons-proxy.conf
```

The template shows one prefix for an example sidecar named
`wanportal-addon-nb` on the compose network. Nested paths such as
`/nb/cloud-api/` ride the same `/nb/` rule. Further sidecars get their own
prefix on the same network: the template also carries commented blocks for
`/ipc/` (`wanportal-addon-ipc`) and `/catalog/` (`wanportal-addon-catalog`,
the service catalog addon). Uncomment a block and edit the service name to
fit your install.

```apache
ProxyPreserveHost On

<Location /nb/>
    RequestHeader set X-Forwarded-Prefix "/nb"
</Location>
ProxyPass        /nb/ http://wanportal-addon-nb:80/nb/
ProxyPassReverse /nb/ http://wanportal-addon-nb:80/nb/
```

Scope `X-Forwarded-Prefix` inside `<Location /prefix/>` for every addon.
A later unscoped `RequestHeader set` overwrites earlier prefixes.

The second sidecar block in the template is commented out:

```apache
<Location /ipc/>
    RequestHeader set X-Forwarded-Prefix "/ipc"
</Location>
ProxyPass        /ipc/ http://wanportal-addon-ipc:80/ipc/
ProxyPassReverse /ipc/ http://wanportal-addon-ipc:80/ipc/
```

The third sidecar block (the service catalog addon) is commented out too:

```apache
<Location /catalog/>
    RequestHeader set X-Forwarded-Prefix "/catalog"
</Location>
ProxyPass        /catalog/ http://wanportal-addon-catalog:80/catalog/
ProxyPassReverse /catalog/ http://wanportal-addon-catalog:80/catalog/
```

Each addon is its own compose service attached to the existing network, so no
published ports are needed; Apache resolves the service name through compose
DNS:

```yaml
services:
  wanportal-addon-nb:
    image: example/nb:latest
    networks:
      - netops
```

Changes apply on the next wanportal restart, or immediately with a graceful
reload of Apache PID 1:

```sh
docker exec wanportal /usr/sbin/httpd -k graceful
```

`conf/addons-proxy.conf` may also chain a
second gitignored file for site-local overrides
(`IncludeOptional /srv/conf/addons-site.conf`); the template ends with that
include.

Define sidecar services in the portal `docker-compose.override.yml` and
run `docker compose up --build -d <service>` from the portal directory.
Running compose from an addon tree creates a second project, a name
conflict, and a stray `<addon>_default` network.

A `ProxyPass` whose backend name does not resolve is **not** a config-load
error: `httpd -t` still passes. The request then returns 500 / AH00898
(DNS lookup failure). That means the sidecar is not on the same compose
network, or the service name does not match.

The portal bind-mounts the repo at `/srv`. Apache (typically uid 100)
must have execute on every host directory in that path. AH00035 on `/`
or `/ipc/` is a host permission problem (`chmod o+x` on parent dirs),
not a proxy bug.

## The catalog sidecar

`wanportal-addon-catalog` serves a generic service catalog under `/catalog/`:
a public listing (`index.php`), SKU detail (`sku.php`), lookup-table admin
(`admin.php`), and a small JSON API (`api.php`). Point `build:` and the
SQLite volume at your addon checkout. Wiring follows the rules above, with
these specifics:

- **One compose service, no host port.** The service joins the `netops`
  network and `expose`s container port 80 only. Apache reaches it as
  `http://wanportal-addon-catalog:80/catalog/`, and nothing is published on
  the host.
- **Reads are public, writes are gated.** GETs need no token. POST, PATCH,
  DELETE, and HTML form posts carry the portal bearer token, validated live
  by calling `GET http://wanportal/cgi-bin/api/session` over the compose
  network. Any authenticated user may write, and no token is stored.
- **State lives in a host bind, not the image.** Mount a host `data/`
  directory at `/var/lib/catalog`. The file is `catalog.sqlite`. A
  one-file copy (`catalog.sqlite.bak`) is taken before every successful
  write, so recreating the container loses nothing and every write has an
  undo. The container apache user must be able to write that directory.

## Extending without SPA work

A new PHP page under the sidecar `/nb/` prefix does not need a Vue change.
Link it from the addon's own chrome (`chrome.php`) and, if you want it in the
portal bar, add an `href` to `/nb/...` in `htdocs/config.json`. Same-origin
hrefs stay in this tab. Hash routes and AddonFrame iframes are optional.

The catalog sidecar is linked the same way, with a top-level entry
`{ "label": "Catalog", "href": "/catalog/" }` in `htdocs/config.json`.

## Rules of the road

- **Prefixes must not collide with the portal.** Avoid `/cgi-bin`, `/classic`,
  `/api-docs`, and the SPA routes at `/` and `/assets`. Give each addon one
  prefix and keep it stable.
- **Same compose network.** A sidecar on a different network cannot be
  resolved by name; that is the most common failure (Apache logs a DNS or
  503 error for the prefix).
- **Keep `ProxyPass` and `ProxyPassReverse` paired**, or redirects from the
  addon will leak its internal URL.
- **No secrets in tracked files.** `conf/addons-proxy.conf` and
  `conf/addons-site.conf` are gitignored on purpose; the tracked
  `conf/addons-proxy.conf.example` documents routing only.

## Verifying

After a reload, a working route returns the addon's own response through the
portal port (HTTP 200/404 from the addon, not a proxy error):

```sh
curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:3385/nb/
```

The same check for the catalog prefix:

```sh
curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:3385/catalog/
```

`502`/`503` usually means the sidecar is down or on the wrong network.
AH00898 at request time means the compose service name does not resolve.