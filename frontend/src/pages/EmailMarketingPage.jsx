import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  Mail, Plus, Send, Copy, Trash2, Pencil, AlertTriangle, BarChart3, FileText,
  ShieldOff, Settings as SettingsIcon, CheckCircle2, MousePointerClick, MailOpen,
} from 'lucide-react'
import { emailMarketing } from '../api/emailMarketing'
import { toast } from '../components/Toast'
import { audienceLabel, EMAIL_STATUS_BADGE, EMAIL_STATUS_LABEL, fmtUtc } from '../components/email/emailShared'

/**
 * Email marketing hub: campaigns, templates, the suppression list and the
 * sending (SMTP) settings. Bulk email always goes through the customer's own
 * SMTP server — the same bring-your-own rule as their WhatsApp number.
 */

const TABS = [
  { key: 'campaigns',    label: 'Campaigns',        icon: Send },
  { key: 'templates',    label: 'Templates',        icon: FileText },
  { key: 'suppressions', label: 'Unsubscribes',     icon: ShieldOff },
  { key: 'settings',     label: 'Sending settings', icon: SettingsIcon },
]

export default function EmailMarketingPage() {
  const [params, setParams] = useSearchParams()
  const tab = TABS.some(t => t.key === params.get('tab')) ? params.get('tab') : 'campaigns'
  const [overview, setOverview] = useState(null)

  function loadOverview() {
    emailMarketing.overview().then(r => setOverview(r.data)).catch(() => {})
  }
  useEffect(loadOverview, [])

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <h1 className="page-title">Email Marketing</h1>
          <p className="text-muted mt-1">Send newsletters and offers to your contacts by email, and see who opened and clicked.</p>
        </div>
        <div className="flex items-center gap-2">
          <Link to="/email/templates/new" className="btn btn-ghost"><FileText size={15} /> New template</Link>
          <Link to="/email/campaigns/new" className="btn btn-primary"><Plus size={15} /> New campaign</Link>
        </div>
      </div>

      {overview && !overview.ready && (
        <div className="card mb-4" style={{ borderColor: '#f59e0b' }}>
          <div className="card-body flex items-start gap-3">
            <AlertTriangle size={18} style={{ color: '#d97706', flexShrink: 0, marginTop: 2 }} />
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 600, fontSize: '.875rem' }}>Connect your email server to start sending</div>
              <div className="text-muted" style={{ fontSize: '.8rem', marginTop: '.2rem' }}>
                Campaigns go out through your own SMTP account (Google Workspace, Zoho, Hostinger, Amazon SES, Brevo…),
                so they come from your domain and your sending reputation stays yours.
              </div>
            </div>
            <button className="btn btn-primary btn-sm" onClick={() => setParams({ tab: 'settings' })}>Set up SMTP</button>
          </div>
        </div>
      )}

      {overview && (
        <div className="mb-4" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12 }}>
          <Stat icon={Mail} label="Contacts with email" value={overview.contacts_with_email.toLocaleString()} />
          <Stat icon={Send} label="Sent (30 days)" value={overview.last_30_days.sent.toLocaleString()} />
          <Stat icon={Send} label={overview.daily_limit ? `Used in last 24h / daily limit${overview.copy_to ? ' (incl. copies)' : ''}` : 'Sent in last 24h'}
                value={overview.daily_limit ? `${overview.sent_last_24h} / ${overview.daily_limit}` : String(overview.sent_last_24h)} />
          <Stat icon={MailOpen} label="Open rate (30 days)" value={`${overview.last_30_days.open_rate}%`} />
          <Stat icon={MousePointerClick} label="Click rate (30 days)" value={`${overview.last_30_days.click_rate}%`} />
          <Stat icon={ShieldOff} label="Unsubscribed / suppressed" value={overview.suppressed.toLocaleString()} />
        </div>
      )}

      <div className="flex gap-2 mb-4" style={{ borderBottom: '1px solid var(--border, #e5e7eb)', flexWrap: 'wrap' }}>
        {TABS.map(t => (
          <button key={t.key} onClick={() => setParams({ tab: t.key })}
                  className="btn btn-ghost btn-sm"
                  style={{ borderRadius: 0, borderBottom: tab === t.key ? '2px solid var(--primary, #2563eb)' : '2px solid transparent', fontWeight: tab === t.key ? 600 : 400 }}>
            <t.icon size={14} /> {t.label}
          </button>
        ))}
      </div>

      {tab === 'campaigns'    && <CampaignsTab />}
      {tab === 'templates'    && <TemplatesTab />}
      {tab === 'suppressions' && <SuppressionsTab onChange={loadOverview} />}
      {tab === 'settings'     && <SettingsTab onSaved={loadOverview} />}
    </div>
  )
}

function Stat({ icon: Icon, label, value }) {
  return (
    <div className="card">
      <div className="card-body">
        <div className="text-muted flex items-center gap-2" style={{ fontSize: 12 }}><Icon size={13} /> {label}</div>
        <div style={{ fontSize: 22, fontWeight: 700, marginTop: 4 }}>{value}</div>
      </div>
    </div>
  )
}

// ── Campaigns ──────────────────────────────────────────────────────────

function CampaignsTab() {
  const [rows, setRows] = useState(null)
  const navigate = useNavigate()

  function load() {
    emailMarketing.campaigns().then(r => setRows(r.data ?? [])).catch(e => { setRows([]); toast.error('Could not load campaigns', e.message) })
  }
  useEffect(load, [])

  // Keep sending campaigns' progress live.
  useEffect(() => {
    if (!rows?.some(r => r.status === 'processing')) return
    const t = setInterval(load, 10000)
    return () => clearInterval(t)
  }, [rows])

  async function act(fn, ok) {
    try { await fn(); toast.success(ok); load() } catch (e) { toast.error('Action failed', e.message) }
  }

  if (rows === null) return <div className="text-muted">Loading…</div>
  if (rows.length === 0) {
    return (
      <div className="empty-state card">
        <div className="card-body" style={{ textAlign: 'center', padding: 40 }}>
          <Mail size={32} className="empty-state-icon" />
          <p className="empty-state-text">No email campaigns yet.</p>
          <Link to="/email/campaigns/new" className="btn btn-primary mt-2"><Plus size={15} /> Create your first campaign</Link>
        </div>
      </div>
    )
  }

  return (
    <div className="card" style={{ overflowX: 'auto' }}>
      <table className="data-table">
        <thead>
          <tr><th>Campaign</th><th>Status</th><th>Audience</th><th style={{ textAlign: 'right' }}>Sent</th><th style={{ textAlign: 'right' }}>Opened</th><th style={{ textAlign: 'right' }}>Clicked</th><th style={{ textAlign: 'right' }}>Replied</th><th /></tr>
        </thead>
        <tbody>
          {rows.map(c => {
            const f = c.funnel ?? {}
            const editable = ['draft', 'scheduled'].includes(c.status)
            return (
              <tr key={c.id} style={{ cursor: 'pointer' }} onClick={() => navigate(editable ? `/email/campaigns/${c.id}/edit` : `/email/campaigns/${c.id}`)}>
                <td className="lp-cell-cap-mobile">
                  <div style={{ fontWeight: 600 }}>{c.name}</div>
                  <div className="text-muted" style={{ fontSize: 12 }}>{c.subject}</div>
                </td>
                <td>
                  <span className={`badge ${EMAIL_STATUS_BADGE[c.status] ?? 'badge-draft'}`}>{EMAIL_STATUS_LABEL[c.status] ?? c.status}</span>
                  {c.status === 'scheduled' && <div className="text-muted" style={{ fontSize: 11, marginTop: 2 }}>{fmtUtc(c.scheduled_at)}</div>}
                  {c.status === 'processing' && c.total_contacts > 0 && (
                    <div className="text-muted" style={{ fontSize: 11, marginTop: 2 }}>{(f.recipients ?? 0).toLocaleString()} / {Number(c.total_contacts).toLocaleString()}</div>
                  )}
                  {c.last_error && c.status !== 'done' && (
                    <div style={{ fontSize: 11, color: '#b45309', marginTop: 2, maxWidth: 260 }}>{c.last_error}</div>
                  )}
                </td>
                <td className="text-sm lp-cell-cap-mobile">{audienceLabel(c.segment)}</td>
                <td style={{ textAlign: 'right' }}>{(f.sent ?? 0).toLocaleString()}{f.failed > 0 && <div style={{ fontSize: 11, color: '#dc2626' }}>{f.failed} failed</div>}</td>
                <td style={{ textAlign: 'right' }}>{f.sent ? `${f.open_rate}%` : '—'}</td>
                <td style={{ textAlign: 'right' }}>{f.sent ? `${f.click_rate}%` : '—'}</td>
                <td style={{ textAlign: 'right' }}>{f.replied ? <strong style={{ color: '#16a34a' }}>{f.replied}</strong> : (f.sent ? 0 : '—')}</td>
                <td onClick={e => e.stopPropagation()} style={{ whiteSpace: 'nowrap', textAlign: 'right' }}>
                  {!editable && <Link className="btn btn-ghost btn-sm" to={`/email/campaigns/${c.id}`} title="Report"><BarChart3 size={14} /></Link>}
                  {editable && <Link className="btn btn-ghost btn-sm" to={`/email/campaigns/${c.id}/edit`} title="Edit"><Pencil size={14} /></Link>}
                  <button className="btn btn-ghost btn-sm" title="Duplicate"
                          onClick={() => act(() => emailMarketing.duplicate(c.id), 'Campaign duplicated')}><Copy size={14} /></button>
                  {c.status !== 'processing' && (
                    <button className="btn btn-ghost btn-sm" title="Delete"
                            onClick={() => { if (confirm(`Delete "${c.name}"?`)) act(() => emailMarketing.deleteCampaign(c.id), 'Campaign deleted') }}>
                      <Trash2 size={14} />
                    </button>
                  )}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

// ── Templates ──────────────────────────────────────────────────────────

function TemplatesTab() {
  const [rows, setRows] = useState(null)

  function load() {
    emailMarketing.templates().then(r => setRows(r.data ?? [])).catch(e => { setRows([]); toast.error('Could not load templates', e.message) })
  }
  useEffect(load, [])

  async function remove(t) {
    if (!confirm(`Delete template "${t.name}"? Campaigns already created from it keep their own copy.`)) return
    try { await emailMarketing.deleteTemplate(t.id); toast.success('Template deleted'); load() }
    catch (e) { toast.error('Delete failed', e.message) }
  }

  if (rows === null) return <div className="text-muted">Loading…</div>

  return (
    <div className="card" style={{ overflowX: 'auto' }}>
      {rows.length === 0 ? (
        <div className="card-body" style={{ textAlign: 'center', padding: 40 }}>
          <p className="empty-state-text">Templates let you reuse a design across campaigns and automations.</p>
          <Link to="/email/templates/new" className="btn btn-primary mt-2"><Plus size={15} /> New template</Link>
        </div>
      ) : (
        <table className="data-table">
          <thead><tr><th>Name</th><th>Subject</th><th>Updated</th><th /></tr></thead>
          <tbody>
            {rows.map(t => (
              <tr key={t.id}>
                <td className="lp-cell-cap-mobile"><Link to={`/email/templates/${t.id}`} style={{ fontWeight: 600 }}>{t.name}</Link></td>
                <td className="text-sm lp-cell-cap-mobile">{t.subject}</td>
                <td className="text-sm text-muted">{fmtUtc(t.updated_at)}</td>
                <td style={{ whiteSpace: 'nowrap', textAlign: 'right' }}>
                  <Link className="btn btn-ghost btn-sm" to={`/email/campaigns/new?template=${t.id}`} title="Use in a campaign"><Send size={14} /></Link>
                  <Link className="btn btn-ghost btn-sm" to={`/email/templates/${t.id}`} title="Edit"><Pencil size={14} /></Link>
                  <button className="btn btn-ghost btn-sm" title="Delete" onClick={() => remove(t)}><Trash2 size={14} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

// ── Suppression list ───────────────────────────────────────────────────

const REASON_LABEL = { unsubscribed: 'Unsubscribed', bounced: 'Bounced', complaint: 'Spam complaint', manual: 'Added manually' }

function SuppressionsTab({ onChange }) {
  const [data, setData]     = useState(null)
  const [search, setSearch] = useState('')
  const [page, setPage]     = useState(1)
  const [adding, setAdding] = useState(false)
  const [text, setText]     = useState('')

  function load() {
    emailMarketing.suppressions({ search, page }).then(r => setData(r.data)).catch(e => toast.error('Could not load list', e.message))
  }
  useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t) }, [search, page]) // eslint-disable-line react-hooks/exhaustive-deps

  async function add(e) {
    e.preventDefault()
    try {
      const r = await emailMarketing.addSuppressions({ emails: text, reason: 'manual' })
      toast.success(`${r.data.added} address(es) suppressed`, r.data.invalid?.length ? `Skipped invalid: ${r.data.invalid.join(', ')}` : undefined)
      setText(''); setAdding(false); load(); onChange?.()
    } catch (err) { toast.error('Could not add', err.message) }
  }

  async function remove(row) {
    if (!confirm(`Allow campaigns to email ${row.email} again?${row.reason === 'unsubscribed' ? '\n\nThis person unsubscribed themselves — only do this if they asked to be re-added.' : ''}`)) return
    try { await emailMarketing.removeSuppression(row.id); toast.success('Removed'); load(); onChange?.() }
    catch (e) { toast.error('Could not remove', e.message) }
  }

  return (
    <div className="card">
      <div className="card-body">
        <div className="flex items-center justify-between gap-2 mb-4" style={{ flexWrap: 'wrap' }}>
          <p className="text-muted text-sm" style={{ margin: 0, maxWidth: 560 }}>
            Campaigns and automations never email these addresses. People land here by clicking unsubscribe;
            you can also add addresses yourself. Personal emails from a contact's page are not blocked.
          </p>
          <div className="flex gap-2">
            <input className="form-input" placeholder="Search email…" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} style={{ width: 200 }} />
            <button className="btn btn-primary btn-sm" onClick={() => setAdding(a => !a)}><Plus size={14} /> Add</button>
          </div>
        </div>

        {adding && (
          <form onSubmit={add} className="mb-4">
            <textarea className="form-input w-full" rows={3} value={text} onChange={e => setText(e.target.value)}
                      placeholder="Paste addresses — one per line, or separated by commas" />
            <div className="flex gap-2 mt-2">
              <button className="btn btn-primary btn-sm" disabled={!text.trim()}>Suppress</button>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setAdding(false)}>Cancel</button>
            </div>
          </form>
        )}

        {!data ? <div className="text-muted">Loading…</div> : data.rows.length === 0 ? (
          <div className="text-muted text-sm" style={{ padding: 20, textAlign: 'center' }}>Nobody has unsubscribed{search ? ' matching that search' : ''}.</div>
        ) : (
          <>
            <div style={{ overflowX: 'auto' }}><table className="data-table">
              <thead><tr><th>Email</th><th>Contact</th><th>Reason</th><th>Since</th><th /></tr></thead>
              <tbody>
                {data.rows.map(r => (
                  <tr key={r.id}>
                    <td>{r.email}</td>
                    <td className="text-sm">{r.contact_id ? <Link to={`/contacts/${r.contact_id}`}>{r.contact_name || `#${r.contact_id}`}</Link> : '—'}</td>
                    <td className="text-sm">{REASON_LABEL[r.reason] ?? r.reason}</td>
                    <td className="text-sm text-muted">{fmtUtc(r.created_at)}</td>
                    <td style={{ textAlign: 'right' }}><button className="btn btn-ghost btn-sm" title="Remove from list" onClick={() => remove(r)}><Trash2 size={14} /></button></td>
                  </tr>
                ))}
              </tbody>
            </table></div>
            {data.total > data.per_page && (
              <div className="flex items-center justify-between mt-2 text-sm">
                <span className="text-muted">{data.total.toLocaleString()} addresses</span>
                <div className="flex gap-2">
                  <button className="btn btn-ghost btn-sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
                  <button className="btn btn-ghost btn-sm" disabled={page * data.per_page >= data.total} onClick={() => setPage(p => p + 1)}>Next</button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}

// ── Sending settings (tenant SMTP) ─────────────────────────────────────

const PRESETS = [
  // Port 465 (SSL) where the provider offers it: many VPS hosts block outbound 587.
  { label: 'Gmail',                    host: 'smtp.gmail.com',           port: 465, crypto: 'ssl', rate: 20,  daily: 450 },
  { label: 'Google Workspace',         host: 'smtp.gmail.com',           port: 465, crypto: 'ssl', rate: 20,  daily: 1800 },
  { label: 'Zoho Mail',                host: 'smtp.zoho.in',             port: 465, crypto: 'ssl', rate: 20,  daily: 0 },
  { label: 'Hostinger',                host: 'smtp.hostinger.com',       port: 465, crypto: 'ssl', rate: 20,  daily: 0 },
  { label: 'Brevo (Sendinblue)',       host: 'smtp-relay.brevo.com',     port: 587, crypto: 'tls', rate: 100, daily: 0 },
  { label: 'Amazon SES (Mumbai)',      host: 'email-smtp.ap-south-1.amazonaws.com', port: 465, crypto: 'ssl', rate: 200, daily: 0 },
]

function SettingsTab({ onSaved }) {
  const [cfg, setCfg]         = useState(null)
  const [password, setPassword] = useState('')
  const [saving, setSaving]   = useState(false)
  const [testTo, setTestTo]   = useState('')
  const [testing, setTesting] = useState(false)

  useEffect(() => {
    emailMarketing.smtpConfig()
      .then(r => setCfg({ rate_per_minute: 50, daily_limit: 0, copy_to: '', footer_text: '', ...r.data, crypto: r.data.crypto || 'tls' }))
      .catch(e => { setCfg(false); toast.error('Could not load settings', e.message) })
  }, [])

  if (cfg === null) return <div className="text-muted">Loading…</div>
  if (cfg === false) return <div className="card"><div className="card-body text-muted">Only owners and admins can change sending settings.</div></div>

  const set = (k) => (e) => setCfg(c => ({ ...c, [k]: e.target.value }))

  async function save(e) {
    e.preventDefault()
    setSaving(true)
    try {
      await emailMarketing.saveSmtpConfig({ ...cfg, port: Number(cfg.port), rate_per_minute: Number(cfg.rate_per_minute), daily_limit: Number(cfg.daily_limit) || 0, password })
      toast.success('Sending settings saved')
      setPassword('')
      setCfg(c => ({ ...c, configured: true, has_password: c.has_password || Boolean(password) }))
      onSaved?.()
    } catch (err) { toast.error('Could not save', err.message) }
    setSaving(false)
  }

  async function test() {
    setTesting(true)
    try { const r = await emailMarketing.testSmtp(testTo); toast.success(r.message ?? 'Test sent') }
    catch (e) { toast.error('SMTP test failed', e.message) }
    setTesting(false)
  }

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 16, alignItems: 'start' }}>
      <form className="card" onSubmit={save}>
        <div className="card-body">
          <div className="flex items-center gap-2 mb-4">
            {cfg.configured
              ? <><CheckCircle2 size={16} style={{ color: '#16a34a' }} /><strong>Connected</strong><span className="text-muted text-sm">— campaigns send via {cfg.host}</span></>
              : <strong>Connect your SMTP server</strong>}
          </div>

          <div className="form-group">
            <label className="form-label">Quick fill</label>
            <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
              {PRESETS.map(p => (
                <button key={p.label} type="button" className="btn btn-ghost btn-sm"
                        onClick={() => setCfg(c => ({ ...c, host: p.host, port: p.port, crypto: p.crypto, rate_per_minute: p.rate, daily_limit: p.daily }))}>{p.label}</button>
              ))}
            </div>
          </div>

          <div className="form-grid-2">
            <div className="form-group"><label className="form-label">SMTP host</label><input className="form-input" value={cfg.host} onChange={set('host')} required placeholder="smtp.example.com" /></div>
            <div className="form-grid-2">
              <div className="form-group"><label className="form-label">Port</label><input className="form-input" type="number" value={cfg.port} onChange={set('port')} required /></div>
              <div className="form-group"><label className="form-label">Security</label>
                <select className="form-select" value={cfg.crypto} onChange={set('crypto')}>
                  <option value="tls">STARTTLS (587)</option><option value="ssl">SSL (465)</option><option value="">None</option>
                </select>
              </div>
            </div>
            <div className="form-group"><label className="form-label">Username</label><input className="form-input" value={cfg.username} onChange={set('username')} autoComplete="off" /></div>
            <div className="form-group"><label className="form-label">Password / app password</label>
              <input className="form-input" type="password" value={password} onChange={e => setPassword(e.target.value)} autoComplete="new-password"
                     placeholder={cfg.has_password ? '•••••••• (saved — leave blank to keep)' : ''} />
            </div>
            <div className="form-group"><label className="form-label">From email</label><input className="form-input" type="email" value={cfg.from_email} onChange={set('from_email')} required placeholder="news@yourdomain.com" /></div>
            <div className="form-group"><label className="form-label">From name</label><input className="form-input" value={cfg.from_name} onChange={set('from_name')} placeholder="Your Company" /></div>
            <div className="form-group"><label className="form-label">Send rate (emails per minute)</label>
              <input className="form-input" type="number" min={1} max={500} value={cfg.rate_per_minute} onChange={set('rate_per_minute')} />
              <div className="form-hint">How fast a campaign goes out.</div>
            </div>
            <div className="form-group"><label className="form-label">Daily limit (emails per 24 hours)</label>
              <input className="form-input" type="number" min={0} value={cfg.daily_limit} onChange={set('daily_limit')} />
              <div className="form-hint">0 = no limit. Gmail allows ~500/day, Workspace ~2,000. Big campaigns pause at the limit and carry on automatically.</div>
            </div>
          </div>
          <div className="form-group">
            <label className="form-label">Send me a copy of every email (optional)</label>
            <input className="form-input" type="email" value={cfg.copy_to ?? ''} onChange={set('copy_to')} placeholder="you@yourdomain.com" />
            <div className="form-hint">
              Each copy arrives separately as "[Copy → recipient] subject", so opening it never counts as the customer's open.
              Copies are sent through the same account and count against the daily limit — each recipient then uses two.
            </div>
          </div>
          <div className="form-group">
            <label className="form-label">Reply alerts &amp; daily report to (optional)</label>
            <input className="form-input" type="email" value={cfg.notify_to ?? ''} onChange={set('notify_to')} placeholder="you@yourdomain.com" />
            <div className="form-hint">
              Gets an alert the moment a customer replies to a campaign, plus a report every morning at 9:00 IST — who opened, clicked and replied.
              {' '}Reply tracking reads your reply-to mailbox:{' '}
              {!cfg.imap_supported
                ? <strong style={{ color: '#b45309' }}>the server needs the PHP IMAP extension first.</strong>
                : cfg.replies_last_error
                  ? <strong style={{ color: '#b45309' }}>last check failed — {cfg.replies_last_error}</strong>
                  : cfg.replies_last_ok
                    ? <span style={{ color: '#16a34a' }}>working (last checked {new Date(cfg.replies_last_ok * 1000).toLocaleString()}).</span>
                    : <span>connect it under <Link to="/settings/integrations">Settings → Integrations → Inbound email (IMAP)</Link>.</span>}
            </div>
          </div>
          <div className="form-group">
            <label className="form-label">Footer — company name and address</label>
            <textarea className="form-input w-full" rows={2} value={cfg.footer_text} onChange={set('footer_text')} placeholder="Acme Pvt Ltd, 12 MG Road, Gurugram 122001" />
            <div className="form-hint">Shown above the unsubscribe link in every campaign. A physical address is required by anti-spam laws in many countries.</div>
          </div>
          <button className="btn btn-primary" disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</button>
        </div>
      </form>

      <div className="flex flex-col gap-4">
        <div className="card"><div className="card-body">
          <strong>Send a test</strong>
          <p className="text-muted text-sm">Checks the saved settings by sending a real email.</p>
          <input className="form-input w-full" type="email" placeholder="you@yourdomain.com (default: your login)" value={testTo} onChange={e => setTestTo(e.target.value)} />
          <button className="btn btn-ghost btn-sm mt-2" onClick={test} disabled={testing || !cfg.configured}>{testing ? 'Sending…' : 'Send test email'}</button>
        </div></div>
        <div className="card"><div className="card-body text-sm">
          <strong>Deliverability checklist</strong>
          <ul style={{ paddingLeft: 18, margin: '8px 0 0', lineHeight: 1.7 }} className="text-muted">
            <li>Send from your own domain, not @gmail.com.</li>
            <li>Add SPF, DKIM and DMARC records for that domain (your provider shows them).</li>
            <li>Warm up: start with a few hundred a day and grow.</li>
            <li>Only email people who expect to hear from you.</li>
          </ul>
        </div></div>
      </div>
    </div>
  )
}
