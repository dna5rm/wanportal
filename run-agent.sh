#!/usr/bin/env sh

set -eu

# Single-instance guard: hold a non-blocking lock on fd 9 for the whole run
# (including the exec'd agent). If another instance is already running,
# exit quietly -- the next cron tick will pick it up.
exec 9>/var/lock/netping-agent.lock
flock -n 9 || exit 0

# Source environment file if it exists
if [ -r /srv/.env ]; then
    set -a
    . /srv/.env
    set +a
fi

# Check for the MariaDB client binary (`mariadb`; Alpine's mariadb-client
# package does not ship a `mysql` alias)
check_mariadb() {
    command -v mariadb >/dev/null 2>&1
}

# Get AGENT_ID if not set and the mariadb client exists
if [ -z "${AGENT_ID:-}" ] && check_mariadb; then
    # --ssl=false is intentional (SSL verification stays off). Credentials are
    # never echoed; a failed or empty query falls through to the empty check.
    AGENT_ID="$(mariadb --ssl=false -h "${MYSQL_HOST:-wandb}" -P "${MYSQL_PORT:-3306}" \
        -u "${MYSQL_USER:-root}" --password="${MYSQL_PASSWORD:-netops}" \
        --skip-column-names --batch "${MYSQL_DB:-netops}" \
        -e "SELECT id FROM agents WHERE name='LOCAL' LIMIT 1;" 2>/dev/null)" || true
    export AGENT_ID
else
    # Fallback: cron jobs do not inherit the container env, so pull
    # SERVER/PASSWORD/AGENT_ID from the init (PID 1) environment. Parse one
    # KEY=VALUE line at a time -- never `export $(...)`, which word-splits on
    # spaces in values and lets crafted environ entries inject assignments.
    # Only the three known keys are exported; already-set values win.
    if [ -z "${AGENT_ID:-}" ] && [ -r /proc/1/environ ]; then
        pid1_env="$(tr '\0' '\n' < /proc/1/environ 2>/dev/null || true)"
        while IFS= read -r kv || [ -n "$kv" ]; do
            case "$kv" in
                SERVER=*)   [ -n "${SERVER:-}" ]   || export "$kv" ;;
                PASSWORD=*) [ -n "${PASSWORD:-}" ] || export "$kv" ;;
                AGENT_ID=*) export "$kv" ;;
            esac
        done <<EOF
$pid1_env
EOF
    fi
fi

# Run the agent only with a concrete AGENT_ID; an empty one is a hard failure.
if [ -z "${AGENT_ID:-}" ]; then
    echo "ERROR: AGENT_ID env not set" >&2
    exit 1
fi

export PASSWORD="${PASSWORD:-LOCAL}"
export SERVER="${SERVER:-http://localhost/cgi-bin/api}"
exec /srv/netping-agent.pl