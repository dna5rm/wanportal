#!/usr/bin/env sh

set -eu

# Source environment file if readable. When run via cron-run-notify the
# wrapper already sourced /srv/.env as root and preserved the vars through
# su -p, so an unreadable .env here (apache, mode 600) is not fatal: keep
# going and let the var checks below decide whether mail runs.
if [ -r /srv/.env ]; then
    set -a
    . /srv/.env
    set +a
else
    echo "Warning: /srv/.env not readable; relying on env inherited from cron wrapper"
fi

# RUN: notify-email.pl
if [ -n "${SMTP_SERVER:-}" ] && [ -n "${FROM_EMAIL:-}" ] && [ -n "${TO_EMAIL:-}" ]; then
    /srv/notify-email.pl
fi