#!/usr/bin/env bash
# Builds the app's JavaScript the same way an over-the-air update is built, and checks the one thing that once broke every phone: the server address.
# Fails (exit 1) unless the bundle contains https://app.tripsarthi.com and NO development address. Usage: scripts/check-bundle.sh
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="$(mktemp -d)"; trap 'rm -rf "$OUT"' EXIT
EXPO_PUBLIC_API_URL="${EXPO_PUBLIC_API_URL:-https://app.tripsarthi.com}" npx expo export --clear --platform android --output-dir "$OUT" >/dev/null 2>&1 || { echo "FAIL: the app does not bundle (run: npx expo export --platform android)"; exit 1; }
B="$(ls "$OUT"/_expo/static/js/android/*.hbc | head -1)"
good=$(strings "$B" | grep -c "https://app.tripsarthi.com" || true)
bad=$(strings "$B" | grep -cE "localhost:8731|localhost:8085|10\.0\.2\.2|192\.168\.[0-9]+\.[0-9]+:8731" || true)
if [ "$good" -lt 1 ]; then echo "FAIL: the bundle does not contain https://app.tripsarthi.com"; exit 1; fi
if [ "$bad" -gt 0 ]; then echo "FAIL: the bundle contains a development server address ($bad places). Phones would not connect."; strings "$B" | grep -oE ".{0,30}(localhost:8731|localhost:8085|10\.0\.2\.2|192\.168\.[0-9]+\.[0-9]+:8731).{0,10}" | head -3; exit 1; fi
echo "OK: bundle uses https://app.tripsarthi.com and no development address"
