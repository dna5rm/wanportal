#!/usr/bin/env sh

set -eu

# Source environment file if it exists
if [ -f /srv/.env ]; then
    set -a  # automatically export all variables
    . /srv/.env
    set +a  # turn off automatic export
else
    echo "Warning: /srv/.env file not found"
    exit 0
fi

# RUN: notify-email.pl
if [ -n "${SMTP_SERVER:-}" ] && [ -n "${FROM_EMAIL:-}" ] && [ -n "${TO_EMAIL:-}" ]; then
    /srv/notify-email.pl
fi