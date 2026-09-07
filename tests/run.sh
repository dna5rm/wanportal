#!/usr/bin/env bash
# Run unit tests for wanportal. Prefers the live container.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CTR="${WANPORTAL_CONTAINER:-wanportal}"
fail=0

in_ctr() {
  docker exec "$CTR" "$@"
}

if docker inspect "$CTR" >/dev/null 2>&1; then
  if in_ctr sh -c 'command -v prove >/dev/null && test -d /srv/tests/perl'; then
    if in_ctr prove -l -r /srv/tests/perl; then
      echo "PASS  prove /srv/tests/perl"
    else
      echo "FAIL  prove /srv/tests/perl"
      fail=1
    fi
  else
    echo "SKIP  prove (no prove or tests/perl in container)"
  fi
  phpbin=/usr/bin/php84
  if in_ctr test -x "$phpbin" && in_ctr sh -c 'ls /srv/tests/php/*.php >/dev/null 2>&1'; then
    while IFS= read -r f; do
      base=$(basename "$f")
      if in_ctr "$phpbin" "/srv/tests/php/$base"; then
        echo "PASS  php $base"
      else
        echo "FAIL  php $base"
        fail=1
      fi
    done < <(ls "$ROOT/tests/php"/*.php 2>/dev/null || true)
  fi
else
  echo "SKIP  container $CTR not running"
  fail=1
fi

exit "$fail"
