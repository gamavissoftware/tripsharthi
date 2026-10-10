# TripSarthi mobile app (Expo / React Native)

Agent app: enquiries (create one in 20 seconds), trip & booking detail, **WhatsApp chats** (reply inside the 24-hour window, approved templates outside it), **AI quote drafting + price adjustment + send**, payments due, **push notifications**, **offline cache** and an optional **biometric app lock**.

## Run it
```bash
cd mobile && npm install
EXPO_PUBLIC_API_URL=http://<your-computer-LAN-IP>:8731 npx expo start     # a phone cannot reach "localhost"
```
Everything except push works in Expo Go.

## Offline & privacy
- Lists and details you have opened (enquiries, a trip, payments due, chats) are saved on the phone. With no signal you see them under an amber "Offline — showing saved data from …" banner, and sending is disabled until you are back online. Errors you must act on (expired login, a rejected form) are never hidden behind saved data.
- Saved data is wiped when you sign out. Settings → "Lock the app" asks for Face ID / fingerprint / passcode on open and after 60 s in the background, and hides the app in the app switcher. It cannot be turned on unless biometrics/passcode are set up on the phone and you pass the check once.
- **Targets tab** (🎯): month-to-date progress against the monthly sales target, same bars and "even pace" tick as the web dashboard. Agents see their own revenue + bookings bars; owners/admins see the team bar and each person (tap to open one). Reads `GET /api/v1/home/targets[?user=ID]` (offline-cached, stale data is labelled). Targets are SET on the web (Settings → Sales targets). The "behind pace" push opens this tab.
- `npm test` runs the pure-logic tests (cache rules, lock timing, validation). Everything else was bundled (`npx expo export`) but **not run on a device** — test the chat, lock and offline flows on a real phone before rollout.

## Push notifications — what you must set up (one time)
Push needs real credentials and a **development build**; none of this can be faked from code.

1. **Expo project**: `npm i -g eas-cli && eas login && eas init` — this writes `extra.eas.projectId` into `app.json`.
   Without it the Settings screen says "this build has no Expo project ID" (the app never crashes).
2. **iOS**: an Apple Developer account. `eas credentials` → let EAS create/upload an **APNs key**. Bundle id: `com.gamavis.travelpilot`.
3. **Android**: create a Firebase project, add an Android app `com.gamavis.travelpilot`, put `google-services.json` in `mobile/`,
   set `expo.android.googleServicesFile` in `app.json`, and upload an **FCM V1 service-account key** with `eas credentials`.
4. **Build**: `eas build --profile development --platform ios` (and/or android). Android remote push does **not** work in Expo Go (SDK 53+); iOS device builds do.
5. **Server**: set `EXPO_ACCESS_TOKEN` (optional, enables Expo's enhanced security) and schedule the cron
   `* * * * * php /path/to/backend/spark push:run` (retries, delivery receipts, morning briefing).
   Local development: `PUSH_MOCK_MODE=true` makes the server pretend to send.
6. **Test**: sign in on the phone → allow notifications → Settings → "Send me a test".

## How it behaves
- The OS permission dialog appears only after an in-app explanation, and only once (iOS never lets an app ask twice).
- Tapping a notification opens the right screen (trip, booking or contact) — also from a cold start.
- Signing out removes this phone from the account's alerts; signing in as someone else on the same phone moves it.
- Per-category switches, quiet hours (India time) and a "hide details on the lock screen" mode are in Settings.
- Not verified here: no physical device or simulator run (push tokens do not exist in simulators). The server side is tested end to end.
