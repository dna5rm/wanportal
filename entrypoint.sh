#!/usr/bin/env sh

set -eu

# Configurable DB connection details
MYSQL_HOST="${MYSQL_HOST:-wandb}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-netops}"

# Wait for the database to be ready
echo "Waiting for MySQL/MariaDB at $MYSQL_HOST:$MYSQL_PORT ..."
for i in $(seq 1 60); do
    if mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" -e 'SELECT 1;' >/dev/null 2>&1; then
        echo "MySQL is up!"
        break
    fi
    sleep 1
done

if ! mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" -e 'SELECT 1;' >/dev/null 2>&1; then
    echo "ERROR: MySQL is not available after 60 seconds, exiting."
    exit 1
fi

# Start background agent
start_agent() {
    while true; do
        AGENT_ID="00000000-0000-0000-0000-000000000000" PASSWORD="LOCAL" SERVER="http://localhost/cgi-bin/api" ./netping-agent.pl
        sleep 60
    done
}

# Run background agent
start_agent &
AGENT_PID=$!

# Function to handle termination signals
cleanup() {
    echo "Received termination signal, stopping background jobs..."
    kill "$AGENT_PID" || true
    wait "$AGENT_PID" 2>/dev/null || true
    exit
}

# Trap SIGINT and SIGTERM to cleanup
trap 'cleanup' INT TERM

# Start apache in foreground
exec httpd -D FOREGROUND
