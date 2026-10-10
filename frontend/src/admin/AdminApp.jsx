import { useState, useEffect } from 'react'
import { HashRouter, Routes, Route, NavLink, Navigate } from 'react-router-dom'
import { LayoutDashboard, Building2, CreditCard, MessagesSquare, LogOut, Menu, ShieldCheck } from 'lucide-react'
import { ToastProvider } from '../components/Toast'
import { isLoggedIn } from '../api/client'
import { adminAuth } from '../api/adminAuth'
import AdminLoginPage from './AdminLoginPage'
import AdminOverviewPage from '../pages/admin/AdminOverviewPage'
import AdminCustomersPage from '../pages/admin/AdminCustomersPage'
import AdminSubscriptionsPage from '../pages/admin/AdminSubscriptionsPage'
import AdminInboxPage from '../pages/admin/AdminInboxPage'

// One place to add sections as the admin grows (offers, partners, WhatsApp, ...).
const NAV = [
  { section: 'Platform', items: [
    { to: '/admin',               label: 'Overview',      icon: LayoutDashboard, end: true },
    { to: '/admin/customers',     label: 'Customers',     icon: Building2 },
    { to: '/admin/subscriptions', label: 'Subscriptions', icon: CreditCard },
    { to: '/admin/inbox',         label: 'Website inbox', icon: MessagesSquare },
  ] },
]

function Shell({ user, onLogout }) {
  const [navOpen, setNavOpen] = useState(false)
  const initials = (user?.name ?? 'A').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
  return (
    <div className="app-shell">
      <aside className={'sidebar' + (navOpen ? ' open' : '')}>
        <div className="sidebar-logo">
          <span className="sidebar-logo-icon" style={{ background: '#fff', padding: 3 }}><img src="/brand/tripsarthi-mark.png" alt="" width="30" height="30" style={{ display: 'block' }} /></span>
          <span className="sidebar-logo-text">Trip<span style={{ color: '#2fd0c2' }}>Sarthi</span> <span style={{ fontSize: '.6rem', opacity: .8, letterSpacing: '.6px', textTransform: 'uppercase' }}>Admin</span></span>
        </div>
        <div className="sidebar-nav">
          {NAV.map(g => (
            <div key={g.section}>
              <div className="sidebar-section-label">{g.section}</div>
              {g.items.map(({ to, label, icon: Icon, end }) => (
                <NavLink key={to} to={to} end={end} onClick={() => setNavOpen(false)} className={({ isActive }) => 'sidebar-link' + (isActive ? ' active' : '')}>
                  <span className="sidebar-link-icon"><Icon size={17} strokeWidth={1.8} /></span>
                  <span className="sidebar-link-text">{label}</span>
                </NavLink>
              ))}
            </div>
          ))}
        </div>
        <div className="sidebar-footer">
          <div className="sidebar-user">
            <div className="avatar" style={{ width: 34, height: 34, fontSize: '.8rem' }}>{initials}</div>
            <div className="sidebar-user-info">
              <div className="sidebar-user-name">{user?.name}</div>
              <div className="sidebar-user-role"><ShieldCheck size={11} style={{ verticalAlign: '-1px' }} /> Platform admin</div>
            </div>
          </div>
          <button className="sidebar-signout" onClick={onLogout} title="Sign out"><LogOut size={16} /><span className="sidebar-link-text">Sign out</span></button>
        </div>
      </aside>
      {navOpen && <div className="nav-backdrop" onClick={() => setNavOpen(false)} />}
      <main className="app-main">
        <header className="app-topbar">
          <button className="topbar-menu" aria-label="Open menu" onClick={() => setNavOpen(true)}><Menu size={20} /></button>
          <div style={{ flex: 1 }} />
          <div className="topbar-right"><span style={{ fontSize: '.82rem', color: 'var(--text-3)', marginRight: 8 }}>{user?.email}</span><span className="avatar" style={{ width: 30, height: 30 }}>{initials}</span></div>
        </header>
        <Routes>
          <Route path="/" element={<Navigate to="/admin" replace />} />
          <Route path="/admin" element={<AdminOverviewPage />} />
          <Route path="/admin/customers" element={<AdminCustomersPage />} />
          <Route path="/admin/subscriptions" element={<AdminSubscriptionsPage />} />
          <Route path="/admin/inbox" element={<AdminInboxPage />} />
          <Route path="*" element={<Navigate to="/admin" replace />} />
        </Routes>
      </main>
    </div>
  )
}

export default function AdminApp() {
  const [authed, setAuthed] = useState(isLoggedIn)
  const [user, setUser] = useState(null)

  useEffect(() => {
    const out = () => { setUser(null); setAuthed(false) }                       // any 401 (expired / revoked session) lands here
    window.addEventListener('tp:unauthorized', out)
    return () => window.removeEventListener('tp:unauthorized', out)
  }, [])

  useEffect(() => {                                                              // after a reload we only have the token: ask who we are
    if (!authed || user) return undefined
    let alive = true
    adminAuth.me().then(u => { if (alive) setUser(u) }).catch(() => {})
    return () => { alive = false }
  }, [authed, user])

  async function logout() { await adminAuth.logout(); setUser(null); setAuthed(false) }

  return (
    <ToastProvider>
      {!authed
        ? <AdminLoginPage onLogin={u => { setUser(u); setAuthed(true); if (window.location.hash.startsWith('#/login')) window.location.hash = '#/' }} />
        : <HashRouter><Shell user={user} onLogout={logout} /></HashRouter>}
    </ToastProvider>
  )
}
