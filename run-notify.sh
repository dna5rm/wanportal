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

# Function to check if all required email variables are set
check_email_vars() {
    [ -n "${SITE_URL:-}" ] && [ -n "${SMTP_SERVER:-}" ] && [ -n "${FROM_EMAIL:-}" ] && [ -n "${TO_EMAIL:-}" ]
}

# Function to check if all required NTFY variables are set
check_ntfy_vars() {
    [ -n "${NTFY_SERVER:-}" ] && [ -n "${NTFY_TOPIC:-}" ]
}

# Check which notification method to use
if check_ntfy_vars; then
    # Running NTFY notification script
    /srv/notify-ntfy.pl
elif check_email_vars; then
    # Running email notification script
    /srv/notify-email.pl
fi
