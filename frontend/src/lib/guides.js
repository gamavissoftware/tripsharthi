// User guides - plain-language, task-first, one per module. Shown by components/GuideButton.jsx (the "Guide" button in the top bar,
// which picks the entry whose `paths` match the page you are on) and listed on the Help page (/help).
// Writing rules: say what the page is FOR, then the steps in the order a person does them, then the mistakes people actually make.
// `paths` are matched as prefixes against the route; the longest match wins. Add a new module = add an entry here, nothing else.

export const GUIDES = [
  {
    id: 'start', title: 'Getting started', icon: '🚀', paths: ['/dashboard'],
    intro: 'Your Home screen shows what needs you today: enquiries, quotes waiting for an answer, payments due and trips about to depart.',
    steps: [
      'Settings → Business & Invoicing: enter your company name, GSTIN and bank details once. Every quote, invoice and receipt uses it.',
      'Suppliers & Rates: add your hotels, drivers and activity partners and their prices (see the Suppliers guide).',
      'Settings → WhatsApp: connect your number so you can chat with customers and send quotes and payment reminders.',
      'Add an enquiry under Trips, build a quote in the itinerary builder, share the link, and confirm the booking when the customer accepts.',
    ],
    tips: ['The red "needs attention" chips on Home are your to-do list: start there every morning.', 'Agents only see their own trips and numbers. Owners and admins see everyone.'],
  },
  {
    id: 'trips', title: 'Trips & enquiries', icon: '🧳', paths: ['/trips'],
    intro: 'A trip is one customer enquiry, from the first message to the finished holiday. It moves across the board as you work on it.',
    steps: [
      'Click + New trip (or let WhatsApp, web forms and ads create it for you). Enter the customer, destination, dates and number of travellers.',
      'Open the trip and press Build itinerary. That opens the itinerary builder where you add hotels, transfers and activities and set your price.',
      'Press Share quote link and send it on WhatsApp. You will see when the customer opens it.',
      'When the customer accepts, press Confirm booking. The trip becomes a booking with a payment schedule.',
    ],
    tips: ['Always set a next step on a trip (a task or call). Trips without one are flagged on Home.', 'Change the status with the trip\'s status control, not by editing dates. That keeps your automations and ad reporting correct.'],
    mistakes: ['Creating a second trip for the same customer and place. Open the existing enquiry instead.'],
  },
  {
    id: 'itinerary', title: 'Itinerary builder (quotes)', icon: '🗺️', paths: ['/itineraries'],
    intro: 'Build the day-by-day plan, price every service from your rate cards, add your markup, and the customer gets a clean quote with no costs shown.',
    steps: [
      'Press + Add service inside a day. Search your rate cards by hotel, city or service name. Picking a rate fills the title, type, unit and price for you.',
      'Check Quantity. A per-person rate needs the number of travellers, a per-room rate the number of rooms, a per-vehicle rate the number of cars. The builder suggests it from the trip; change it if needed.',
      'For hotels also set Nights. The line total is price × quantity × nights and is shown under each line so you can check it.',
      'Click any line to edit its quantity, nights or price later. Tick Optional to show an add-on that is not included in the total.',
      'On the right set your Markup, Discount, GST and tick International for overseas trips (adds TCS).',
      'Check the margin shown at the bottom, then Share quote link. Customers never see your cost or margin.',
    ],
    tips: [
      'A line marked "needs price" has no cost yet. Fix it before sharing.',
      'A line marked "rate expired" is using a rate card past its valid-to date. Update the rate card.',
      'Foreign-currency rates are converted once at your exchange rate (plus buffer) and locked, so an accepted quote never changes. Use Re-price to refresh a draft.',
      'Once a quote is accepted it is locked. Use Duplicate as new version to change it.',
    ],
    mistakes: ['Leaving quantity at 1 for a per-person service. The total looks small and you lose margin. The builder now warns you when the unit is per-person and quantity is 1.'],
  },
  {
    id: 'suppliers', title: 'Suppliers & rate cards', icon: '🏨', paths: ['/suppliers'],
    intro: 'Your price list. Add each supplier once, then add their rates. The itinerary builder reads from here, so accurate rates mean accurate quotes.',
    steps: [
      'Suppliers tab: add a hotel, transport company, DMC or guide with phone and GSTIN.',
      'Rate cards tab: add a rate for a supplier. Pick the service type, the unit it is charged in (per night, per person, per vehicle, per room, per day, flat), the currency and the cost you pay.',
      'Set Valid from / Valid to for seasonal prices and pick the destination so you can find it fast when quoting.',
      'Changed prices? Click a rate to edit it, or use Copy to make next season\'s rate from this one.',
      'Many rates at once? Use Import rates (AI): paste a price list or upload Excel/CSV, review the preview, and save.',
    ],
    tips: ['Use the search box and filters (supplier, type, destination, expired) to find a rate quickly.', 'Rates past their valid-to date show as Expired and are warned about in the builder.', 'Changing a rate does not change quotes already made. They keep the price they were built with.'],
    mistakes: ['Entering the customer price as the cost. Rate cards hold what YOU pay; markup is added in the itinerary.'],
  },
  {
    id: 'bookings', title: 'Bookings & payments', icon: '📒', paths: ['/bookings'],
    intro: 'A booking is a confirmed trip. It holds the payment schedule, the supplier to-dos, travellers and documents.',
    steps: [
      'Open a booking and check the payment schedule (deposit + instalments). Send each customer payment link on WhatsApp with one click.',
      'When money arrives outside the link (bank transfer, cash), use Mark paid and add the reference.',
      'Add travellers with passport details (stored encrypted). The document checklist builds itself.',
      'Under Supplier services record what you owe each supplier and pay them when due.',
      'Issue the GST invoice or bill of supply and receipts from the booking. Issued documents cannot be edited; use a credit note to correct.',
    ],
    tips: ['Automatic payment reminders go out on WhatsApp 3 days before, on the due date and when overdue (Settings → Payment Reminders).', 'The customer portal link lets travellers see their trip, pay and upload documents.'],
  },
  {
    id: 'payables', title: 'Supplier payables', icon: '💸', paths: ['/payables'],
    intro: 'What you owe suppliers, sorted by pay-by date, so nothing is paid late and nothing is paid twice.',
    steps: ['Open the page and look at Overdue and Due this week first.', 'Pay the supplier by bank/UPI, then record the payment against the service with the reference.', 'A fully paid service turns settled and your real margin is updated.'],
    tips: ['Foreign-currency suppliers show what you owe in their currency and an INR estimate at today\'s rate.'],
  },
  {
    id: 'departures', title: 'Group departures', icon: '🚌', paths: ['/departures'],
    intro: 'Fixed-date group tours with a limited number of seats. The system makes sure you never oversell.',
    steps: ['Create a departure with dates, total seats, price per person and the minimum to operate.', 'From an enquiry choose Group departure to hold seats (held for 24 hours by default).', 'Confirm the booking to turn the hold into confirmed seats.', 'Download the manifest and rooming list before travel.'],
    tips: ['"At risk" means fewer than the minimum passengers within 30 days: decide go or no-go.'],
  },
  {
    id: 'contacts', title: 'Contacts', icon: '👥', paths: ['/contacts'],
    intro: 'Everyone who has contacted you or you have contacted, with their full history.',
    steps: ['Add contacts one by one, import a CSV, or let ads, forms and WhatsApp add them.', 'Use tags and segments to group people (for example "Dubai 2026" or "Honeymoon").', 'Open a contact to see chats, trips, bookings and notes in one place.'],
    tips: ['Only message people who have opted in to WhatsApp. Opted-out contacts are blocked automatically.', 'Duplicates are merged automatically by phone number; check Duplicates for the rest.'],
  },
  {
    id: 'inbox', title: 'WhatsApp inbox', icon: '💬', paths: ['/inbox'],
    intro: 'All your customer WhatsApp chats in one place, shared by your team.',
    steps: ['Pick a chat on the left and type your reply.', 'Assign the chat to a teammate with the assign control.', 'Use quick replies for answers you send often.'],
    tips: ['WhatsApp only allows free-text replies within 24 hours of the customer\'s last message. After that you must send an approved template; the inbox offers them automatically.'],
  },
  {
    id: 'campaigns', title: 'WhatsApp campaigns & templates', icon: '📣', paths: ['/campaigns', '/templates'],
    intro: 'Send an approved message to many opted-in customers at once, for example a new package or a festival offer.',
    steps: ['Templates: write the message and submit it to Meta. Approval usually takes minutes to a day.', 'Campaigns: choose the approved template, choose the audience (a segment or tag) and send now or schedule.', 'Watch delivered / read / replied on the campaign report.'],
    tips: ['Start small and watch your number quality rating. Too many blocks or complaints lower it and Meta limits you.'],
  },
  {
    id: 'automations', title: 'Automations & flows', icon: '⚙️', paths: ['/automations', '/flows'],
    intro: 'Automatic follow-ups: quote not opened, payment due, departure in 3 days, and more.',
    steps: ['Automations page: install a ready-made starter. It is created as a draft and sends nothing yet.', 'Open it, check the message, and make sure its template is approved by Meta.', 'Turn it on.'],
    tips: ['Each automation checks the 24-hour window and uses a template when the window is closed.', 'Do not turn on both the payment-received automation and the payment reminder receipt: customers would get two messages.'],
  },
  {
    id: 'deals', title: 'Deals pipeline', icon: '📈', paths: ['/deals'],
    intro: 'Your sales board. Every trip is also a deal, so forecasts include your travel enquiries.',
    steps: ['Drag deals between stages as they progress.', 'Watch the stuck (rotting) markers and follow up.', 'Won and lost deals feed your reports and forecast.'],
  },
  {
    id: 'invoicing', title: 'Invoices, GST & TCS', icon: '🧾', paths: ['/settings/business', '/settings/gst-exports', '/settings/tcs', '/settings/einvoice'],
    intro: 'Documents and filing support for Indian GST.',
    steps: ['Business & Invoicing: fill your legal name, GSTIN, state, bank and invoice prefix.', 'Issue invoices from the booking page. Numbers are gap-free and cannot be changed after issue.', 'GST & Tally exports: each month download GSTR-1, the 3B summary and the Tally file for your CA.', 'TCS: record the challans you deposit for overseas packages.'],
    tips: ['No GSTIN means a Bill of Supply is issued instead of a tax invoice.', 'Have your CA confirm the SAC code, the GST rate wording and the TCS base before first use.'],
  },
  {
    id: 'fx', title: 'Exchange rates', icon: '💱', paths: ['/settings/fx'],
    intro: 'Needed only when a supplier charges in a foreign currency (USD, AED, THB…). All your accounts stay in rupees.',
    steps: ['Add the currencies you use. Rates refresh daily or you can type your own.', 'Set a buffer % so you are not caught by rate movement.', 'In the itinerary builder pick the currency on a service line. It converts once and locks.'],
  },
  {
    id: 'ads', title: 'Ads & lead tracking', icon: '🎯', paths: ['/ads', '/settings/ads'],
    intro: 'Create and monitor Meta and Google ads, and see which ads actually produced bookings.',
    steps: ['Settings → Ad Platforms: connect Meta and Google.', 'Ad campaigns: set a daily spend cap first (without one nothing can be launched), then build a campaign. It is created paused.', 'Review, then switch on. Ads → Bookings shows leads, bookings and return per campaign.'],
    tips: ['Rules can only pause or notify, never raise your spend.'],
  },
  {
    id: 'reports', title: 'Reports & targets', icon: '📊', paths: ['/business-report', '/reports', '/forecast', '/settings/targets', '/analytics'],
    intro: 'How the business is doing: revenue, margin, best destinations, best lead sources, and each person against their monthly target.',
    steps: ['Choose a date range at the top.', 'Compare lead sources and agents to see where to spend time and money.', 'Set monthly targets under Sales targets. They carry forward until you change them.'],
    tips: ['Margin is only accurate when supplier costs are entered on bookings.'],
  },
  // ── Added 2026-10-20: the remaining pages ──
  {
    id: 'tasks', title: 'Tasks & meetings', icon: '✅', paths: ['/tasks', '/meetings'],
    intro: 'Your follow-ups. A task is something to do (call, send documents); a meeting is a booked time with a customer.',
    steps: ['Click + New and set what, who it is for and when it is due.', 'Link it to a contact, trip or deal so it shows on that record too.', 'Tick a task off when done. Overdue and due-today tasks show on your Home screen.', 'Meetings: pick a time; the list shows upcoming ones by default, change the dates to look back.'],
    tips: ['Give every open enquiry a next-step task. Trips without one are flagged on Home.'],
  },
  {
    id: 'accounts', title: 'Accounts (companies)', icon: '🏢', paths: ['/accounts'],
    intro: 'Companies or organisations your contacts belong to, for example a corporate client or a school group.',
    steps: ['Click + New account and enter the company name and details.', 'Link contacts to the account from the contact page.', 'Open an account to see everyone and every deal connected to it.'],
    tips: ['Skip this for ordinary family travellers. It is mainly for corporate, MICE and group business.'],
  },
  {
    id: 'segments', title: 'Segments & tags', icon: '🏷️', paths: ['/segments', '/tags'],
    intro: 'Ways to group contacts so you can message the right people. A tag is a label you put on a contact; a segment is a saved filter.',
    steps: ['Tags: create a label such as "Honeymoon" or "Dubai 2026", then tag contacts from their page or in bulk.', 'Segments: set filter rules (tag, source, status…) and save them. The list updates itself as contacts change.', 'Pick a segment or tag as the audience when you create a WhatsApp or email campaign.'],
    tips: ['Use short, consistent tag names. "Dubai" and "dubai trip" become two different tags.'],
  },
  {
    id: 'imports', title: 'Import contacts', icon: '📥', paths: ['/imports'],
    intro: 'Bring your existing customer list in from a CSV or Excel file.',
    steps: ['Upload the file.', 'Match each column to a field (name, WhatsApp number, email…).', 'Review the preview and confirm. People with the same WhatsApp number are merged, not duplicated.'],
    tips: ['Include the country code in numbers, for example 91 for India.', 'Only mark people as opted in if they actually agreed to receive WhatsApp messages from you.'],
    mistakes: ['Importing a bought or scraped list and messaging it. This can get your WhatsApp number blocked.'],
  },
  {
    id: 'duplicates', title: 'Duplicates & recycle bin', icon: '🧹', paths: ['/duplicates', '/recycle'],
    intro: 'Keep your data clean: merge records that are the same person, and bring back anything deleted by mistake.',
    steps: ['Duplicates: the page lists records sharing a phone, email or domain. Review the pair and merge them into one.', 'Recycle bin: find the deleted record and press Restore, or remove it permanently.'],
    tips: ['Merging cannot be undone, so check which record has the better details first.'],
  },
  {
    id: 'objects', title: 'Custom objects', icon: '🧩', paths: ['/objects'],
    intro: 'Your own record types for anything the standard screens do not cover, for example Hotels you work with or Visa applications.',
    steps: ['Create an object and add its fields (text, number, date, dropdown…).', 'Open the object to add and edit records.', 'Link records to contacts where it helps.'],
    tips: ['Check that a standard screen cannot already do the job before adding a custom one.'],
  },
  {
    id: 'products', title: 'Products & price books', icon: '📦', paths: ['/products', '/price-books'],
    intro: 'A catalogue of things you sell repeatedly (a fixed package, a visa service) and named price lists for different customers.',
    steps: ['Products: add each item with its price.', 'Price books: create a named list (for example "Corporate") with its own prices.', 'On a deal, pick a product, and optionally a price book to override the standard price.'],
    tips: ['For custom holidays use the itinerary builder instead. Products suit fixed-price services.'],
  },
  {
    id: 'tickets', title: 'Customer support tickets', icon: '🎫', paths: ['/tickets'],
    intro: 'Track a traveller\'s problem or request until it is solved, with a timer so nothing is forgotten. Often opened from a WhatsApp chat.',
    steps: ['Open a ticket from a chat or click + New ticket.', 'Set the priority and assign it to someone.', 'Reply and add internal notes, then change the status to resolved.'],
    tips: ['Timers only count down during your business hours (Settings → Business hours).', 'This is for your travellers. To ask the TripSarthi team for help use Help & support.'],
  },
  {
    id: 'email', title: 'Email marketing', icon: '✉️', paths: ['/email'],
    intro: 'Send newsletters and offers by email to customers who agreed to receive them.',
    steps: ['Templates: create a reusable design, or start from a campaign.', 'New campaign: write the subject and body, then choose the audience.', 'Send yourself a test first and check it on your phone.', 'Send now or schedule. The campaign report shows delivered, opened and clicked.'],
    tips: ['Every email carries an unsubscribe link automatically. Anyone who unsubscribes is never emailed again.', 'Keep the subject short and specific. A clear destination and date beats "Big offer!".'],
  },
  {
    id: 'forms', title: 'Web forms & lead sources', icon: '📝', paths: ['/web-forms', '/settings/lead-sources', '/tools/wa-link'],
    intro: 'Ways for enquiries to reach you automatically: a form on your website, leads from travel portals, and a WhatsApp link or QR code.',
    steps: ['Web forms: design the form, copy the embed code and paste it into your website. Each submission becomes a contact and a trip enquiry.', 'Lead sources: create one per portal, then give the portal the web address (or the email address) shown. Test with the sample request.', 'WhatsApp link & QR: create a link with a pre-written message, and print the QR on brochures or posters.'],
    tips: ['Keep the secret link of a lead source private. Rotate it if it leaks.', 'The same person enquiring twice does not create a second enquiry.'],
  },
  {
    id: 'social', title: 'Social posts', icon: '📱', paths: ['/social'],
    intro: 'Plan and schedule posts to your Facebook Page and Instagram business account.',
    steps: ['Connect your page under Settings → Integrations.', 'Write the post, add a picture, and pick a time.', 'Check the calendar for what is scheduled and what has failed.'],
    tips: ['If a page shows "reconnect needed", reconnect it before scheduling more posts.'],
  },
  {
    id: 'checklists', title: 'Documents & visas', icon: '🛂', paths: ['/checklists'],
    intro: 'Which upcoming trips still wait for passports, photos, visas or insurance, soonest departure first.',
    steps: ['Look at the departures at the top: they are the most urgent.', 'Open a booking and tick off each document as the customer sends it, or accept what they uploaded in the customer portal.', 'Remind the customer on WhatsApp for anything missing.'],
    tips: ['The checklist is generic guidance. Check each country\'s visa rules separately.'],
  },
  {
    id: 'leaderboard', title: 'Leaderboard', icon: '🏆', paths: ['/leaderboard'],
    intro: 'A friendly ranking of the team by won revenue, conversions, pipeline and follow-ups.',
    steps: ['Pick the period.', 'See how each person ranks and where they are strong or weak.', 'Use it in team meetings. Set targets under Sales targets.'],
  },
  {
    id: 'support', title: 'Help & support', icon: '🛟', paths: ['/support'],
    intro: 'Ask the TripSarthi team for help. Replies appear here and are also emailed to you.',
    steps: ['Click New request, write a short subject and describe what you were doing and what went wrong.', 'Check back here or your email for the reply.', 'Reply in the same request if you need more help.'],
    tips: ['Include the booking or trip number and what you expected to see. It saves a round of questions.', 'Try the User guides first. Many answers are there.'],
  },
  {
    id: 'whatsapp-setup', title: 'WhatsApp connection', icon: '🔌', paths: ['/settings/waba'],
    intro: 'Connect your WhatsApp Business number so TripSarthi can send and receive messages.',
    steps: ['Press Connect and log in with Facebook when asked.', 'Choose your business account and phone number.', 'Once it shows Active, send yourself a test from the inbox.', 'Watch the quality rating of each number here.'],
    tips: ['A yellow or red quality rating pauses marketing campaigns to protect your number. Replies and payment reminders still work.', 'Number change or new SIM? Reconnect from this page.'],
  },
  {
    id: 'dunning', title: 'Payment reminders', icon: '⏰', paths: ['/settings/dunning'],
    intro: 'Automatic WhatsApp reminders with a payment link before and after an instalment is due.',
    steps: ['Choose the reminder steps, for example 3 days before, on the due date and 2 days after.', 'Create the ready-made templates and submit them to Meta for approval.', 'Set the sending hours so customers are not messaged at night.'],
    tips: ['Reminders stop for customers who opted out and after 45 days overdue.', 'If a step has no approved template and the 24-hour window is closed, a task is created for you instead.'],
  },
  {
    id: 'payments-setup', title: 'Payments (Razorpay)', icon: '💳', paths: ['/settings/payments'],
    intro: 'Connect your own Razorpay account so customer payment links pay straight into your bank account.',
    steps: ['Enter your Razorpay key and secret from your Razorpay dashboard.', 'Copy the webhook address shown here into Razorpay so paid instalments are marked automatically.', 'Create a test payment link on a booking to check it end to end.'],
    tips: ['Never share the secret with anyone. It is stored encrypted and is not shown again.'],
  },
  {
    id: 'assignment', title: 'Assignment & routing rules', icon: '🔀', paths: ['/settings/assignment', '/settings/routing', '/settings/teams'],
    intro: 'Decide who gets each new enquiry or chat automatically, instead of someone assigning by hand.',
    steps: ['Assignment rules: pick the condition (for example source = Instagram or destination = Bali) and the person or team to receive it.', 'Inbox routing: send new WhatsApp chats to the right person.', 'Teams: group agents; lists then offer a "My team" view.', 'Put the most specific rule first and keep a general fallback last.'],
    tips: ['Pause a rule while someone is on leave instead of deleting it.'],
  },
  {
    id: 'scoring', title: 'Lead scoring', icon: '🔥', paths: ['/settings/scoring'],
    intro: 'Marks leads Hot, Warm or Cold so the team calls the most promising people first.',
    steps: ['Adjust how many points each signal is worth (replies, quote viewed, budget…).', 'Set where Hot and Warm start.', 'Look for the labels on contact and deal lists.'],
    tips: ['Change one thing at a time and watch for a week before changing more.'],
  },
  {
    id: 'hours', title: 'Business hours', icon: '🕘', paths: ['/settings/hours'],
    intro: 'The hours your team works. Support timers only count down inside them.',
    steps: ['Set each day\'s opening and closing time, and mark days off.', 'Save. Tickets use these hours for their response timers.'],
  },
  {
    id: 'integrations', title: 'Integrations & webhooks', icon: '🔗', paths: ['/settings/integrations', '/settings/webhooks', '/settings/ecommerce'],
    intro: 'Connect other tools: Facebook lead ads and pages, store platforms, and your own systems.',
    steps: ['Integrations: connect Facebook to import lead-ad leads and to post to your pages.', 'Store integrations: connect your online store if you sell products there.', 'Webhooks: give a web address that should be called when something happens (new contact, booking…).'],
    tips: ['Webhooks are for developers. Each call is signed with a secret so your system can check it is genuine.', 'If an integration shows failing, reconnect it first.'],
  },
  {
    id: 'ai', title: 'AI assistant', icon: '🤖', paths: ['/settings/ai'],
    intro: 'Settings for the AI that drafts itineraries, replies and ad copy.',
    steps: ['Check that the AI is switched on for your workspace.', 'Use it from the trip page (Draft with AI) or the inbox (suggest a reply).', 'Always read what it wrote before sending.'],
    tips: ['The AI never invents prices. Prices only come from your rate cards or from what you type.'],
  },
  {
    id: 'branding', title: 'Branding & notifications', icon: '🎨', paths: ['/settings/branding', '/settings/notifications', '/settings/mobile', '/settings/profile'],
    intro: 'Your logo and colours on quotes and documents, how you are notified, and your own profile.',
    steps: ['Branding: upload your logo and choose the colour used on quotes and invoices.', 'Notifications: choose what you are told about and how.', 'Mobile alerts: pick which push alerts the phone app sends and set quiet hours.', 'Profile: change your name, password and your marketing preferences.'],
    tips: ['Change colours through this page. Documents already issued keep the look they were issued with.'],
  },
  {
    id: 'audit', title: 'Audit log', icon: '🕵️', paths: ['/settings/audit'],
    intro: 'A record of who changed what and when, useful when something looks wrong.',
    steps: ['Search or filter by person, type of change or date.', 'Open an entry to see the details.'],
    tips: ['When the TripSarthi team helps you through a support session, their changes appear here labelled "TripSarthi support".'],
  },
  {
    id: 'billing', title: 'Billing & plan', icon: '🧾', paths: ['/settings/billing'],
    intro: 'Your TripSarthi plan, what you have used, and your GST invoices.',
    steps: ['See your plan, renewal date and usage against the limits.', 'To upgrade or renew choose a plan, add a coupon code if you have one, and pay online.', 'Download your GST invoices from the invoices card.'],
    tips: ['Prices on this page are before GST. GST is added at payment.', 'A renewal paid early starts when the current period ends, so no days are lost.'],
  },
  {
    id: 'team', title: 'Team & settings', icon: '🔧', paths: ['/settings/team', '/settings'],
    intro: 'Invite staff and set what they can see.',
    steps: ['Team: invite people as agent, admin or owner. Agents see only their own trips and never see costs or margin.', 'Teams: group agents so lists can show a "My team" view.', 'Changes to roles apply the next time the person opens the app.'],
  },
]

/** Best guide for a route: longest matching path prefix. */
export function guideFor(pathname) {
  let best = null; let len = -1
  for (const g of GUIDES) for (const p of g.paths) {
    if ((pathname === p || pathname.startsWith(p + '/') || pathname.startsWith(p)) && p.length > len) { best = g; len = p.length }
  }
  return best
}
