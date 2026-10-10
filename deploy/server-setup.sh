#!/usr/bin/env bash
# TripSarthi — first-time server setup (Ubuntu 22.04 / 24.04). Run as root ON THE SERVER:
#
#   curl -fsSL https://raw.githubusercontent.com/gamavissoftware/tripsharthi/main/deploy/server-setup.sh -o setup.sh
#   bash setup.sh
#
# What it does (safe to run again — it is idempotent and never overwrites an existing backend/.env):
#   PHP 8.3 + MySQL 8 + Composer + Node 20 + Caddy (automatic HTTPS) -> clones the repo -> builds the backend and the web app
#   -> creates the database and a production .env with generated secrets -> runs migrations -> writes the Caddy config
#   -> installs the scheduled jobs and nightly backups -> firewall (SSH, 80, 443) -> health checks.
#
# Settings can be overridden: DOMAIN=example.com APP_HOST=app.example.com REPO=... BRANCH=main bash setup.sh
set -euo pipefail

REPO="${REPO:-https://github.com/gamavissoftware/tripsharthi.git}"
BRANCH="${BRANCH:-main}"
DOMAIN="${DOMAIN:-tripsarthi.com}"
APP_HOST="${APP_HOST:-app.${DOMAIN}}"
APP_DIR="${APP_DIR:-/var/www/tripsarthi}"
ACME_EMAIL="${ACME_EMAIL:-manglesh@gamavis.com}"
DB_NAME="${DB_NAME:-tripsarthi}"
DB_USER="${DB_USER:-tripsarthi}"
PHP_V="8.3"
TEST_MODE="${TEST_MODE:-0}"          # 1 = running inside a container for testing: no firewall / no systemd / no Caddy start

c_ok='\033[0;32m'; c_warn='\033[0;33m'; c_err='\033[0;31m'; c_off='\033[0m'
log()  { echo -e "${c_ok}==>${c_off} $*"; }
warn() { echo -e "${c_warn}!!${c_off} $*"; }
die()  { echo -e "${c_err}ERROR:${c_off} $*" >&2; exit 1; }
svc()  { if [ "$TEST_MODE" = 1 ]; then service "$1" "$2" || true; else systemctl "$2" "$1"; fi; }

[ "$(id -u)" = 0 ] || die "Run as root (sudo bash setup.sh)."
[ -r /etc/os-release ] || die "Cannot detect the operating system."
. /etc/os-release
case "${ID}-${VERSION_ID}" in
  ubuntu-22.04|ubuntu-24.04) ;;
  *) die "This script supports Ubuntu 22.04 / 24.04 (found ${PRETTY_NAME:-unknown}). Tell us the OS and we will adapt it." ;;
esac
export DEBIAN_FRONTEND=noninteractive
# containers have no init system: let package scripts start/stop services through the old "service" wrapper
if [ "$TEST_MODE" = 1 ]; then printf '#!/bin/sh\nexit 0\n' > /usr/sbin/policy-rc.d; chmod +x /usr/sbin/policy-rc.d; fi

# ---------------------------------------------------------------------------------------------------- packages
log "Installing base packages"
apt-get update -y
apt-get install -y curl git unzip ca-certificates gnupg lsb-release software-properties-common cron openssl ufw fail2ban

log "Installing PHP ${PHP_V}"
if ! apt-cache policy "php${PHP_V}-fpm" 2>/dev/null | grep -q 'Candidate: [0-9]'; then
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
fi
apt-get install -y "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-mysql" "php${PHP_V}-mbstring" "php${PHP_V}-xml" "php${PHP_V}-curl" \
                   "php${PHP_V}-gd" "php${PHP_V}-intl" "php${PHP_V}-zip" "php${PHP_V}-bcmath"
cat > "/etc/php/${PHP_V}/fpm/conf.d/99-tripsarthi.ini" <<'EOF'
expose_php = Off
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 12M
max_execution_time = 60
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
EOF
cp "/etc/php/${PHP_V}/fpm/conf.d/99-tripsarthi.ini" "/etc/php/${PHP_V}/cli/conf.d/99-tripsarthi.ini"
sed -i 's/^opcache.enable = 1/opcache.enable = 1\nopcache.enable_cli = 0/' "/etc/php/${PHP_V}/cli/conf.d/99-tripsarthi.ini"

log "Installing MySQL 8"
if [ "$TEST_MODE" = 1 ]; then apt-get install -y mysql-server || { pkill -x mysqld || true; sleep 3; dpkg --configure -a; }; mkdir -p /var/run/mysqld && chown mysql:mysql /var/run/mysqld   # container-only quirk
else apt-get install -y mysql-server; fi
svc mysql start
for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
mysqladmin ping >/dev/null 2>&1 || die "MySQL did not start."

if ! command -v composer >/dev/null 2>&1; then
  log "Installing Composer"
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  [ "$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" = "$EXPECTED" ] || die "Composer installer checksum mismatch."
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

if ! command -v node >/dev/null 2>&1 || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  log "Installing Node.js 20"
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi

if [ "$TEST_MODE" != 1 ] && ! command -v caddy >/dev/null 2>&1; then
  log "Installing Caddy"
  {
    apt-get install -y debian-keyring debian-archive-keyring apt-transport-https
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list >/dev/null
    apt-get update -y
    apt-get install -y caddy
  } || die "Could not install Caddy from its package repository. Install it by hand (https://caddyserver.com/docs/install), then run this script again."
fi

# ---------------------------------------------------------------------------------------------------- code
log "Getting the code (${REPO} @ ${BRANCH})"
mkdir -p "$(dirname "$APP_DIR")"
if [ -d "$APP_DIR/.git" ]; then
  git -C "$APP_DIR" fetch --quiet origin "$BRANCH"
  git -C "$APP_DIR" checkout --quiet "$BRANCH"
  git -C "$APP_DIR" reset --hard --quiet "origin/$BRANCH"
else
  [ -e "$APP_DIR" ] && [ -n "$(ls -A "$APP_DIR" 2>/dev/null)" ] && die "$APP_DIR exists and is not a git checkout. Move it away first."
  git clone --quiet --branch "$BRANCH" "$REPO" "$APP_DIR"
fi
git config --global --add safe.directory "$APP_DIR" || true

# ---------------------------------------------------------------------------------------------------- dependencies, database + .env
mkdir -p "$APP_DIR/backend/writable"/{cache,logs,session,uploads,debugbar}     # CodeIgniter refuses to start without it
chown -R www-data:www-data "$APP_DIR/backend/writable"
log "Installing backend dependencies"
(cd "$APP_DIR/backend" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet)
ENV_FILE="$APP_DIR/backend/.env"
if [ ! -f "$ENV_FILE" ]; then
  log "Creating the database and a production .env (secrets are generated, never printed)"
  DB_PASS="$(openssl rand -hex 24)"
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  cp "$APP_DIR/backend/.env.example" "$ENV_FILE"
  setenv() {   # setenv KEY VALUE  — replaces "KEY = ..." (or "# KEY = ...") else appends
    local k="$1" v="$2" esc
    esc="$(printf '%s' "$v" | sed -e 's/[\/&|]/\\&/g')"
    if grep -qE "^[#[:space:]]*${k//./\\.}[[:space:]]*=" "$ENV_FILE"; then sed -i -E "s|^[#[:space:]]*${k//./\\.}[[:space:]]*=.*|${k} = ${esc}|" "$ENV_FILE"; else printf '%s = %s\n' "$k" "$v" >> "$ENV_FILE"; fi
  }
  setenv CI_ENVIRONMENT production
  setenv app.baseURL "'https://${APP_HOST}'"
  setenv APP_MODE saas
  setenv database.default.hostname localhost
  setenv database.default.database "$DB_NAME"
  setenv database.default.username "$DB_USER"
  setenv database.default.password "$DB_PASS"
  setenv database.default.DBDriver MySQLi
  setenv database.default.port 3306
  setenv CORS_ORIGIN "https://${APP_HOST},https://${DOMAIN},https://www.${DOMAIN}"
  setenv APP_URL "https://${APP_HOST}"
  setenv MAIL_FROM_ADDRESS "noreply@${DOMAIN}"
  setenv MAIL_FROM_NAME TripSarthi
  setenv CONTACT_NOTIFY_EMAIL "$ACME_EMAIL"
  for f in WHATSAPP_MOCK_MODE META_LEADS_MOCK_MODE RAZORPAY_MOCK_MODE EMAIL_MOCK_MODE AI_MOCK_MODE ADS_MOCK_MODE PUSH_MOCK_MODE CONVERSIONS_MOCK_MODE EINVOICE_MOCK_MODE; do setenv "$f" false; done
else
  log "backend/.env already exists — keeping it"
fi
if ! grep -qE '^encryption.key *= *[^ ]' "$ENV_FILE"; then
  (cd "$APP_DIR/backend" && php spark key:generate --force >/dev/null 2>&1) || true
  grep -qE '^encryption.key *= *[^ ]' "$ENV_FILE" || die "Could not generate the encryption key (php spark key:generate)."
fi
chown root:www-data "$ENV_FILE"; chmod 640 "$ENV_FILE"

# ---------------------------------------------------------------------------------------------------- build
mkdir -p "$APP_DIR/backend/writable"/{cache,logs,session,uploads,debugbar}
chown -R www-data:www-data "$APP_DIR/backend/writable"; chmod -R u+rwX,g+rwX "$APP_DIR/backend/writable"

log "Running database migrations"
(cd "$APP_DIR/backend" && runuser -u www-data -- php spark migrate --all) 2>&1 | tail -3

log "Building the web app (this takes a minute)"
(cd "$APP_DIR/frontend" && NODE_OPTIONS=--max-old-space-size=1024 npm ci --no-audit --no-fund --silent && NODE_OPTIONS=--max-old-space-size=1024 npm run build --silent)
chmod -R a+rX "$APP_DIR/frontend/dist" "$APP_DIR/website"

# ---------------------------------------------------------------------------------------------------- PHP-FPM, Caddy
svc "php${PHP_V}-fpm" restart || svc "php${PHP_V}-fpm" start
if [ "$TEST_MODE" != 1 ]; then
  log "Configuring Caddy (a copy of the old config is kept next to it)"
  usermod -aG www-data caddy
  [ -f /etc/caddy/Caddyfile ] && cp -n /etc/caddy/Caddyfile "/etc/caddy/Caddyfile.bak-$(date +%Y%m%d%H%M%S)" || true
  sed -e "s|{\$DOMAIN}|${DOMAIN}|g" -e "s|{\$APP_HOST}|${APP_HOST}|g" -e "s|{\$ACME_EMAIL}|${ACME_EMAIL}|g" -e "s|{\$APP_DIR}|${APP_DIR}|g" -e "s|{\$PHP_V}|${PHP_V}|g" \
      "$APP_DIR/deploy/Caddyfile" > /etc/caddy/Caddyfile.new
  caddy validate --config /etc/caddy/Caddyfile.new --adapter caddyfile >/dev/null 2>&1 || { caddy validate --config /etc/caddy/Caddyfile.new --adapter caddyfile; die "The generated Caddy config is invalid; the old one is untouched."; }
  mv /etc/caddy/Caddyfile.new /etc/caddy/Caddyfile
  systemctl enable caddy >/dev/null 2>&1 || true
  systemctl restart caddy
fi

# ---------------------------------------------------------------------------------------------------- scheduled jobs + backups
log "Installing scheduled jobs and nightly backups"
sed -e "s|{\$APP_DIR}|${APP_DIR}|g" "$APP_DIR/deploy/tripsarthi.cron" > /etc/cron.d/tripsarthi
chmod 644 /etc/cron.d/tripsarthi
chmod +x "$APP_DIR/deploy/"*.sh
mkdir -p /var/backups/tripsarthi && chmod 700 /var/backups/tripsarthi

# ---------------------------------------------------------------------------------------------------- firewall
if [ "$TEST_MODE" != 1 ]; then
  log "Firewall: allow SSH, HTTP and HTTPS only"
  ufw allow OpenSSH >/dev/null; ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null; ufw allow 443/udp >/dev/null
  ufw --force enable >/dev/null
  systemctl enable --now fail2ban >/dev/null 2>&1 || true
fi

# ---------------------------------------------------------------------------------------------------- checks
log "Health checks"
(cd "$APP_DIR/backend" && runuser -u www-data -- php spark golive:check 2>&1 | tail -25) || true
if [ "$TEST_MODE" != 1 ]; then
  sleep 3
  for u in "https://${DOMAIN}/" "https://${APP_HOST}/" "https://${APP_HOST}/api/v1/public/chat/00000000000000000000000000000000/poll"; do
    printf '%-62s %s\n' "$u" "$(curl -s -o /dev/null -m 20 -w '%{http_code}' "$u" || echo ERR)"
  done
  echo "(the last one should answer 404 — it proves the API is reachable)"
fi

cat <<EOF

------------------------------------------------------------------------------------------------
 DONE. Website: https://${DOMAIN}     App: https://${APP_HOST}
 Next steps (see ${APP_DIR}/deploy/DEPLOY.md):
  1. Open https://${APP_HOST}/#/register and create YOUR account.
  2. Make it the platform admin:   cd ${APP_DIR}/backend && php spark admin:grant you@yourmail.com
  3. Set real email (SMTP) in ${ENV_FILE}: MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD
  4. CHANGE THE ROOT PASSWORD, add an SSH key and disable password login (DEPLOY.md, section 5).
  5. Future updates:   bash ${APP_DIR}/deploy/update.sh
------------------------------------------------------------------------------------------------
EOF
