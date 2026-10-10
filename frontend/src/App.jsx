import { useState, useEffect } from 'react'
import { HashRouter, Routes, Route, NavLink, Navigate, useLocation } from 'react-router-dom'
import {
  LayoutDashboard, Users, UsersRound, Building2, Briefcase, Ticket, Package,
  ListChecks, CheckSquare, Calendar, MessageSquare, LayoutTemplate, Megaphone,
  Zap, Share2, Download, Upload, ClipboardList, Crosshair, Tag, QrCode,
  ShoppingBag, IndianRupee, BarChart3, TrendingUp, Trophy, Copy, Trash2,
  Plug, Smartphone, Store, Webhook, Wallet, Bot, Settings, Shuffle, Target,
  Pin, Clock, Home, CircleUser, Palette, Bell, CreditCard, ScrollText,
  Mail, Plane, Handshake, ShieldCheck, MessagesSquare, PanelLeftClose, PanelLeftOpen, LogOut, Menu } from 'lucide-react'
import { api, isLoggedIn, clearToken } from './api/client'
import { ToastProvider } from './components/Toast'
import GlobalSearch from './components/GlobalSearch'
import NotificationBell from './components/NotificationBell'
import { BrandingProvider, useBranding } from './branding/BrandingContext'

import LoginPage         from './pages/LoginPage'
import RegisterPage      from './pages/RegisterPage'
import ForgotPasswordPage from './pages/ForgotPasswordPage'
import ResetPasswordPage  from './pages/ResetPasswordPage'
import ContactsPage      from './pages/ContactsPage'
import ContactDetailPage from './pages/ContactDetailPage'
import AccountsPage      from './pages/AccountsPage'
import TasksPage         from './pages/TasksPage'
import MeetingsPage      from './pages/MeetingsPage'
import DealsPage         from './pages/DealsPage'
import DealCreatePage    from './pages/DealCreatePage'
import DealDetailPage    from './pages/DealDetailPage'
import TicketsPage       from './pages/TicketsPage'
import TicketDetailPage  from './pages/TicketDetailPage'
import CrmDashboardPage  from './pages/CrmDashboardPage'
import CustomObjectsPage from './pages/CustomObjectsPage'
import RecordsPage       from './pages/RecordsPage'
import RecordDetailPage  from './pages/RecordDetailPage'
import ImportPage        from './pages/ImportPage'
import TagsPage          from './pages/TagsPage'
import FlowsPage         from './pages/FlowsPage'
import FlowBuilderPage   from './pages/FlowBuilderPage'
import InboxPage         from './pages/InboxPage'
import TemplatesPage     from './pages/TemplatesPage'
import CampaignsPage     from './pages/CampaignsPage'
import AnalyticsPage     from './pages/AnalyticsPage'
import MetaLeadsMonthPage from './pages/MetaLeadsMonthPage'
import WabaPage          from './pages/WabaPage'
import IntegrationsPage  from './pages/IntegrationsPage'
import BillingPage       from './pages/BillingPage'
import WebFormsPage      from './pages/WebFormsPage'
import BrandingPage       from './pages/BrandingPage'
import SegmentsPage      from './pages/SegmentsPage'
import OnboardingBanner  from './components/OnboardingBanner'
import ProfilePage       from './pages/ProfilePage'
import TeamPage          from './pages/TeamPage'
import RoutingRulesPage  from './pages/RoutingRulesPage'
import PaymentsPage      from './pages/PaymentsPage'
import ProductsPage      from './pages/ProductsPage'
import EcommercePage     from './pages/EcommercePage'
import WebhooksPage      from './pages/WebhooksPage'
import WaLinkPage        from './pages/WaLinkPage'
import AiPage            from './pages/AiPage'
import NotificationsPage from './pages/NotificationsPage'
import SocialPage        from './pages/SocialPage'
import EmailMarketingPage         from './pages/EmailMarketingPage'
import EmailCampaignEditorPage    from './pages/EmailCampaignEditorPage'
import EmailCampaignReportPage    from './pages/EmailCampaignReportPage'
import EmailTemplateEditorPage    from './pages/EmailTemplateEditorPage'
import RecycleBinPage    from './pages/RecycleBinPage'
import DuplicatesPage    from './pages/DuplicatesPage'
import ScoringPage       from './pages/ScoringPage'
import AssignmentRulesPage from './pages/AssignmentRulesPage'
import BusinessHoursPage  from './pages/BusinessHoursPage'
import ReportsPage        from './pages/ReportsPage'
import ForecastPage       from './pages/ForecastPage'
import LeaderboardPage    from './pages/LeaderboardPage'
import PriceBooksPage     from './pages/PriceBooksPage'
import AuditLogPage       from './pages/AuditLogPage'
import TeamsPage          from './pages/TeamsPage'

import TripsPage          from './pages/TripsPage'
import TripDetailPage     from './pages/TripDetailPage'
import ItineraryBuilderPage from './pages/ItineraryBuilderPage'
import BookingsPage       from './pages/BookingsPage'
import BookingDetailPage  from './pages/BookingDetailPage'
import SuppliersPage      from './pages/SuppliersPage'
import AdsReportPage      from './pages/AdsReportPage'
import PublicQuotePage    from './pages/PublicQuotePage'
import PublicPortalPage   from './pages/PublicPortalPage'
import DunningSettingsPage from './pages/DunningSettingsPage'
import AdPlatformsPage from './pages/AdPlatformsPage'
import TravelAutomationsPage from './pages/TravelAutomationsPage'
import AdCampaignsPage from './pages/AdCampaignsPage'
import AdAudiencesPage from './pages/AdAudiencesPage'
import BusinessProfilePage from './pages/BusinessProfilePage'
import GstExportsPage from './pages/GstExportsPage'
import TcsPage from './pages/TcsPage'
import FxRatesPage from './pages/FxRatesPage'
import EinvoiceSettingsPage from './pages/EinvoiceSettingsPage'
import PayablesPage from './pages/PayablesPage'
import ChecklistsPage from './pages/ChecklistsPage'
import BusinessReportPage from './pages/BusinessReportPage'
import DeparturesPage from './pages/DeparturesPage'
import LeadSourcesPage from './pages/LeadSourcesPage'
import MobileNotificationsPage from './pages/MobileNotificationsPage'

import AdminOverviewPage from './pages/admin/AdminOverviewPage'
import AdminCustomersPage from './pages/admin/AdminCustomersPage'
import AdminSubscriptionsPage from './pages/admin/AdminSubscriptionsPage'
import AdminInboxPage from './pages/admin/AdminInboxPage'

import './App.css'

// ── Nav structure ─────────────────────────────────────────────────────────
// A standalone top link plus collapsible groups, so the ~40 destinations stay
// scannable instead of one long flat list. Each group auto-expands when it holds
// the active route. Keep group `items` ordered by how often they're used.
const NAV_HOME = { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard }

const NAV_GROUPS = [
  { label: 'Travel', icon: Plane, items: [
    { to: '/trips',     label: 'Trips & Enquiries', icon: Plane },
    { to: '/bookings',  label: 'Bookings',          icon: Briefcase },
    { to: '/suppliers', label: 'Suppliers & Rates', icon: Handshake },
    { to: '/payables',  label: 'Supplier Payables', icon: Handshake },
    { to: '/departures', label: 'Group Departures', icon: Briefcase },
    { to: '/checklists', label: 'Documents & Visas', icon: Briefcase },
    { to: '/business-report', label: 'Business Report', icon: Briefcase },
    { to: '/ads/campaigns', label: 'Ad Campaigns',   icon: Megaphone },
    { to: '/ads/audiences', label: 'Ad Audiences',   icon: Megaphone },
    { to: '/ads',       label: 'Ads → Bookings',    icon: Megaphone },
    { to: '/automations', label: 'Automations',     icon: Zap },
  ]},
  { label: 'CRM', icon: UsersRound, items: [
    { to: '/contacts', label: 'Contacts', icon: Users },
    { to: '/accounts', label: 'Accounts', icon: Building2 },
    { to: '/deals',    label: 'Deals',    icon: Briefcase },
    { to: '/tickets',  label: 'Tickets',  icon: Ticket },
    { to: '/objects',  label: 'Objects',  icon: Package },
  ]},
  { label: 'Productivity', icon: ListChecks, items: [
    { to: '/tasks',    label: 'Tasks',    icon: CheckSquare },
    { to: '/meetings', label: 'Meetings', icon: Calendar },
  ]},
  { label: 'Messaging', icon: MessageSquare, items: [
    { to: '/inbox',     label: 'Inbox',     icon: MessageSquare },
    { to: '/templates', label: 'Templates', icon: LayoutTemplate },
    { to: '/campaigns', label: 'Campaigns', icon: Megaphone },
    { to: '/email',     label: 'Email Marketing', icon: Mail },
    { to: '/social',    label: 'Social Planner', icon: Share2 },
    { to: '/flows',     label: 'Flows',     icon: Zap },
  ]},
  { label: 'Lead Capture', icon: Download, items: [
    { to: '/imports',       label: 'Import',     icon: Upload },
    { to: '/web-forms',     label: 'Web Forms',  icon: ClipboardList },
    { to: '/segments',      label: 'Segments',   icon: Crosshair },
    { to: '/tags',          label: 'Tags',       icon: Tag },
    { to: '/tools/wa-link', label: 'Link & QR',  icon: QrCode },
  ]},
  { label: 'Catalog', icon: ShoppingBag, items: [
    { to: '/products',    label: 'Products',    icon: ShoppingBag },
    { to: '/price-books', label: 'Price Books', icon: IndianRupee },
  ]},
  { label: 'Analytics', icon: BarChart3, items: [
    { to: '/analytics',   label: 'Analytics',   icon: BarChart3 },
    { to: '/reports',     label: 'Reports',     icon: TrendingUp },
    { to: '/forecast',    label: 'Forecast',    icon: Target },
    { to: '/leaderboard', label: 'Leaderboard', icon: Trophy },
  ]},
  { label: 'Data', icon: Trash2, items: [
    { to: '/duplicates', label: 'Find Duplicates', icon: Copy },
    { to: '/recycle',    label: 'Recycle Bin',     icon: Trash2 },
  ]},
]

const SETTINGS_GROUPS = [
  { label: 'Channels', icon: Plug, items: [
    { to: '/settings/waba',         label: 'WhatsApp',     icon: Smartphone },
    { to: '/settings/integrations', label: 'Integrations', icon: Plug },
    { to: '/settings/ads',          label: 'Ad Platforms', icon: Megaphone },
    { to: '/settings/ecommerce',    label: 'Store Sync',   icon: Store },
    { to: '/settings/webhooks',     label: 'Webhooks',     icon: Webhook },
    { to: '/settings/payments',     label: 'Payments',     icon: Wallet },
    { to: '/settings/ai',           label: 'AI Assistant', icon: Bot },
  ]},
  { label: 'Sales Ops', icon: Settings, items: [
    { to: '/settings/dunning',     label: 'Payment Reminders', icon: Wallet },
    { to: '/settings/routing',     label: 'Inbox Routing',    icon: Shuffle },
    { to: '/settings/scoring',     label: 'Lead Scoring',     icon: Target },
    { to: '/settings/assignment',  label: 'Assignment Rules', icon: Pin },
    { to: '/settings/hours',       label: 'Working Hours',    icon: Clock },
  ]},
  { label: 'Workspace', icon: Home, items: [
    { to: '/settings/profile',       label: 'My Profile',    icon: CircleUser },
    { to: '/settings/team',          label: 'Team',          icon: UsersRound },
    { to: '/settings/teams',         label: 'Teams',         icon: Users },
    { to: '/settings/business',      label: 'Business & Invoicing', icon: Building2 },
    { to: '/settings/lead-sources',  label: 'Travel portal leads', icon: Building2 },
    { to: '/settings/gst-exports',   label: 'GST & Tally exports', icon: Building2 },
    { to: '/settings/tcs',          label: 'TCS (Form 27EQ)', icon: Building2 },
    { to: '/settings/fx',           label: 'Exchange rates', icon: Building2 },
    { to: '/settings/einvoice',     label: 'E-invoicing', icon: Building2 },
    { to: '/settings/branding',      label: 'Branding',      icon: Palette },
    { to: '/settings/notifications', label: 'Notifications', icon: Bell },
    { to: '/settings/mobile',        label: 'Mobile alerts', icon: Smartphone },
  ]},
  { label: 'Account', icon: CreditCard, items: [
    { to: '/settings/billing', label: 'Billing',   icon: CreditCard },
    { to: '/settings/audit',   label: 'Audit Log', icon: ScrollText },
  ]},
]

// Platform admin (the TripSarthi team only): shown when /auth/me says is_platform_admin. The API enforces it again on every call.
const NAV_ADMIN = { label: 'Platform admin', icon: ShieldCheck, items: [
  { to: '/admin',               label: 'Overview',      icon: LayoutDashboard },
  { to: '/admin/customers',     label: 'Customers',     icon: Building2 },
  { to: '/admin/subscriptions', label: 'Subscriptions', icon: CreditCard },
  { to: '/admin/inbox',         label: 'Website inbox', icon: MessagesSquare },
]}

function AdminOnly({ user, children }) {
  if (!user) return <div className="page"><p style={{ color: 'var(--text-3)' }}>Loading…</p></div>
  return user.is_platform_admin ? children : <Navigate to="/" replace />
}

// ── Collapsible nav group ────────────────────────────────────────────────────
// NavLink `end` for '/ads': otherwise it also lights up for /ads/campaigns.
function NavGroup({ group, onNavigate, collapsed = false, up = false }) {
  const { pathname } = useLocation()
  // A child is active when the path equals it or is a sub-route (e.g. /contacts/42).
  const hasActive = group.items.some(i => pathname === i.to || pathname.startsWith(i.to + '/'))
  const [open, setOpen] = useState(hasActive)
  // Keep the active group expanded as the user navigates within it.
  useEffect(() => { if (hasActive) setOpen(true) }, [hasActive])

  return (
    <div className={'nav-group' + (open ? ' open' : '') + (up ? ' flyout-up' : '')}>
      <button
        type="button"
        className={'nav-group-header' + (hasActive ? ' has-active' : '')}
        onClick={() => setOpen(o => !o)}
        aria-expanded={collapsed ? undefined : open}
        title={collapsed ? group.label : undefined}
      >
        <span className="sidebar-link-icon"><group.icon size={17} strokeWidth={1.8} /></span>
        <span className="nav-group-label">{group.label}</span>
        <span className="nav-group-chevron">▸</span>
      </button>
      {(open || collapsed) && (
        <div className="nav-group-items">
          {collapsed && <div className="nav-flyout-title">{group.label}</div>}
          {group.items.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/ads'}
              onClick={onNavigate}
              className={({ isActive }) => 'sidebar-link sidebar-sublink' + (isActive ? ' active' : '')}
            >
              <span className="sidebar-link-icon"><Icon size={17} strokeWidth={1.8} /></span>
              {label}
            </NavLink>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Sidebar ──────────────────────────────────────────────────────────────────
function Sidebar({ user, onLogout, open = false, onNavigate, collapsed = false, onToggleCollapse }) {
  const { branding } = useBranding()
  const appName = branding?.app_name?.trim() || 'TripSarthi'
  const initials = (user?.name ?? 'U')
    .split(' ')
    .map(w => w[0])
    .join('')
    .substring(0, 2)
    .toUpperCase()

  return (
    <aside className={'sidebar' + (open ? ' open' : '') + (collapsed ? ' collapsed' : '')}>
      {/* Logo */}
      <div className="sidebar-logo">
        {branding?.logo_url
          ? <img src={branding.logo_url} alt={appName} className="sidebar-logo-icon" style={{ objectFit:'cover', background:'#fff' }} onError={e => { e.target.outerHTML = '<div class="sidebar-logo-icon">✈</div>' }} />
          : <span className="sidebar-logo-icon" style={{ background: '#fff', padding: 3 }}><img src="/brand/tripsarthi-mark.png" alt="" width="30" height="30" style={{ display: 'block' }} /></span>}
        <span className="sidebar-logo-text">{appName === 'TripSarthi' ? <>Trip<span style={{ color: '#2fd0c2' }}>Sarthi</span></> : appName}</span>
      </div>

      {/* Main nav */}
      <div className="sidebar-nav">
        <NavLink
          to={NAV_HOME.to}
          onClick={onNavigate}
          end
          className={({ isActive }) => 'sidebar-link' + (isActive ? ' active' : '')}
        >
          <span className="sidebar-link-icon"><NAV_HOME.icon size={17} strokeWidth={1.8} /></span>
          <span className="sidebar-link-text">{NAV_HOME.label}</span>
        </NavLink>

        {user?.is_platform_admin && (<>
          <div className="sidebar-section-label">Platform</div>
          <NavGroup group={NAV_ADMIN} onNavigate={onNavigate} collapsed={collapsed} />
        </>)}

        <div className="sidebar-section-label">Workspace</div>
        {NAV_GROUPS.map((g, i) => <NavGroup key={g.label} group={g} onNavigate={onNavigate} collapsed={collapsed} up={i >= 4} />)}

        {/* Settings section */}
        <div className="sidebar-divider" />
        <div className="sidebar-section-label">Settings</div>
        {SETTINGS_GROUPS.map(g => <NavGroup key={g.label} group={g} onNavigate={onNavigate} collapsed={collapsed} up />)}
      </div>

      {/* Footer */}
      <div className="sidebar-footer">
        {user && (
          <div className="sidebar-user">
            <div className="avatar" style={{ width: 34, height: 34, fontSize: '.8rem' }}>
              {initials}
            </div>
            <div className="sidebar-user-info">
              <div className="sidebar-user-name">{user.name}</div>
              <div className="sidebar-user-role">{user.role}</div>
            </div>
          </div>
        )}
        <button className="sidebar-signout" onClick={onToggleCollapse} aria-label={collapsed ? 'Expand menu' : 'Collapse menu'} title={collapsed ? 'Expand menu' : 'Collapse menu'}>
          {collapsed ? <PanelLeftOpen size={16} /> : <PanelLeftClose size={16} />}
          <span className="sidebar-link-text">Collapse menu</span>
        </button>
        <button className="sidebar-signout" onClick={onLogout} title="Sign out">
          <LogOut size={16} />
          <span className="sidebar-link-text">Sign out</span>
        </button>
      </div>
    </aside>
  )
}

// ── Layout ───────────────────────────────────────────────────────────────────
function Layout({ user, onLogout }) {
  const [navOpen, setNavOpen] = useState(false)
  // Desktop menu: full width or a slim icon rail. Remembered per browser.
  const [collapsed, setCollapsed] = useState(() => { try { return localStorage.getItem('ts_nav_collapsed') === '1' } catch { return false } })
  function toggleCollapsed() {
    setCollapsed(c => { const n = !c; try { localStorage.setItem('ts_nav_collapsed', n ? '1' : '0') } catch { /* private mode */ } return n })
  }
  const initials = (user?.name ?? 'U').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} open={navOpen} onNavigate={() => setNavOpen(false)} collapsed={collapsed} onToggleCollapse={toggleCollapsed} />
      {navOpen && <div className="nav-backdrop" onClick={() => setNavOpen(false)} />}
      <main className="app-main">
        <header className="app-topbar">
          <button className="topbar-menu" aria-label="Open menu" onClick={() => setNavOpen(true)}><Menu size={20} /></button>
          <button className="topbar-collapse" aria-label={collapsed ? 'Expand menu' : 'Collapse menu'} title={collapsed ? 'Expand menu' : 'Collapse menu'} onClick={toggleCollapsed}>
            {collapsed ? <PanelLeftOpen size={18} /> : <PanelLeftClose size={18} />}
          </button>
          <div className="topbar-search"><GlobalSearch /></div>
          <div className="topbar-right">
            <NotificationBell />
            {user && <NavLink to="/settings/profile" className="topbar-user" title={user.name}><span className="avatar" style={{ width: 30, height: 30 }}>{initials}</span></NavLink>}
          </div>
        </header>
        <OnboardingBanner user={user} />
        <Routes>
          <Route path="/"                       element={<Navigate to="/trips" replace />} />
          <Route path="/dashboard"              element={<CrmDashboardPage />} />

          {/* Platform admin (TripSarthi team) */}
          <Route path="/admin"                  element={<AdminOnly user={user}><AdminOverviewPage /></AdminOnly>} />
          <Route path="/admin/customers"        element={<AdminOnly user={user}><AdminCustomersPage /></AdminOnly>} />
          <Route path="/admin/subscriptions"    element={<AdminOnly user={user}><AdminSubscriptionsPage /></AdminOnly>} />
          <Route path="/admin/inbox"            element={<AdminOnly user={user}><AdminInboxPage /></AdminOnly>} />

          {/* Leads / CRM */}
          <Route path="/trips"                  element={<TripsPage user={user} />} />
          <Route path="/trips/:id"              element={<TripDetailPage />} />
          <Route path="/itineraries/:id"        element={<ItineraryBuilderPage />} />
          <Route path="/bookings"               element={<BookingsPage />} />
          <Route path="/bookings/:id"           element={<BookingDetailPage />} />
          <Route path="/suppliers"              element={<SuppliersPage />} />
          <Route path="/payables"               element={<PayablesPage />} />
          <Route path="/departures"             element={<DeparturesPage />} />
          <Route path="/checklists"             element={<ChecklistsPage />} />
          <Route path="/business-report"        element={<BusinessReportPage />} />
          <Route path="/ads"                    element={<AdsReportPage />} />
          <Route path="/contacts"               element={<ContactsPage />} />
          <Route path="/contacts/:id"           element={<ContactDetailPage />} />
          <Route path="/accounts"               element={<AccountsPage />} />
          <Route path="/deals"                  element={<DealsPage />} />
          <Route path="/deals/new"              element={<DealCreatePage />} />
          <Route path="/deals/:id"              element={<DealDetailPage />} />
          <Route path="/tickets"                element={<TicketsPage />} />
          <Route path="/tickets/:id"            element={<TicketDetailPage />} />
          <Route path="/objects"                element={<CustomObjectsPage />} />
          <Route path="/objects/:id"            element={<RecordsPage />} />
          <Route path="/objects/:objectId/records/:recordId" element={<RecordDetailPage />} />
          <Route path="/tasks"                  element={<TasksPage />} />
          <Route path="/meetings"               element={<MeetingsPage />} />
          <Route path="/imports"                element={<ImportPage />} />
          <Route path="/tags"                   element={<TagsPage />} />
          <Route path="/recycle"                element={<RecycleBinPage />} />
          <Route path="/duplicates"             element={<DuplicatesPage />} />
          <Route path="/settings/mobile"        element={<MobileNotificationsPage />} />
          <Route path="/settings/business"      element={<BusinessProfilePage />} />
          <Route path="/settings/lead-sources"  element={<LeadSourcesPage />} />
          <Route path="/settings/gst-exports"   element={<GstExportsPage />} />
          <Route path="/settings/tcs"           element={<TcsPage />} />
          <Route path="/settings/fx"            element={<FxRatesPage />} />
          <Route path="/settings/einvoice"      element={<EinvoiceSettingsPage />} />
          <Route path="/ads/campaigns"          element={<AdCampaignsPage />} />
          <Route path="/ads/audiences"          element={<AdAudiencesPage />} />
          <Route path="/automations"            element={<TravelAutomationsPage />} />
          <Route path="/settings/ads"           element={<AdPlatformsPage />} />
          <Route path="/settings/dunning"       element={<DunningSettingsPage />} />
          <Route path="/settings/scoring"       element={<ScoringPage />} />
          <Route path="/settings/assignment"    element={<AssignmentRulesPage />} />
          <Route path="/settings/hours"         element={<BusinessHoursPage />} />
          <Route path="/settings/audit"         element={<AuditLogPage />} />
          <Route path="/settings/teams"         element={<TeamsPage />} />

          {/* Messaging */}
          <Route path="/inbox"                  element={<InboxPage />} />
          <Route path="/templates"              element={<TemplatesPage />} />
          <Route path="/campaigns"              element={<CampaignsPage />} />
          <Route path="/products"               element={<ProductsPage />} />
          <Route path="/price-books"            element={<PriceBooksPage />} />

          {/* Automation */}
          <Route path="/flows"                  element={<FlowsPage />} />
          <Route path="/flows/:id"              element={<FlowBuilderPage />} />

          {/* Analytics */}
          <Route path="/analytics"              element={<AnalyticsPage />} />
          <Route path="/analytics/meta-leads/:month" element={<MetaLeadsMonthPage />} />
          <Route path="/reports"                element={<ReportsPage />} />
          <Route path="/forecast"               element={<ForecastPage />} />
          <Route path="/leaderboard"            element={<LeaderboardPage />} />

          {/* Segments */}
          <Route path="/segments"               element={<SegmentsPage />} />

          {/* Lead capture */}
          <Route path="/web-forms"              element={<WebFormsPage />} />
          <Route path="/tools/wa-link"          element={<WaLinkPage />} />

          {/* Settings */}
          <Route path="/social"                 element={<SocialPage />} />
          <Route path="/email"                        element={<EmailMarketingPage />} />
          <Route path="/email/campaigns/new"          element={<EmailCampaignEditorPage />} />
          <Route path="/email/campaigns/:id/edit"     element={<EmailCampaignEditorPage />} />
          <Route path="/email/campaigns/:id"          element={<EmailCampaignReportPage />} />
          <Route path="/email/templates/new"          element={<EmailTemplateEditorPage />} />
          <Route path="/email/templates/:id"          element={<EmailTemplateEditorPage />} />
          <Route path="/settings/waba"          element={<WabaPage />} />
          <Route path="/settings/integrations"  element={<IntegrationsPage />} />
          <Route path="/settings/billing"       element={<BillingPage />} />
          <Route path="/settings/branding"      element={<BrandingPage />} />
          <Route path="/settings/profile"       element={<ProfilePage />} />
          <Route path="/settings/team"          element={<TeamPage />} />
          <Route path="/settings/routing"       element={<RoutingRulesPage />} />
          <Route path="/settings/payments"      element={<PaymentsPage />} />
          <Route path="/settings/ecommerce"     element={<EcommercePage />} />
          <Route path="/settings/webhooks"      element={<WebhooksPage />} />
          <Route path="/settings/ai"            element={<AiPage />} />
          <Route path="/settings/notifications" element={<NotificationsPage />} />
        </Routes>
      </main>
    </div>
  )
}

// ── App root ─────────────────────────────────────────────────────────────────
export default function App() {
  const [user, setUser]                 = useState(null)
  const [authed, setAuthed]             = useState(isLoggedIn)
  const [showRegister, setShowRegister] = useState(() => /^#\/(register|signup)/.test(window.location.hash))
  const [showForgot, setShowForgot]     = useState(false)
  const [showReset, setShowReset]       = useState(false)
  const [, setHashTick]               = useState(0) // re-render on hash change so public #/q/ links switch in place

  useEffect(() => {
    if (window.location.hash.includes('reset-password')) setShowReset(true)

    // Global 401 listener — any API call with an expired/revoked token triggers auto-logout
    function onUnauthorized() {
      setUser(null)
      setAuthed(false)
    }
    const onHash = () => { if (/^#\/(register|signup)/.test(window.location.hash)) setShowRegister(true); else if (/^#\/login/.test(window.location.hash)) setShowRegister(false); setHashTick(n => n + 1) }
    window.addEventListener('tp:unauthorized', onUnauthorized)
    window.addEventListener('hashchange', onHash)
    return () => { window.removeEventListener('tp:unauthorized', onUnauthorized); window.removeEventListener('hashchange', onHash) }
  }, [])

  // After a page reload we only have the token: ask who we are (also brings back the sidebar user card and platform-admin menu).
  useEffect(() => {
    if (!authed || user) return undefined
    let alive = true
    api.get('/auth/me').then(r => { if (alive && r?.user) setUser(r.user) }).catch(() => {})
    return () => { alive = false }
  }, [authed, user])

  function handleLogin(userData) { setUser(userData); setAuthed(true); setShowRegister(false); if (/^#\/(login|register|signup)/.test(window.location.hash)) window.location.hash = '#/' }
  function handleLogout() { clearToken(); setUser(null); setAuthed(false) }

  // Customer quote links (#/q/<token>) are public — no login, no app shell.
  const publicQuote = window.location.hash.match(/^#\/q\/([a-f0-9]{32})/)
  if (publicQuote) return <PublicQuotePage token={publicQuote[1]} />
  const publicPortal = window.location.hash.match(/^#\/trip\/([a-f0-9]{32})/)
  if (publicPortal) return <PublicPortalPage token={publicPortal[1]} />

  return (
    <ToastProvider>
      {!authed ? (
        showReset ? (
          <ResetPasswordPage
            onResetSuccess={() => setShowReset(false)}
          />
        ) : showForgot ? (
          <ForgotPasswordPage
            onBackToLogin={() => setShowForgot(false)}
          />
        ) : showRegister ? (
          <RegisterPage
            onLogin={handleLogin}
            onShowLogin={() => setShowRegister(false)}
          />
        ) : (
          <LoginPage
            onLogin={handleLogin}
            onShowRegister={() => setShowRegister(true)}
            onShowForgot={() => setShowForgot(true)}
          />
        )
      ) : (
        <BrandingProvider>
          <HashRouter>
            <Layout user={user} onLogout={handleLogout} />
          </HashRouter>
        </BrandingProvider>
      )}
    </ToastProvider>
  )
}
