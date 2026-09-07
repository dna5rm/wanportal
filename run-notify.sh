#!/usr/bin/env sh

set -eu

# Source environment file if it exists
if [ -r /srv/.env ]; then
    set -a
    . /srv/.env
    set +a
else
    echo "Warning: /srv/.env file not found"
    exit 0
fi

# RUN: notify-email.pl
if [ -n "${SMTP_SERVER:-}" ] && [ -n "${FROM_EMAIL:-}" ] && [ -n "${TO_EMAIL:-}" ]; then
    /srv/notify-email.pl
fi