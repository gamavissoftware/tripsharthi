#!/usr/bin/env bash
# Runs the MySQL-backed integration tests (group "mysql") against the throwaway DB `travelpilot_test`.
# Credentials come from backend/.env; they are passed via the environment and never printed.
set -euo pipefail
cd "$(dirname "$0")/../backend"
get() { grep -E "^database\.default\.$1" .env | head -1 | sed -E 's/^[^=]*= *//'; }
PW="$(get password)"; USER="$(get username)"; HOST="$(get hostname)"
MYSQL_PWD="$PW" mysql -u"$USER" -h"$HOST" -e "CREATE DATABASE IF NOT EXISTS travelpilot_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
env database.tests.hostname="$HOST" database.tests.database=travelpilot_test database.tests.username="$USER" \
    database.tests.password="$PW" database.tests.DBDriver=MySQLi database.tests.charset=utf8mb4 database.tests.DBCollat=utf8mb4_general_ci database.tests.DBPrefix= database.tests.port=3306 \
    vendor/bin/phpunit --no-coverage --group mysql "$@"
