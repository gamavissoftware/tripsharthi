#!/usr/bin/env bash
# Nightly backup: database dump + uploaded files (invoices, portal uploads, ad images). Kept 14 days in /var/backups/tripsarthi.
# Copy that folder off the server too (another machine or cloud storage) — a backup on the same disk does not survive a lost server.
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/tripsarthi}"; OUT=/var/backups/tripsarthi; STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUT"; umask 077
DB="$(grep -E '^database\.default\.database' "$APP_DIR/backend/.env" | head -1 | sed -E 's/^[^=]*= *//; s/[\x27"]//g')"
mysqldump --single-transaction --quick --routines "$DB" | gzip -9 > "$OUT/db-$STAMP.sql.gz"
tar -czf "$OUT/uploads-$STAMP.tar.gz" -C "$APP_DIR/backend/writable" uploads 2>/dev/null || true
find "$OUT" -type f -mtime +14 -delete
echo "$(date -Is) backup ok: $OUT/db-$STAMP.sql.gz"
