# Publishing TripSarthi on Google Play

Everything below is ready to copy. Steps marked **YOU** need your Google account or a decision from you; nothing is submitted for you.

## 1. One-time account setup (YOU)
1. Create a Google Play Developer account at https://play.google.com/console (one-time fee, US$25). Register it as an **Organisation** (Gamavis Software Solutions) - Google asks for a D-U-N-S number and a verification that can take several days. A personal account created after Nov 2023 must run a closed test with 12+ testers for 14 days before it may publish.
2. Create the app: name **TripSarthi**, default language English (India), App, Free.

## 2. Build the release file
```bash
cd mobile
eas build --platform android --profile production     # makes an .aab (not an .apk); versionCode goes up by itself
```
Upload the `.aab` under **Testing > Internal testing** first, install from the Play link on your own phone, then promote to Production.
Google Play signs the app for you ("Play App Signing"); EAS keeps the upload key.

## 3. Store listing text
**App name (30):** TripSarthi - Travel CRM
**Short description (80):** Enquiries, quotes, bookings and WhatsApp chats for travel agents, in your pocket.
**Full description (4000):**
> TripSarthi is the business app for Indian travel agencies and tour operators. Run your whole day from your phone.
>
> • Enquiries: add a customer in seconds, track every trip from enquiry to booking.
> • Quotes: draft a quote with AI, adjust the markup, and send it on WhatsApp.
> • WhatsApp inbox: reply to customers, send photos, documents and voice notes, assign chats to your team.
> • Bookings and payments: see who owes you, send payment links and reminders, record payments.
> • Tasks and follow-ups: never let an enquiry go cold. Works offline: tasks, contacts and enquiries you save without internet are sent automatically later.
> • Dashboard and targets: today's numbers, revenue, collections, departures and your sales target.
> • Alerts: instant notifications for new leads, replies, payments and quotes viewed.
> • Everything else in TripSarthi (suppliers, itineraries, ads, GST invoices, reports and settings) is available inside the app too.
> • Available in English and Hindi, with light and dark mode and an optional fingerprint / Face ID lock.
>
> You need a TripSarthi account. Create one at tripsarthi.com.

**Category:** Business. **Tags:** CRM, Travel. **Contact e-mail:** support address you want shown publicly. **Website:** https://tripsarthi.com

## 4. Graphics (YOU / design)
- App icon 512x512 PNG: `mobile/assets/icon.png` (resize).
- Feature graphic 1024x500: not made yet. Use the logo on the navy/blue gradient.
- Phone screenshots (2-8, 1080x1920 or similar): take them on your phone from the **Home, Enquiries, Chat, Quote, Bookings, Tasks** screens using the demo workspace login (showcase@tripsarthi.test is dev-only; on live use your demo data).

## 5. Policy forms (Play Console > App content)
- **Privacy policy URL:** https://tripsarthi.com/privacy.html (the site's policy is still a DRAFT: have a lawyer finish it before publishing; it must mention the phone app, the microphone/camera/photos, push tokens and crash reports).
- **App access:** login required - give Google a test account (create a dedicated "play-review" workspace; never a real customer's).
- **Ads:** No. **Target audience:** 18+. **News/government/health/finance app:** No.
- **Data safety** (what the app collects; all encrypted in transit, none sold, none used for advertising):

| Data | Collected | Why | Shared |
|---|---|---|---|
| Name, e-mail, phone | Yes | Account and the customer records the agent types in | No |
| Photos / files / audio | Yes, only what the user picks or records to send in a chat | App functionality | No (goes to WhatsApp via the user's own business account) |
| Device or other IDs (push token) | Yes | Push notifications | No |
| Crash logs / diagnostics | Yes | Fixing bugs | No |
| Messages | Yes | WhatsApp chats of the user's business | No |
- Users can request deletion: **Account deletion** - Play requires a web page for this. Add one (e.g. https://tripsarthi.com/delete-account) or an e-mail process; **not built yet**.
- **Permissions to explain:** camera and photos (attach in chat), microphone (voice notes), notifications, biometrics (app lock). Location is not used.
- **Content rating:** answer the questionnaire honestly (business tool, no user-generated public content) - expect "Everyone".
- Declare the app uses no financial-services features: it only records payments, it does not move money.

## 6. Before you press Publish
- [ ] Privacy policy reviewed by a lawyer and live
- [ ] Account deletion page/process live
- [ ] Test account for Google reviewers created
- [ ] Internal test installed and checked on a real phone: sign in, push, voice note, offline task, Hindi, dark mode
- [ ] Data-safety form matches the table above
- [ ] Newest version number saved in the admin app > Mobile app, plus the Play Store link (the website and login page then show the "Get the app" buttons)
