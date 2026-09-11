# Testing wanportal

Run from the repo root (or anywhere; the scripts find the tree themselves):

```sh
bash tests/validate.sh          # syntax + live smoke + audit gates + unit tests
bash tests/run.sh                 # unit tests only (Perl prove + PHP CLI)
```

Both drivers talk to the running `wanportal` container through `docker exec`, so the tests run with the same interpreters the stack serves with. If the container is down, `tests/run.sh` exits nonzero and the live sections of `tests/validate.sh` fail; there is no host-interpreter fallback. Set `WANPORTAL_CONTAINER` or `WANPORTAL_API` if your container or endpoint differ from the defaults.

The Vue 3 SPA under `ui/` (`npm install && npm run build` there publishes `htdocs/index.html` plus hashed bundles into `htdocs/spa/`, served at the site root `/`; vite `base: '/'`, `emptyOutDir: false` — the docroot is shared: the classic console now lives under `htdocs/classic/` alongside `config.json` and `assets/`, so the build must never wipe it) is outside these suites. It is the primary UI at `/`; the classic PHP console remains reachable at `/classic` and its per-page paths (login at `/classic/login.php`). The SPA has its own vitest suite — `npm test` in `ui/` runs the specs under `ui/src/__tests__/`; it needs node on the host and is not part of the container drivers above.

What each layer does:

- `tests/validate.sh`: `perl -c` on `cgi-bin/api`, `php -l` on every PHP page under `htdocs` (all of them now in `htdocs/classic/`), a live pass over `GET /health` plus an admin login (the password is read from the container env, never hardcoded), a URL-contract pass (`/` serves the SPA shell, `/classic` and `/classic/login.php` serve the PHP console, `/app` is 404), grep gates for fixed audit regressions (LDAP filter escaping, /rrd path allowlist, credential password stripping, delete path prefixes, escaped page titles, cron wrapper privileges), then `tests/run.sh` as the last section. Exit 0 only when everything passes.
- `tests/run.sh`: `prove` over `tests/perl/*.t` (Test::More) and `php84` over `tests/php/*.php`. A PHP test passes by exiting 0 and fails by printing FAIL and exiting 1. Missing or empty suites are skipped; a down container is not.

What belongs here: tests for behavior that broke before (LDAP filter escaping, /rrd id allowlist, credential password stripping, DELETE path prefix, title escaping, spec-versus-routes drift), and pure functions and CGI helpers. `tests/perl/openapi_paths.t` compares every route in `cgi-bin` against `api-docs/openapi.yaml`; when you add a route, update the spec in the same commit.

Other standing suites: `tests/perl/agent_image_pkgs.t` ties every non-pragma `use` line in `agent/netping-agent.pl` to an apk package in `agent/Dockerfile` (add a `use Module` there and it fails until mapped and shipped); `tests/perl/agent_stats.t` extracts and runs the loss/median/min/max/stddev math from `agent/netping-agent.pl` verbatim; `tests/php/proxy_gate.php` drives `htdocs/classic/proxy.php`'s auth/CSRF/method/path gates through a hermetic harness (config.php require stripped, api_request stubbed) — no secrets, no network; `tests/php/no_direct_db.php` keeps the web tier free of direct database handles by scanning the `htdocs/classic` sources.

What does not: secrets, personal account names, or tests that need a browser session unless they go through the public health/login path using MYSQL_PASSWORD from the container env (never committed).

Layout:

```
tests/perl/     *.t   Test::More, run via prove
tests/php/      *.php exit 0 on pass, print FAIL and exit 1 on fail
tests/run.sh    driver (docker exec into the wanportal container)
```