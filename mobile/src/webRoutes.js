// Every screen of the TripSarthi web app, grouped like the web menu. `native` = the phone has its own screen for it (opened instead of the web page);
// everything else opens the web page INSIDE the app (signed in automatically, no browser needed). `mgr` = owners and admins only (the server enforces it
// too; this just hides what an agent could not use). Keep this list in step with NAV_GROUPS / SETTINGS_GROUPS in frontend/src/App.jsx.
// CommonJS so `node --test` can check it.

const G = (id, title, icon, items) => ({ id, title, icon, items })
const I = (path, label, icon, extra = {}) => ({ path, label, icon, ...extra })

const FEATURES = [
  G('travel', 'Travel', 'airplane', [
    I('/trips', 'Trips & enquiries', 'airplane', { native: 'Enquiries', tab: true }),
    I('/bookings', 'Bookings', 'briefcase', { native: 'Bookings', tab: true }),
    I('/suppliers', 'Suppliers & rates', 'business'),
    I('/payables', 'Supplier payables', 'cash', { mgr: true }),
    I('/departures', 'Group departures', 'flag', { native: 'Departures' }),
    I('/checklists', 'Documents & visas', 'document-text'),
    I('/business-report', 'Business report', 'stats-chart', { native: 'Report', mgr: true }),
    I('/ads/campaigns', 'Ad campaigns', 'megaphone', { mgr: true }),
    I('/ads/audiences', 'Ad audiences', 'people-circle', { mgr: true }),
    I('/ads', 'Ads to bookings', 'trending-up', { mgr: true }),
    I('/automations', 'Automations', 'flash', { mgr: true }),
  ]),
  G('crm', 'CRM', 'people', [
    I('/contacts', 'Contacts', 'people', { native: 'Contacts' }),
    I('/accounts', 'Accounts', 'business'),
    I('/deals', 'Deals', 'briefcase'),
    I('/tickets', 'Tickets', 'ticket'),
    I('/objects', 'Custom objects', 'cube'),
  ]),
  G('productivity', 'Productivity', 'checkbox', [
    I('/tasks', 'Tasks', 'checkbox', { native: 'Tasks' }),
    I('/meetings', 'Meetings', 'calendar'),
  ]),
  G('messaging', 'Messaging', 'chatbubbles', [
    I('/inbox', 'WhatsApp inbox', 'chatbubbles', { native: 'Chats', tab: true }),
    I('/templates', 'Message templates', 'document', { mgr: true }),
    I('/campaigns', 'WhatsApp campaigns', 'megaphone', { mgr: true }),
    I('/email', 'E-mail marketing', 'mail', { mgr: true }),
    I('/social', 'Social planner', 'share-social', { mgr: true }),
    I('/flows', 'Flows', 'git-network', { mgr: true }),
  ]),
  G('capture', 'Lead capture', 'download', [
    I('/imports', 'Import contacts', 'cloud-upload', { mgr: true }),
    I('/web-forms', 'Web forms', 'clipboard', { mgr: true }),
    I('/segments', 'Segments', 'funnel'),
    I('/tags', 'Tags', 'pricetag'),
    I('/tools/wa-link', 'WhatsApp link & QR', 'qr-code'),
  ]),
  G('catalog', 'Catalog', 'bag', [
    I('/products', 'Products', 'bag', { mgr: true }),
    I('/price-books', 'Price books', 'cash', { mgr: true }),
  ]),
  G('analytics', 'Analytics', 'bar-chart', [
    I('/analytics', 'Analytics', 'bar-chart'),
    I('/reports', 'Reports', 'trending-up'),
    I('/forecast', 'Forecast', 'telescope'),
    I('/leaderboard', 'Leaderboard', 'trophy'),
  ]),
  G('data', 'Data', 'trash', [
    I('/duplicates', 'Find duplicates', 'copy'),
    I('/recycle', 'Recycle bin', 'trash'),
  ]),
]

const SETTINGS = [
  G('channels', 'Channels & integrations', 'git-compare', [
    I('/settings/waba', 'WhatsApp', 'logo-whatsapp', { mgr: true }),
    I('/settings/integrations', 'Integrations', 'extension-puzzle', { mgr: true }),
    I('/settings/ads', 'Ad platforms', 'megaphone', { mgr: true }),
    I('/settings/ecommerce', 'Store sync', 'storefront', { mgr: true }),
    I('/settings/webhooks', 'Webhooks', 'link', { mgr: true }),
    I('/settings/payments', 'Payments', 'wallet', { mgr: true }),
    I('/settings/ai', 'AI assistant', 'sparkles', { mgr: true }),
  ]),
  G('salesops', 'Sales operations', 'settings', [
    I('/settings/dunning', 'Payment reminders', 'alarm', { mgr: true }),
    I('/settings/routing', 'Inbox routing', 'shuffle', { mgr: true }),
    I('/settings/scoring', 'Lead scoring', 'speedometer', { mgr: true }),
    I('/settings/assignment', 'Assignment rules', 'pin', { mgr: true }),
    I('/settings/hours', 'Working hours', 'time', { mgr: true }),
  ]),
  G('workspace', 'Workspace', 'home', [
    I('/settings/profile', 'My profile', 'person-circle'),
    I('/settings/team', 'Team', 'people', { mgr: true }),
    I('/settings/teams', 'Teams', 'people-circle', { mgr: true }),
    I('/settings/targets', 'Sales targets', 'flag', { mgr: true }),
    I('/settings/business', 'Business & invoicing', 'business', { mgr: true }),
    I('/settings/lead-sources', 'Travel portal leads', 'globe', { mgr: true }),
    I('/settings/gst-exports', 'GST & Tally exports', 'receipt', { mgr: true }),
    I('/settings/tcs', 'TCS (Form 27EQ)', 'receipt', { mgr: true }),
    I('/settings/fx', 'Exchange rates', 'swap-horizontal', { mgr: true }),
    I('/settings/einvoice', 'E-invoicing', 'document-lock', { mgr: true }),
    I('/settings/branding', 'Branding', 'color-palette', { mgr: true }),
    I('/settings/notifications', 'Notification rules', 'notifications'),
    I('/settings/mobile', 'Mobile alerts (web)', 'phone-portrait'),
  ]),
  G('account', 'Account', 'card', [
    I('/settings/billing', 'Billing & plan', 'card', { mgr: true }),
    I('/help', 'User guides', 'book'),
    I('/support', 'Help & support', 'help-buoy'),
    I('/settings/audit', 'Audit log', 'list', { mgr: true }),
  ]),
]

/** Hide what this person cannot use. */
function visible(groups, manager) {
  return groups.map(g => ({ ...g, items: g.items.filter(i => manager || !i.mgr) })).filter(g => g.items.length > 0)
}

/** Every item (all groups) matching a search text, for the "find a feature" box. */
function search(groups, q) {
  const t = String(q || '').trim().toLowerCase()
  if (!t) return []
  const out = []
  for (const g of groups) for (const i of g.items) if (i.label.toLowerCase().includes(t) || g.title.toLowerCase().includes(t)) out.push({ ...i, group: g.title })
  return out
}

module.exports = { FEATURES, SETTINGS, visible, search }
