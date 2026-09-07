#!/usr/bin/env bash
# Smoke + regression checks for wanportal. Run from repo root or anywhere.
# Uses the live wanportal container when present.
# Exit 0 only if every check passes. No secrets in this file.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CTR="${WANPORTAL_CONTAINER:-wanportal}"
API="${WANPORTAL_API:-http://127.0.0.1:3385/cgi-bin/api}"
fail=0
pass=0

ok() { echo "PASS  $*"; pass=$((pass + 1)); }
bad() { echo "FAIL  $*"; fail=$((fail + 1)); }

echo "== syntax =="
if docker exec "$CTR" perl -c /srv/cgi-bin/api >/tmp/wanportal-perl-c.out 2>&1; then
  ok "perl -c cgi-bin/api"
else
  bad "perl -c cgi-bin/api"
  cat /tmp/wanportal-perl-c.out
fi

phpbin=""
for c in /usr/bin/php84 php84 php8.4 php; do
  if docker exec "$CTR" test -x "$c" 2>/dev/null || docker exec "$CTR" sh -c "command -v $c >/dev/null 2>&1"; then
    phpbin=$c
    break
  fi
done
if [[ -z "$phpbin" ]]; then
  bad "no php in container"
else
  while IFS= read -r -d '' f; do
    rel="${f#"$ROOT"/}"
    if docker exec "$CTR" "$phpbin" -l "/srv/$rel" >/dev/null 2>&1; then
      ok "php -l $rel"
    else
      bad "php -l $rel"
    fi
  done < <(find "$ROOT/htdocs" -name '*.php' -print0)
fi

echo "== live http =="
code=$(curl -sS -o /tmp/wanportal-health.json -w '%{http_code}' --max-time 8 "$API/health" || echo 000)
if [[ "$code" == "200" ]] && grep -q ok /tmp/wanportal-health.json; then
  ok "GET /health 200"
else
  bad "GET /health ($code)"
fi

pw=$(docker exec "$CTR" printenv MYSQL_PASSWORD 2>/dev/null || true)
if [[ -z "$pw" ]]; then
  pw=netops
fi

tok=$(curl -sS --max-time 8 -X POST "$API/login" \
  -H 'Content-Type: application/json' \
  -d "{\"username\":\"admin\",\"password\":\"$pw\"}" | python3 -c 'import sys,json
try:
  d=json.load(sys.stdin)
except Exception:
  d={}
print(d.get("token") or d.get("jwt") or "")' 2>/dev/null || true)

if [[ -z "$tok" ]]; then
  bad "admin login (no token)"
else
  ok "admin login"
fi

auth=(-H "Authorization: Bearer $tok" -H 'Accept: application/json')

if [[ -n "$tok" ]]; then
  curl -sS --max-time 8 "${auth[@]}" "$API/credentials" -o /tmp/wanportal-creds.json || true
  python3 - <<'PY' && ok "GET /credentials has no password fields" || bad "GET /credentials still includes password"
import json,sys
d=json.load(open("/tmp/wanportal-creds.json"))
creds=d.get("credentials") or d.get("data") or []
if isinstance(creds, dict):
    creds=list(creds.values()) if creds else []
for c in creds:
    if isinstance(c, dict) and "password" in c and c.get("password") not in (None, "", "***"):
        sys.exit(1)
sys.exit(0)
PY

  curl -sS --max-time 8 "${auth[@]}" "$API/credentials?include_password=1" -o /tmp/wanportal-creds-inc.json || true
  python3 - <<'PY' && ok "include_password does not dump secrets" || bad "include_password still dumps secrets"
import json,sys
d=json.load(open("/tmp/wanportal-creds-inc.json"))
creds=d.get("credentials") or d.get("data") or []
if isinstance(creds, dict):
    creds=[]
for c in creds:
    if isinstance(c, dict) and c.get("password") not in (None, "", "***"):
        sys.exit(1)
sys.exit(0)
PY
fi

# GET /session must echo the caller's claims and require the bearer token
if [[ -n "$tok" ]]; then
  curl -sS --max-time 8 "${auth[@]}" "$API/session" -o /tmp/wanportal-session.json || true
  python3 - <<'PY' && ok "GET /session returns claims" || bad "GET /session claims wrong/missing"
import json,sys
d=json.load(open("/tmp/wanportal-session.json"))
if d.get("status")!="success": sys.exit(1)
if d.get("username")!="admin": sys.exit(1)
if d.get("is_admin") is not True: sys.exit(1)
e=d.get("exp")
if not isinstance(e,int) or e<=0: sys.exit(1)
sys.exit(0)
PY

  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 8 "$API/session" || echo 000)
  if [[ "$code" == "401" ]]; then
    ok "GET /session without bearer is 401"
  else
    bad "GET /session without bearer http $code (want 401)"
  fi

  # the /login response must carry the claims (login.php stores them in the PHP session)
  curl -sS --max-time 8 -X POST "$API/login" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"admin\",\"password\":\"$pw\"}" \
    -o /tmp/wanportal-login.json || true
  python3 - <<'PY' && ok "login response carries claims" || bad "login response missing claims"
import json,sys
d=json.load(open("/tmp/wanportal-login.json"))
if d.get("status")!="success": sys.exit(1)
if d.get("username")!="admin": sys.exit(1)
if d.get("is_admin") is not True: sys.exit(1)
e=d.get("exp")
if not isinstance(e,int) or e<=0: sys.exit(1)
sys.exit(0)
PY
fi

# Host header must not be used as API URL in netping.php
if grep -n "https://{\$server_name}" "$ROOT/htdocs/netping.php" >/dev/null; then
  bad "netping.php still interpolates SERVER_NAME into API URL"
else
  ok "netping.php does not use Host as API URL"
fi

if grep -n "strtolower(\$_SERVER\['HTTP_USER_AGENT'\]" "$ROOT/htdocs/netping.php" >/dev/null \
   && grep -n "echo \$content" "$ROOT/htdocs/netping.php" >/dev/null; then
  # dump still present: must be after a session gate
  if grep -n "check_session\|!\s*isset(\$_SESSION\['user'\])" "$ROOT/htdocs/netping.php" >/dev/null; then
    ok "netping.php agent dump is session-gated (or still present with a gate)"
  else
    bad "netping.php curl dump has no session gate"
  fi
else
  ok "netping.php does not dump agent source to curl UA"
fi

if grep -n "proxyRequest('DELETE', '/cgi-bin/api/" "$ROOT/htdocs/assets/js/listings.js" >/dev/null; then
  bad "listings.js DELETE paths still double-prefix /cgi-bin/api"
else
  ok "listings.js DELETE paths are API-relative"
fi

if grep -nE 'filter[[:space:]]*=>[[:space:]]*".*\$username' "$ROOT/cgi-bin/auth.pm" >/dev/null; then
  bad "auth.pm LDAP filter interpolates raw username"
else
  ok "auth.pm LDAP filter does not interpolate raw username"
fi

if grep -n 'scalar localtime' "$ROOT/cgi-bin/auth.pm" "$ROOT/cgi-bin/users.pm" >/dev/null; then
  bad "lockout still compares DATETIME to scalar localtime"
else
  ok "lockout does not use scalar localtime string compare"
fi

if grep -n "fields .= ', password' if \$c->stash('jwt_payload')" "$ROOT/cgi-bin/agent.pm" >/dev/null; then
  bad "GET /agent/:id still appends password for any JWT"
else
  ok "agent detail does not append password for any JWT"
fi

if grep -n '\$datadir/\$id.rrd' "$ROOT/cgi-bin/public_api.pm" >/dev/null; then
  bad "public /rrd concatenates id into path without a tight allowlist"
else
  ok "public /rrd does not concat raw id into path"
fi

# cheap Host XSS: title must htmlspecialchars server_name
if grep -n "<title>' . \$server_name" "$ROOT/htdocs/lib/page.php" >/dev/null; then
  bad "page.php echoes \$server_name into <title> unescaped"
else
  ok "page.php escapes server name in title"
fi

rrd_code=$(curl -sS -o /tmp/wanportal-rrd.json -w '%{http_code}' --max-time 8 \
  "$API/rrd?id=../../../etc/passwd" || echo 000)
if [[ "$rrd_code" == "400" || "$rrd_code" == "404" ]]; then
  ok "GET /rrd traversal id rejected ($rrd_code)"
else
  bad "GET /rrd traversal id http $rrd_code (want 400/404)"
fi

if grep -q cron-run-agent "$ROOT/Dockerfile" && grep -q 'su -s /bin/sh apache' "$ROOT/Dockerfile"; then
  ok "Dockerfile cron drops to apache via image wrappers"
else
  bad "Dockerfile cron still runs bind-mount scripts as root"
fi

echo
echo "== tests/run.sh =="
if [[ -f "$ROOT/tests/run.sh" ]]; then
  if bash "$ROOT/tests/run.sh"; then
    ok "tests/run.sh"
  else
    bad "tests/run.sh"
  fi
else
  echo "SKIP  tests/run.sh (not present)"
fi

echo
echo "passed=$pass failed=$fail"
exit $(( fail > 0 ? 1 : 0 ))
