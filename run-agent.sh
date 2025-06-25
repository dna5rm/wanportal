#!/usr/bin/env sh

set -eu

# Function to check if mysql is available
check_mysql() {
    command -v mysql >/dev/null 2>&1
}

# Get AGENT_ID is not set and mysql exists
if [ -z "${AGENT_ID:-}" ] && check_mysql; then
    export AGENT_ID="$(mysql -h "${MYSQL_HOST:-wandb}" -P "${MYSQL_PORT:-3306}" -u "${MYSQL_USER:-root}" \
        --password="${MYSQL_PASSWORD:-netops}" --skip-column-names --batch "${MYSQL_DB:-netops}" \
        -e "SELECT id FROM agents WHERE password = 'LOCAL' LIMIT 1;" 2> /dev/null)"
else
    export $(cat /proc/1/environ | tr '\0' '\n' | grep -E '^(SERVER|PASSWORD|AGENT_ID)')
fi

# Run the agent if AGENT_ID is set
[ -n "${AGENT_ID:-}" ] && {
    PASSWORD="${PASSWORD:-LOCAL}" SERVER="${SERVER:-http://localhost/cgi-bin/api}" /srv/netping-agent.pl
} || {
    echo "ERROR: AGENT_ID env not set"
    exit 1
}
