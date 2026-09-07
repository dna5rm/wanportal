# Testing wanportal

Run from the repo root (or anywhere; the scripts find the tree themselves):

```sh
bash scripts/validate.sh          # syntax + live smoke + audit gates + unit tests
bash tests/run.sh                 # unit tests only (Perl prove + PHP CLI)
```

Both drivers talk to the running `wanportal` container through `docker exec`, so the tests run with the same interpreters the stack serves with. If the container is down, `tests/run.sh` exits nonzero and the live sections of `scripts/validate.sh` fail; there is no host-interpreter fallback. Set `WANPORTAL_CONTAINER` or `WANPORTAL_API` if your container or endpoint differ from the defaults.

What each layer does:

- `scripts/validate.sh`: `perl -c` on `cgi-bin/api`, `php -l` on every `htdocs` page, a live pass over `GET /health` plus an admin login (the password is read from the container env, never hardcoded), grep gates for fixed audit regressions (LDAP filter escaping, /rrd path allowlist, credential password stripping, delete path prefixes, escaped page titles, cron wrapper privileges), then `tests/run.sh` as the last section. Exit 0 only when everything passes.
- `tests/run.sh`: `prove` over `tests/perl/*.t` (Test::More) and `php84` over `tests/php/*.php`. A PHP test passes by exiting 0 and fails by printing FAIL and exiting 1. Missing or empty suites are skipped; a down container is not.

What belongs here: tests for behavior that broke before (LDAP filter escaping, /rrd id allowlist, credential password stripping, DELETE path prefix, title escaping, spec-versus-routes drift), and pure functions and CGI helpers. `tests/perl/openapi_paths.t` compares every route in `cgi-bin` against `api-docs/openapi.yaml`; when you add a route, update the spec in the same commit.

What does not: secrets, personal account names, or tests that need a browser session unless they go through the public health/login path using MYSQL_PASSWORD from the container env (never committed).

Layout:

```
tests/perl/     *.t   Test::More, run via prove
tests/php/      *.php exit 0 on pass, print FAIL and exit 1 on fail
tests/run.sh    driver (docker exec into the wanportal container)
```