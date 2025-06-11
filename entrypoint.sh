#!/usr/bin/env sh

set -eu

# Configurable DB connection details (set via .env or docker-compose)
MYSQL_HOST="${MYSQL_HOST:-wandb}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-netops}"

# Wait for DB container/service to be ready
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

echo "Starting Apache..."
exec httpd -D FOREGROUND
