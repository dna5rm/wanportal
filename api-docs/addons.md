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

Copy the tracked template and edit the copy (it is gitignored — live routes
are host-specific):

```sh
cp conf/addons-proxy.conf.example conf/addons-proxy.conf
```

The template shows one prefix for an example sidecar named
`wanportal-addon-nb` on the compose network. Nested paths such as
`/nb/cloud-api/` ride the same `/nb/` rule; do not add a second
`/ipc` prefix (that path is left for other sidecars).

```apache
ProxyPreserveHost On
RequestHeader set X-Forwarded-Prefix "/nb"
ProxyPass        /nb/ http://wanportal-addon-nb:80/nb/
ProxyPassReverse /nb/ http://wanportal-addon-nb:80/nb/
```

Each addon is its own compose service attached to the existing network, so no
published ports are needed — Apache resolves the service name through compose
DNS:

```yaml
services:
  wanportal-addon-nb:
    image: example/nb:latest
    networks:
      - netops
```

Changes apply on the next wanportal restart, or immediately with a graceful
Apache reload inside the container. `conf/addons-proxy.conf` may also chain a
second gitignored file for site-local overrides
(`IncludeOptional /srv/conf/addons-site.conf`); the template ends with that
include.

## Extending without SPA work

A new PHP page under the sidecar `/nb/` prefix does not need a Vue change.
Link it from the addon's own chrome (`chrome.php`) and, if you want it in the
portal bar, add an `href` to `/nb/...` in `htdocs/config.json`. Same-origin
hrefs stay in this tab. Hash routes and AddonFrame iframes are optional.

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
curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:80/nb/
```

`502`/`503` usually means the sidecar is down or on the wrong network; a DNS
failure at config-load time means the service name does not match the compose
service.