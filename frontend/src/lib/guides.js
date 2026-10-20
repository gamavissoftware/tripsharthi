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
  {
    id: 'team', title: 'Team & settings', icon: '🔧', paths: ['/settings/team', '/settings/billing', '/settings/profile', '/settings'],
    intro: 'Invite staff, set what they can see, and manage your plan.',
    steps: ['Team: invite people as agent, admin or owner. Agents see only their own trips and never see costs or margin.', 'Billing: see your plan, usage against limits and your GST invoices.', 'Help & support: ask the TripSarthi team anything. Replies come by email too.'],
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
