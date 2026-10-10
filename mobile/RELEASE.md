# TripSarthi mobile — build & release checklist

Status when this was written: the app is **bundled (`npx expo export`) and unit-tested, but has never been installed on a phone or submitted to a store.**
Tick things off in order. 👤 = only the owner can do it (accounts, payments, legal). 🛠 = can be done in the repo.

## 0. Decide BEFORE the first build (cannot be changed after the app is published)
- [ ] 👤🛠 **Bundle id / package name.** It is still `com.gamavis.travelpilot` (the old product name) in `app.json` (iOS `bundleIdentifier` and Android `package`), plus `slug: travelpilot` and `scheme: travelpilot`. A store id is permanent once published. Recommended: `com.gamavis.tripsarthi`, slug `tripsarthi`, scheme `tripsarthi`. Do this before `eas init`.
- [ ] 🛠 **Icon and splash** (`assets/icon.png`, `splash-icon.png`, Android adaptive icon files) must be the TripSarthi logo. iOS icon: 1024x1024 PNG, no transparency.
- [ ] 🛠 **`supportsTablet: true`** means Apple requires iPad screenshots and the layouts must look right on iPad. The app is phone-first: set it to `false` unless you want to test iPad.
- [ ] 👤 **Publisher accounts** (start now, they are the slowest step):
  - Apple Developer Program (about USD 99 a year). As a company (Gamavis Software Solutions) you enrol as an *organisation*, which needs a D-U-N-S number and can take days to weeks. An individual account is faster but shows a person's name as the seller.
  - Google Play Console (about USD 25 once). Google currently asks new *personal* accounts to run a closed test with a minimum number of testers for a set number of days before production; an organisation account avoids that. Check the current rule when you register.
- [ ] 👤 **Privacy policy URL.** Both stores require one. The website's privacy page is still a **draft that needs a lawyer's review** before you publish it as the app's policy. Say plainly that the app shows your customers' names, phone numbers, messages and payment amounts, and uses a push token and device id.
- [ ] 👤🛠 **A review login.** Apple and Google reviewers must be able to sign in. Create a separate workspace on the live site (for example "TripSarthi Review") with a few made-up enquiries, a quote and a booking. Never give reviewers a real customer workspace. Put its email and password only in the store consoles' review notes, never in the repo.
- [ ] 🛠 The app has no sign-up and no in-app account deletion. That is acceptable only while accounts are created by you on the web. If you ever add sign-up in the app, both stores require in-app account deletion.

## 1. Expo / EAS setup (one time, about 30 minutes)
- [ ] 👤 `npm i -g eas-cli && eas login` (create a free Expo account; use a company mailbox, not a personal one).
- [ ] `cd mobile && eas init` writes `extra.eas.projectId` into `app.json`. **Commit that change.**
- [ ] `eas.json` is already in the repo: `development`, `preview` (internal; Android builds an installable APK) and `production` (auto-increments the build number). All three set `EXPO_PUBLIC_API_URL=https://app.tripsarthi.com`.
  **This matters:** without it the app talks to `http://localhost:8731` and nothing works on a real phone.
- [ ] Run `eas build:configure` once; it validates `eas.json` against the installed EAS CLI.

## 2. Push notification credentials
- [ ] 👤 **iOS:** `eas credentials` and let EAS create the APNs key and provisioning profile (needs the Apple Developer account from step 0).
- [ ] 👤 **Android:** create a Firebase project, add an Android app with your package name, download `google-services.json`, set `expo.android.googleServicesFile` in `app.json`, and upload an **FCM V1 service-account key** with `eas credentials`.
- [ ] ⚠ **The GitHub repository is public.** Never commit the FCM service-account JSON, `.p8` keys or `.mobileprovision` files. Keep `google-services.json` out of the repo too (store it as an EAS file secret) and add it to `.gitignore`.
- [ ] Server (already done on https://app.tripsarthi.com): the `push:run` cron runs every minute; `PUSH_MOCK_MODE` is off. Optional: set `EXPO_ACCESS_TOKEN` in `backend/.env` (Expo enhanced push security), then `systemctl reload php8.3-fpm`.

## 3. First test builds on real phones (do this before any store work)
- [ ] **Android:** `eas build --platform android --profile preview` gives an APK download link. Install it on 2-3 phones (allow "install unknown apps").
- [ ] **iOS:** `eas device:create` (each tester registers their iPhone), then `eas build --platform ios --profile preview` (ad hoc, up to 100 devices a year). Or skip device registration and use TestFlight with a `production` build + `eas submit`.
- [ ] Push tokens do not exist in simulators; push can only be tested on real phones.

## 4. Test script on a real phone (use a test workspace, then repeat once with a real agent login)
Sign in and navigation
- [ ] Login works over HTTPS; a wrong password shows a clear message; an expired login signs the user out cleanly.
- [ ] **Face ID / fingerprint lock:** Settings → Lock the app; locks on cold start and after 60 s in the background; app-switcher cover works.
- [ ] Sign out wipes saved data (sign in as another user and confirm nothing from the first user shows).
Daily work
- [ ] Enquiries: list, filter, new enquiry in about 20 seconds, trip detail, AI quote draft, price adjustment, send quote.
- [ ] Chats: reply inside the 24-hour window; outside it only approved templates can be sent.
- [ ] Payments due: open a booking, send a pay link, send a reminder, mark paid.
- [ ] **Targets tab:** as an agent (own bars only) and as an owner/admin (team bar, people list, tap into one person, back to team). Check "no target set" wording.
- [ ] Airplane mode: amber "Offline" banner on lists you opened before; sending is blocked; errors you must act on are not hidden.
Push (needs the phone to have allowed notifications)
- [ ] Settings → **Send me a test**.
- [ ] Real events: a new lead, a customer reply, a quote opened, a payment, a booking confirmed. Check each arrives with the phone locked.
- [ ] Tapping a push opens the right screen, including from a fully closed app.
- [ ] "Minimal" privacy mode hides names and amounts on the lock screen; quiet hours hold pushes back; per-category switches work.
- [ ] **Target nudges** fire only at about 11:00 IST on day 10 and day 20 of a month, for an agent who is behind pace. To test earlier, ask for a one-off "send me a sample" button or a test clock; do not move the server date.
Platform quirks
- [ ] Android back button, rotation (the app is portrait-only), large system font size, low battery mode.
- [ ] Slow or flaky mobile data (not only Wi-Fi).

## 5. Store listing material (prepare in parallel)
- [ ] Name TripSarthi; subtitle/short description (for example "CRM for Indian travel agents"); long description; keywords; category Business; support URL (https://tripsarthi.com/contact); privacy URL.
- [ ] Screenshots: at least 3-5 phone screenshots (Apple 6.7 inch or 6.5 inch sizes; Google needs phone, plus a 1024x500 feature graphic). iPad screenshots only if `supportsTablet` stays true.
- [ ] Data declarations: Apple "App Privacy" and Google "Data safety". Collected: name, email, phone numbers and message content of your customers (entered by the agent), push token and device id, usage of the app. Not sold, not used for tracking. Have the lawyer confirm the wording.
- [ ] Mention WhatsApp only descriptively ("reply to your customers on WhatsApp"); do not imply it is made or endorsed by WhatsApp or Meta.
- [ ] Age rating questionnaire: no objectionable content; it is a business tool.

## 6. Production build and submit
- [ ] 👤 Apple: create the app record in App Store Connect (bundle id from step 0). Google: create the app in Play Console; for Android you will also create a service-account key for EAS and grant it access in Play Console.
- [ ] `eas build --platform all --profile production`
- [ ] `eas submit --platform ios` and `eas submit --platform android`. Google's first release lands in the **internal testing** track; promote to production after testers confirm.
- [ ] iOS: submit through TestFlight first (a few staff for a day or two), then "Submit for review" with the review login from step 0.
- [ ] Release gradually (Google: start at 10%, then 100%). Reviews usually take from a day to about a week for a first submission.

## 7. After release
- [ ] **The API must stay backward-compatible.** Old app versions stay on phones for months. Never remove or rename an endpoint or a response field the app reads; add new ones instead. (The Targets push already degrades safely on older builds.)
- [ ] There is no over-the-air update (`expo-updates` is not installed), so every JavaScript fix needs a new store build and review. Consider adding it before launch if you expect frequent small fixes.
- [ ] Bump `version` in `app.json` for each release (build numbers auto-increment).
- [ ] Watch `push_log` (why a push was not delivered) and the server error log in the first week.
- [ ] Rotate any credential that was ever shared in chat or screenshots (the server root password, Apple/Google/Expo logins).

## Rough timeline
- Android internal test build on your own phones: same day (needs only the Expo account and the Firebase files).
- iOS test build: as fast as the Apple Developer enrolment (individual: about 1-2 days; organisation with D-U-N-S: often 1-3 weeks).
- First public store release: about 2-4 weeks including accounts, privacy review, TestFlight and store review.
