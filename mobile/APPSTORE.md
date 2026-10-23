# Publishing TripSarthi on the Apple App Store (iPhone)

The app is already iOS-ready in code (Expo SDK 57, bundle id `com.gamavis.tripsarthi`, push entitlement, camera/photo/microphone/contacts/Face ID usage texts come from the Expo plugins in app.json). What is missing is YOUR Apple account; nothing below can be done without it.

## 1. Accounts (YOU)
1. Join the **Apple Developer Program** (US$99 a year) as an organisation (Gamavis Software Solutions; Apple asks for a D-U-N-S number, which takes days).
2. In App Store Connect create the app: name **TripSarthi**, bundle id `com.gamavis.tripsarthi`, primary language English (India), category Business.

## 2. Build and test
```bash
cd mobile
eas credentials            # let EAS create the distribution certificate + provisioning profile (it asks for your Apple login)
eas build --platform ios --profile production
eas submit --platform ios --latest      # uploads to TestFlight
```
Install from TestFlight on your own iPhone and test: sign-in, push (needs the APNs key that `eas credentials` offers to create), voice note, camera, offline task, Hindi, dark mode, Face ID lock, contacts import.

## 3. Push on iPhone
Expo push uses Apple's APNs key: `eas credentials` -> iOS -> Push Notifications -> create a key. Nothing to change on the server (it already sends through Expo).

## 4. App Store listing
Use the text in PLAYSTORE.md (shorter limits: name 30, subtitle 30, description 4000). Screenshots: Apple wants 6.9-inch and 6.5-inch iPhone sizes (1320x2868 and 1284x2778); the images in `store/play/` are 1080x1920, so re-make them with the same script at those sizes or capture on an iPhone. Privacy "nutrition label": same table as the Play data-safety section. Account deletion: the same URL (Apple requires in-app deletion for apps with sign-up; this app has no in-app sign-up, and the More screen links to the deletion page).

## 5. Review notes for Apple
Give a demo login (a dedicated review workspace, never a real customer), and say: "TripSarthi is a business tool for travel agencies. Accounts are created on tripsarthi.com; there is no in-app purchase."
