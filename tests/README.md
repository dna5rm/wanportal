# Tests

From the repo root, with the `wanportal` container up:

```sh
bash tests/validate.sh   # syntax, live smoke, audit gates, unit tests
bash tests/run.sh        # Perl prove + PHP CLI only
```

Both use `docker exec` into that container (`WANPORTAL_CONTAINER` /
`WANPORTAL_API` if yours differ). There is no host-interpreter fallback.

The Vue suite is separate: `npm test` in `ui/`.

## Layout

```
tests/perl/     *.t    Test::More, via prove
tests/php/      *.php  exit 0 on pass; print FAIL and exit 1 on fail
tests/run.sh
tests/validate.sh
```

`validate.sh` lint-checks Perl/PHP, hits live `/health` and login (password
from container env, never hardcoded), checks `/` is the SPA and `/classic`
is the PHP console, then runs `run.sh`.

`tests/perl/openapi_paths.t` requires every `cgi-bin` route to appear in
`api-docs/openapi.yaml` in the same commit.

`tests/perl/agent_image_pkgs.t` maps `use` lines in `agent/netping-agent.pl`
to packages in `agent/Dockerfile`.

Vite builds into the live docroot (`emptyOutDir: false`) because `htdocs/`
also holds `classic/`, `config.json`, and `assets/`.
