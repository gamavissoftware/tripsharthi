#!/usr/bin/env bash
# Removes the DEMO data from one workspace (see demo_cleanup.sql for exactly what counts as demo).
#
#   bash scripts/demo_cleanup.sh <tenant_id>                  # dry run: shows what WOULD be removed, changes nothing
#   bash scripts/demo_cleanup.sh <tenant_id> --apply          # backs up the DB, asks you to confirm, then deletes
#     --reset-profile   also blank the business profile + document counters (only if no real invoices remain)
#     --no-backup       skip the pre-delete DB backup (local scratch databases only)
#     --yes             skip the confirmation question
#
# Database credentials come from backend/.env (never printed). Override the database name with DEMO_CLEANUP_DB
# (used to rehearse on a scratch copy). On the live server run it from the app directory, e.g.
#   cd /var/www/html/tripsarthi && bash scripts/demo_cleanup.sh 1            # look first
#   cd /var/www/html/tripsarthi && bash scripts/demo_cleanup.sh 1 --apply
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/backend"

TENANT="${1:-}"; shift || true
[[ "$TENANT" =~ ^[0-9]+$ ]] || { echo "usage: $0 <tenant_id> [--apply] [--reset-profile] [--no-backup] [--yes]" >&2; exit 2; }
APPLY=0; RESET=0; BACKUP=1; YES=0
for a in "$@"; do
  case "$a" in
    --apply) APPLY=1 ;; --reset-profile) RESET=1 ;; --no-backup) BACKUP=0 ;; --yes) YES=1 ;;
    *) echo "unknown option: $a" >&2; exit 2 ;;
  esac
done

get() { grep -E "^database\.default\.$1" .env | head -1 | sed -E 's/^[^=]*= *//' | tr -d " \"'"; }
export MYSQL_PWD="$(get password)"
DBUSER="$(get username)"; DBHOST="$(get hostname)"; DB="${DEMO_CLEANUP_DB:-$(get database)}"
my() { mysql -u"$DBUSER" -h"$DBHOST" --default-character-set=utf8mb4 "$@"; }

NAME="$(my -N "$DB" -e "SELECT name FROM tenants WHERE id = $TENANT" 2>/dev/null || true)"
[[ -n "$NAME" ]] || { echo "No tenant $TENANT in database $DB." >&2; exit 1; }
echo "Database: $DB   Tenant: #$TENANT ($NAME)   Mode: $([[ $APPLY == 1 ]] && echo APPLY || echo 'dry run')"

if [[ $APPLY == 1 ]]; then
  if [[ $BACKUP == 1 ]]; then
    [[ -x "$ROOT/deploy/backup.sh" || -f "$ROOT/deploy/backup.sh" ]] || { echo "deploy/backup.sh not found; use --no-backup only for scratch databases." >&2; exit 1; }
    echo "Backing up first..."
    APP_DIR="$ROOT" bash "$ROOT/deploy/backup.sh" || { echo "Backup failed - nothing was deleted." >&2; exit 1; }
  fi
  if [[ $YES != 1 ]]; then
    read -r -p "This permanently deletes the demo data of tenant #$TENANT ($NAME). Type the tenant id to continue: " ans
    [[ "$ans" == "$TENANT" ]] || { echo "Cancelled."; exit 1; }
  fi
fi

{ printf 'SET @t=%d, @apply=%d, @reset_profile=%d;\n' "$TENANT" "$APPLY" "$RESET"; cat "$ROOT/scripts/demo_cleanup.sql"; } | my -t "$DB"
