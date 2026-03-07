#!/usr/bin/env sh

set -eu

# Source environment file if it exists
if [ -f /srv/.env ]; then
    set -a  # automatically export all variables
    . /srv/.env
    set +a  # turn off automatic export
fi

# Function to check if mysql is available
check_mysql() {
    command -v mysql >/dev/null 2>&1
}

# Get AGENT_ID if not set and mysql exists
if [ -z "${AGENT_ID:-}" ] && check_mysql; then
    export AGENT_ID="$(mariadb --ssl=false  -h "${MYSQL_HOST:-wandb}" -P "${MYSQL_PORT:-3306}" -u "${MYSQL_USER:-root}" \
        --password="${MYSQL_PASSWORD:-netops}" --skip-column-names --batch "${MYSQL_DB:-netops}" \
        -e "SELECT id FROM agents WHERE password = 'LOCAL' LIMIT 1;" 2> /dev/null)"
else
    # Only try to read from /proc/1/environ if .env file wasn't found or didn't set the variables
    if [ -z "${AGENT_ID:-}" ]; then
        export $(cat /proc/1/environ | tr '\0' '\n' | grep -E '^(SERVER|PASSWORD|AGENT_ID)' 2>/dev/null || true)
    fi
fi

# Run the agent if AGENT_ID is set
[ -n "${AGENT_ID:-}" ] && {
    PASSWORD="${PASSWORD:-LOCAL}" SERVER="${SERVER:-http://localhost/cgi-bin/api}" /srv/netping-agent.pl
} || {
    echo "ERROR: AGENT_ID env not set"
    exit 1
}
