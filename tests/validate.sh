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

  # the /login response must carry the claims themselves (the SPA holds no
  # server-side session)
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

# GET /netping-script serves the agent script source to JWT callers
# (the same /srv/agent/netping-agent.pl the local cron polling agent runs).
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 8 "$API/netping-script" || echo 000)
if [[ "$code" == "401" ]]; then
  ok "GET /netping-script without bearer is 401"
else
  bad "GET /netping-script without bearer http $code (want 401)"
fi

if [[ -n "$tok" ]]; then
  curl -sS --max-time 8 "${auth[@]}" "$API/netping-script" -o /tmp/wanportal-netping-script.json || true
  python3 - "$ROOT/agent/netping-agent.pl" <<'PY' && ok "GET /netping-script returns script source" || bad "GET /netping-script wrong or does not match disk"
import json, sys
d = json.load(open("/tmp/wanportal-netping-script.json"))
if d.get("status") != "success": sys.exit(1)
if d.get("filename") != "netping-agent.pl": sys.exit(1)
disk = open(sys.argv[1], "r", encoding="utf-8", errors="replace").read()
if d.get("content") != disk: sys.exit(1)
sys.exit(0)
PY
fi

# ---- URL contract (SPA-only UI) -------------------------------------------
# The Vue SPA is the only UI, served at /. /classic must not serve a
# server-rendered PHP console (404 or anything else non-PHP passes); /app
# must 404; the CGI API base /cgi-bin/api is untouched (covered by the
# /health check above).
site_root="${WANPORTAL_BASE:-${API%/cgi-bin/api}}"
fetch() { curl -sSL -o "$2" -w '%{http_code}' --max-time 8 "$1" || echo 000; }
is_spa() { grep -q 'id="app"' "$1" && grep -q 'type="module"' "$1"; }
is_php_console() {
  grep -q '</html>' "$1" && ! grep -q 'id="app"' "$1" &&
    grep -q -e 'assets/base\.css' -e 'listings\.js' \
            -e 'cdn\.datatables' -e 'Cache-Control' "$1"
}

echo
echo "== url contract =="
code=$(fetch "$site_root/" /tmp/wanportal-root.html)
if [[ "$code" == "200" ]] && is_spa /tmp/wanportal-root.html; then
  ok "GET / serves the SPA shell (div#app + module script)"
else
  bad "GET / http $code is not the SPA shell (want 200 with div#app; the SPA is served at /)"
fi

spa_src=$(grep -o '<script[^>]*type="module"[^>]*' /tmp/wanportal-root.html 2>/dev/null |
          grep -o 'src="[^"]*"' | head -1 | cut -d'"' -f2 || true)
spa_asset_code="000"
if [[ -n "$spa_src" ]]; then
  case "$spa_src" in
    http*) spa_asset_url="$spa_src" ;;
    *)     spa_asset_url="$site_root$spa_src" ;;
  esac
  spa_asset_code=$(fetch "$spa_asset_url" /tmp/wanportal-spa-asset.js)
fi
if [[ "$spa_asset_code" == "200" ]]; then
  ok "SPA module bundle resolves ($spa_src -> 200)"
else
  bad "SPA module bundle does not resolve (http $spa_asset_code from ${spa_src:-<no module script>})"
fi

classic_code=$(fetch "$site_root/classic" /tmp/wanportal-classic.html)
if [[ "$classic_code" != "200" ]] || ! is_php_console /tmp/wanportal-classic.html; then
  ok "GET /classic is not a PHP page (http $classic_code; the SPA is the only UI)"
else
  bad "GET /classic http $classic_code serves a PHP page (want: /classic is not a PHP page)"
fi

app_code=$(fetch "$site_root/app" /tmp/wanportal-app.html)
if [[ "$app_code" == "404" ]]; then
  ok "GET /app is 404 (the SPA is at /)"
else
  bad "GET /app http $app_code (want 404: the SPA is at /)"
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

rrd_code=$(curl -sS -o /tmp/wanportal-rrd.json -w '%{http_code}' --max-time 8 \
  "$API/rrd?id=../../../etc/passwd" || echo 000)
if [[ "$rrd_code" == "400" || "$rrd_code" == "404" ]]; then
  ok "GET /rrd traversal id rejected ($rrd_code)"
else
  bad "GET /rrd traversal id http $rrd_code (want 400/404)"
fi

if grep -q cron-run-agent "$ROOT/Dockerfile" && grep -qE 'su .*-s /bin/sh apache' "$ROOT/Dockerfile"; then
  ok "Dockerfile cron drops to apache via image wrappers"
else
  bad "Dockerfile cron still runs bind-mount scripts as root"
fi

# Addon reverse-proxy hook: proxy modules load in the image and exactly one
# IncludeOptional overlays operator routes. File-based check on purpose —
# asserting loaded modules would require a rebuild on every image bump.
if grep -q 'LoadModule proxy_module modules/mod_proxy.so' "$ROOT/Dockerfile" \
   && grep -q 'LoadModule proxy_http_module modules/mod_proxy_http.so' "$ROOT/Dockerfile" \
   && grep -q 'IncludeOptional /srv/conf/addons-proxy.conf' "$ROOT/Dockerfile" \
   && ! grep -qE '^[[:space:]]*ProxyPass' "$ROOT/Dockerfile"; then
  ok "Dockerfile enables mod_proxy and the addon IncludeOptional (no hardcoded ProxyPass)"
else
  bad "Dockerfile addon proxy hook missing (proxy modules / IncludeOptional / ProxyPass leaked into image)"
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
