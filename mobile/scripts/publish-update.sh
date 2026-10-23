#!/usr/bin/env bash
# The ONLY way to publish an over-the-air update. Runs the unit tests and the bundle check first, shows what will go out, then publishes.
#   scripts/publish-update.sh preview "what changed"       (APK builds)
#   scripts/publish-update.sh production "what changed"    (Play Store builds)
# Afterwards open the app on your own phone (close it, open it twice) and look at admin app -> Mobile app: "The newest update looks healthy".
set -euo pipefail
cd "$(dirname "$0")/.."
CHANNEL="${1:-}"; MSG="${2:-}"
case "$CHANNEL" in preview|production) ;; *) echo "Usage: scripts/publish-update.sh <preview|production> \"what changed\""; exit 1;; esac
[ -n "$MSG" ] || { echo "Say what changed (it is shown in the Expo dashboard)."; exit 1; }
if [ -n "$(git status --porcelain -- . | head -1)" ]; then echo "FAIL: there are uncommitted changes in mobile/. Commit first, so the update matches a commit."; exit 1; fi
echo "1/4 unit tests"; npm test --silent >/tmp/mobile-test.out 2>&1 || { cat /tmp/mobile-test.out | tail -20; echo "FAIL: unit tests"; exit 1; }; grep -E "^# (pass|fail)" /tmp/mobile-test.out
echo "2/4 bundle check"; bash scripts/check-bundle.sh
RT="$(node -p "require('./app.json').expo.runtimeVersion")"; VER="$(node -p "require('./app.json').expo.version")"
echo "3/4 this update goes to builds with runtimeVersion $RT on channel '$CHANNEL' (app.json version $VER); the EAS '$CHANNEL' environment must hold the server address"
eas env:list "$CHANNEL" 2>/dev/null | grep -q "EXPO_PUBLIC_API_URL=https://app.tripsarthi.com" || { echo "FAIL: EXPO_PUBLIC_API_URL is missing in the EAS '$CHANNEL' environment (eas env:create --environment $CHANNEL --name EXPO_PUBLIC_API_URL --value https://app.tripsarthi.com --visibility plaintext)"; exit 1; }
echo "4/4 publishing"; eas update --channel "$CHANNEL" --environment "$CHANNEL" --message "$MSG" --non-interactive
echo; echo "Published. Now: close the app on your phone and open it twice, then check admin app -> Mobile app (update watch)."
