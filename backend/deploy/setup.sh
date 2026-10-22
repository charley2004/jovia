#!/usr/bin/env bash
# Jovia Network — first-time setup on the HostAfrica (DirectAdmin) server.
# Run once from the browser terminal:  bash ~/domains/joviaplatform.ng/app/backend/deploy/setup.sh
set -euo pipefail
source "$(dirname "$0")/common.sh"

if [ ! -f .env ]; then
    # Values can be passed in (DB_NAME=… DB_USER=… DB_PASS=… bash setup.sh) or typed when asked
    echo "Database details (DirectAdmin → MySQL Management; names start with your username, e.g. nairaup1_jovia)"
    [ -n "${DB_NAME:-}" ] || read -rp "  Database name: " DB_NAME
    [ -n "${DB_USER:-}" ] || read -rp "  Database user: " DB_USER
    [ -n "${DB_PASS:-}" ] || { read -rsp "  Database password: " DB_PASS; echo; }
    ADMIN=${ADMIN_EMAIL:-admin@joviaplatform.ng}

    cat > .env <<EOF
APP_NAME="Jovia Network"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://joviaplatform.ng

LOG_CHANNEL=single
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD='${DB_PASS}'

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=joviaplatform.ng,www.joviaplatform.ng

CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

# Emails (password resets, notifications). "log" = off. Use a DirectAdmin mailbox to switch on.
MAIL_MAILER=log
MAIL_HOST=mail.joviaplatform.ng
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=no-reply@joviaplatform.ng
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="no-reply@joviaplatform.ng"
MAIL_FROM_NAME="Jovia Network"

# Free key from aistudio.google.com (the Assistant tab stays off until set)
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.8-flash
EOF
    chmod 600 .env
    install_packages
    "$PHP" artisan key:generate --force
else
    echo ".env already exists — keeping it."
    ADMIN=${ADMIN_EMAIL:-admin@joviaplatform.ng}
    install_packages
fi

"$PHP" artisan migrate --force
# Creates task modules, built-in games, settings and the admin (prints a temporary password the first time)
ADMIN_EMAIL="$ADMIN" "$PHP" artisan db:seed --force
publish_web_root
optimize

echo
echo "✔ Setup finished. Next:"
echo "  1. Sign in at https://joviaplatform.ng/admin with $ADMIN and the temporary password above, then change it."
echo "  2. DirectAdmin → Cron Jobs, every minute:"
echo "     $PHP $APP_DIR/artisan schedule:run >/dev/null 2>&1"
