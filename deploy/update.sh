#!/usr/bin/env bash
# Deploy the latest code from GitHub. Run on the server:   bash /var/www/tripsarthi/deploy/update.sh
# Options:  --website-only   (only refresh the static website, nothing else)
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/tripsarthi}"; BRANCH="${BRANCH:-main}"; PHP_V="${PHP_V:-8.3}"
[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
git config --global --add safe.directory "$APP_DIR" || true
before="$(git -C "$APP_DIR" rev-parse --short HEAD)"
git -C "$APP_DIR" fetch --quiet origin "$BRANCH"
git -C "$APP_DIR" reset --hard --quiet "origin/$BRANCH"
after="$(git -C "$APP_DIR" rev-parse --short HEAD)"
echo "code: $before -> $after"
chmod -R a+rX "$APP_DIR/website"
if [ "${1:-}" = "--website-only" ]; then echo "website refreshed"; exit 0; fi

# back up the database before touching the schema
"$APP_DIR/deploy/backup.sh" >/dev/null 2>&1 && echo "backup taken" || echo "WARNING: backup failed — continuing"

cd "$APP_DIR/backend"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet
runuser -u www-data -- php spark migrate --all 2>&1 | tail -3
chown -R www-data:www-data writable

cd "$APP_DIR/frontend"
NODE_OPTIONS=--max-old-space-size=1024 npm ci --no-audit --no-fund --silent
NODE_OPTIONS=--max-old-space-size=1024 npm run build --silent
chmod -R a+rX dist

systemctl reload "php${PHP_V}-fpm"       # drop cached PHP code
sed -e "s|{\$APP_DIR}|${APP_DIR}|g" "$APP_DIR/deploy/tripsarthi.cron" > /etc/cron.d/tripsarthi && chmod 644 /etc/cron.d/tripsarthi
echo "deployed $after"
