import { useState, useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { billing as billingApi } from '../api/billing'
import { social as socialApi } from '../api/social'
import { toast } from '../components/Toast'
import GoogleLeadFormsSection from './GoogleLeadFormsSection'
import MetaAdsSection from './MetaAdsSection'
import { Copy, CheckCircle2, XCircle, AlertTriangle, Bell, FlaskConical, X, Building2, Cloud, Target, Mail, Zap, Link2, RefreshCw, Search } from 'lucide-react'
import ImapInboundSection from './ImapInboundSection'

// ── Clipboard helper ───────────────────────────────────────────────────────
async function copyText(text, label) {
  try { await navigator.clipboard.writeText(text); toast.success(`${label} copied!`) }
  catch { toast.error('Copy failed') }
}

// ── Connected integration card ─────────────────────────────────────────────
function IntegrationRow({ integration, metaWebhookUrl, onSubscribe, onDisconnect, subscribing, disconnecting, confirmId, setConfirmId }) {
  const isSubscribed = integration.subscribed || integration.config?.subscribed
  const subscribedAt = integration.config?.subscribed_at
  const [showWebhook, setShowWebhook] = useState(false)
  const [testing, setTesting] = useState(false)
  const [testResult, setTestResult] = useState(null)

  async function handleTestLead() {
    setTesting(true); setTestResult(null)
    try {
      const token = localStorage.getItem('tp_token')
      const resp = await fetch(`/api/v1/meta-integrations/${integration.id}/test`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
      })
      const data = await resp.json().catch(() => ({}))
      if (data.success) {
        setTestResult({ ok: true, message: `Test lead created! Contact ID: ${data.contact_id} — Check Contacts page.` })
        toast.success('Test lead created!', 'New contact added from Meta Lead Ads')
      } else {
        const msg = data.message ?? data.messages?.error ?? `HTTP ${resp.status}`
        setTestResult({ ok: false, message: msg })
        toast.error('Test lead failed', msg)
      }
    } catch (err) {
      setTestResult({ ok: false, message: err.message })
      toast.error('Test failed', err.message)
    } finally {
      setTesting(false)
    }
  }

  return (
    <div style={{
      border: '1px solid var(--border)', borderRadius: 12, padding: '1.25rem',
      background: '#fff', marginBottom: '.75rem',
    }}>
      {/* Header row */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '.85rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          {/* Facebook icon */}
          <div style={{
            width: 40, height: 40, borderRadius: 10, background: '#1877f2',
            display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
          }}>
            <span style={{ color: '#fff', fontWeight: 900, fontSize: '1.2rem', lineHeight: 1 }}>f</span>
          </div>
          <div>
            <div style={{ fontWeight: 700, fontSize: '.9375rem', color: 'var(--text)' }}>
              {integration.config?.page_name ? `${integration.config.page_name} · ` : 'Page: '}
              <code style={{ background: '#f3f4f6', borderRadius: 4, padding: '1px 6px', fontSize: '.85rem' }}>
                {integration.page_id}
              </code>
            </div>
            <div style={{ fontSize: '.75rem', color: 'var(--text-3)', marginTop: 2 }}>
              {integration.config?.connect_method === 'facebook_login' ? 'Connected with Facebook Login' : 'Connected with a pasted token'}
              {integration.updated_at ? ` · ${new Date(integration.updated_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}` : ''}
            </div>
          </div>
        </div>

        {/* Status badges */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{
            background: integration.status === 'active' ? '#dcfce7' : '#f3f4f6',
            color: integration.status === 'active' ? '#15803d' : '#6b7280',
            border: `1px solid ${integration.status === 'active' ? '#86efac' : '#e5e7eb'}`,
            borderRadius: 999, padding: '.18rem .65rem', fontSize: '.72rem', fontWeight: 600,
            display: 'inline-flex', alignItems: 'center', gap: 4,
          }}>
            <span style={{ fontSize: '.55rem' }}>●</span> {integration.status ?? 'active'}
          </span>

          {isSubscribed ? (
            <span style={{
              background: '#eff6ff', color: '#1d4ed8', border: '1px solid #bfdbfe',
              borderRadius: 999, padding: '.18rem .65rem', fontSize: '.72rem', fontWeight: 600,
              display: 'inline-flex', alignItems: 'center', gap: 4,
            }}>
              <CheckCircle2 size={12} strokeWidth={2.5} /> Webhook active
            </span>
          ) : (
            <span style={{
              background: '#fffbeb', color: '#92400e', border: '1px solid #fde68a',
              borderRadius: 999, padding: '.18rem .65rem', fontSize: '.72rem', fontWeight: 600,
              display: 'inline-flex', alignItems: 'center', gap: 4,
            }}>
              <AlertTriangle size={12} strokeWidth={2.5} /> Needs subscription
            </span>
          )}
        </div>
      </div>

      {/* Test lead result */}
      {testResult && (
        <div style={{
          padding:'.75rem 1rem', borderRadius:8, fontSize:'.8125rem',
          background: testResult.ok ? '#f0fdf4' : '#fff1f0',
          color: testResult.ok ? '#15803d' : '#dc2626',
          border: `1px solid ${testResult.ok ? '#86efac' : '#fca5a5'}`,
          display: 'flex', alignItems: 'flex-start', gap: 6,
        }}>
          {testResult.ok
            ? <CheckCircle2 size={14} strokeWidth={2} style={{ flexShrink: 0, marginTop: 2 }} />
            : <XCircle size={14} strokeWidth={2} style={{ flexShrink: 0, marginTop: 2 }} />}
          <span>{testResult.message}</span>
        </div>
      )}

      {/* Webhook details toggle */}
      {(
        <div style={{ marginBottom: '.85rem' }}>
          <button
            onClick={() => setShowWebhook(v => !v)}
            style={{ background:'none', border:'none', color:'var(--primary,#0a6cc4)', fontSize:'.78rem', fontWeight:600, cursor:'pointer', padding:0 }}>
            {showWebhook ? '▲ Hide webhook details' : '▼ Show webhook details'}
          </button>
          {showWebhook && (
            <div style={{ marginTop:'.75rem', background:'#f8fafc', borderRadius:10, padding:'1rem', border:'1px solid var(--border)' }}>
              <div style={{ fontSize:'.72rem', fontWeight:700, color:'var(--text-3)', textTransform:'uppercase', letterSpacing:'.05em', marginBottom:'.75rem' }}>
                Meta for Developers → your app → Webhooks → <strong>Page</strong> → paste both, Verify and save, then subscribe <code>leadgen</code>
              </div>
              {[
                { label: 'Callback URL', value: metaWebhookUrl || '—' },
                { label: 'Verify Token', value: integration.verify_token || '—' },
              ].map(({ label, value }) => (
                <div key={label} style={{ marginBottom:'.6rem' }}>
                  <div style={{ fontSize:'.72rem', fontWeight:600, color:'var(--text-3)', marginBottom:'.2rem' }}>{label}</div>
                  <div style={{ display:'flex', gap:8, alignItems:'center' }}>
                    <code style={{ flex:1, background:'#fff', border:'1px solid var(--border)', borderRadius:6, padding:'.35rem .7rem', fontSize:'.75rem', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
                      {value}
                    </code>
                    <button onClick={() => copyText(value, label)} className="btn btn-sm btn-ghost" style={{ flexShrink:0, fontSize:'.72rem' }}><Copy size={12} strokeWidth={2} /> Copy</button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Actions */}
      {confirmId === integration.id ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '.6rem .85rem', background: '#fff5f5', borderRadius: 8, border: '1px solid #fca5a5' }}>
          <span style={{ fontSize: '.8125rem', color: '#dc2626', fontWeight: 600 }}>Disconnect this page?</span>
          <button onClick={() => onDisconnect(integration.id)} disabled={disconnecting}
            style={{ marginLeft:'auto', background:'#dc2626', color:'#fff', border:'none', borderRadius:6, padding:'4px 14px', fontSize:'.78rem', cursor:'pointer', fontWeight:600 }}>
            {disconnecting ? 'Disconnecting…' : 'Yes, disconnect'}
          </button>
          <button onClick={() => setConfirmId(null)}
            style={{ background:'var(--bg)', border:'1px solid var(--border)', borderRadius:6, padding:'4px 12px', fontSize:'.78rem', cursor:'pointer' }}>
            Cancel
          </button>
        </div>
      ) : (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {!isSubscribed && (
            <button onClick={() => onSubscribe(integration.id)} disabled={subscribing} className="btn btn-sm btn-primary" style={{ fontSize: '.78rem' }}>
              {subscribing ? 'Subscribing…' : <><Bell size={14} strokeWidth={2} /> Subscribe to Leadgen</>}
            </button>
          )}
          {isSubscribed && (
            <button onClick={handleTestLead} disabled={testing} className="btn btn-sm" style={{ fontSize: '.78rem', background: '#f5f3ff', border: '1px solid #c4b5fd', color: '#0e8f8c', fontWeight: 600 }}>
              {testing ? 'Sending…' : <><FlaskConical size={14} strokeWidth={2} /> Send Test Lead</>}
            </button>
          )}
          <button onClick={() => setConfirmId(integration.id)} className="btn btn-sm" style={{ fontSize: '.78rem', background: '#fff1f0', border: '1px solid #fca5a5', color: '#dc2626' }}>
            Disconnect
          </button>
        </div>
      )}
    </div>
  )
}

// ── Connect Modal ─────────────────────────────────────────────────────────
function ConnectModal({ onClose, onConnected }) {
  const [form, setForm] = useState({ page_id: '', page_access_token: '', default_country_code: '' })
  const [connecting, setConnecting] = useState(false)
  const [result, setResult] = useState(null)
  const [err, setErr] = useState(null)

  async function handleConnect(e) {
    e.preventDefault()
    setConnecting(true); setErr(null); setResult(null)
    try {
      const data = await billingApi.connectIntegration(form)
      setResult(data)
      toast.success('Facebook Page connected!', form.page_id)
      onConnected() // refresh list but keep modal open to show webhook details
    } catch (error) {
      setErr(error.message ?? 'Failed to connect.')
      toast.error('Connection failed', error.message)
    } finally {
      setConnecting(false)
    }
  }

  return (
    <div onClick={onClose} style={{
      position:'fixed', inset:0, background:'rgba(0,0,0,.5)', zIndex:10000,
      display:'flex', alignItems:'center', justifyContent:'center', padding:20, backdropFilter:'blur(3px)',
    }}>
      <div onClick={e=>e.stopPropagation()} style={{
        background:'#fff', borderRadius:18, width:'100%', maxWidth:520,
        maxHeight:'90vh', overflow:'auto', boxShadow:'0 24px 64px rgba(0,0,0,.22)',
      }}>
        {/* Header */}
        <div style={{ padding:'1.25rem 1.5rem', borderBottom:'1px solid var(--border)', display:'flex', alignItems:'center', justifyContent:'space-between' }}>
          <div style={{ display:'flex', alignItems:'center', gap:12 }}>
            <div style={{ width:36, height:36, borderRadius:8, background:'#1877f2', display:'flex', alignItems:'center', justifyContent:'center' }}>
              <span style={{ color:'#fff', fontWeight:900, fontSize:'1.1rem' }}>f</span>
            </div>
            <div>
              <h3 style={{ fontWeight:800, fontSize:'.9375rem', margin:0 }}>Connect Facebook Page</h3>
              <p style={{ margin:0, fontSize:'.75rem', color:'var(--text-3)' }}>Import leads from Facebook Lead Ad forms</p>
            </div>
          </div>
          <button onClick={onClose} style={{ background:'none', border:'none', cursor:'pointer', color:'var(--text-3)', display:'inline-flex', alignItems:'center' }}><X size={18} strokeWidth={2} /></button>
        </div>

        <form onSubmit={handleConnect} style={{ padding:'1.5rem' }}>
          {err && <div className="field-error" style={{ marginBottom:'1rem', fontSize:'.875rem' }}>{err}</div>}

          <div className="form-group" style={{ marginBottom:'1.25rem' }}>
            <label className="form-label" style={{ fontWeight:700 }}>Facebook Page ID *</label>
            <input className="form-input" value={form.page_id} onChange={e=>setForm(p=>({...p,page_id:e.target.value}))}
              placeholder="e.g. 123456789012345" required autoFocus />
            <div className="field-hint">Find it in Facebook Page Settings → About</div>
          </div>

          <div className="form-group" style={{ marginBottom:'1.25rem' }}>
            <label className="form-label" style={{ fontWeight:700 }}>Page Access Token *</label>
            <input className="form-input" type="password" value={form.page_access_token}
              onChange={e=>setForm(p=>({...p,page_access_token:e.target.value}))}
              placeholder="EAAxxxxxxxxxxxxx" required />
            <div className="field-hint">From Meta for Developers → Your App → Access Tokens</div>
          </div>

          <div className="form-group" style={{ marginBottom:'1.5rem' }}>
            <label className="form-label" style={{ fontWeight:700 }}>Default Country Code <span style={{ fontWeight:400, color:'var(--text-3)' }}>(optional)</span></label>
            <input className="form-input" value={form.default_country_code}
              onChange={e=>setForm(p=>({...p,default_country_code:e.target.value}))}
              placeholder="+91" style={{ maxWidth:120 }} />
            <div className="field-hint">Applied to phone numbers without country code</div>
          </div>

          {/* Success result */}
          {result && (result.webhook_url || result.verify_token) && (
            <div style={{ background:'#f0fdf4', border:'1px solid #86efac', borderRadius:12, padding:'1.25rem', marginBottom:'1.25rem' }}>
              <div style={{ fontWeight:700, color:'#15803d', marginBottom:'.75rem', display:'flex', alignItems:'center', gap:8 }}>
                <CheckCircle2 size={15} strokeWidth={2} style={{ flexShrink:0 }} /> Page connected! Now subscribe to leadgen webhook:
              </div>
              {result.webhook_url && (
                <div style={{ marginBottom:'.75rem' }}>
                  <div style={{ fontSize:'.72rem', fontWeight:700, color:'#15803d', marginBottom:'.3rem', textTransform:'uppercase', letterSpacing:'.05em' }}>Callback URL</div>
                  <div style={{ display:'flex', gap:8, alignItems:'center' }}>
                    <code style={{ flex:1, background:'#fff', border:'1px solid #86efac', borderRadius:6, padding:'.4rem .75rem', fontSize:'.78rem', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
                      {result.webhook_url}
                    </code>
                    <button type="button" onClick={() => copyText(result.webhook_url, 'Callback URL')} className="btn btn-sm" style={{ background:'#16a34a', color:'#fff', border:'none', flexShrink:0 }}><Copy size={13} strokeWidth={2} /> Copy</button>
                  </div>
                </div>
              )}
              {result.verify_token && (
                <div>
                  <div style={{ fontSize:'.72rem', fontWeight:700, color:'#15803d', marginBottom:'.3rem', textTransform:'uppercase', letterSpacing:'.05em' }}>Verify Token</div>
                  <div style={{ display:'flex', gap:8, alignItems:'center' }}>
                    <code style={{ flex:1, background:'#fff', border:'1px solid #86efac', borderRadius:6, padding:'.4rem .75rem', fontSize:'.78rem', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
                      {result.verify_token}
                    </code>
                    <button type="button" onClick={() => copyText(result.verify_token, 'Verify Token')} className="btn btn-sm" style={{ background:'#16a34a', color:'#fff', border:'none', flexShrink:0 }}><Copy size={13} strokeWidth={2} /> Copy</button>
                  </div>
                </div>
              )}
            </div>
          )}

          <div style={{ display:'flex', gap:10 }}>
            <button type="button" onClick={onClose} className="btn btn-ghost" style={{ flex:1 }}>Cancel</button>
            <button type="submit" disabled={connecting} className="btn btn-primary" style={{ flex:2, justifyContent:'center', background:'#1877f2' }}>
              {connecting ? 'Connecting…' : 'Connect Facebook Page'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ── Coming soon integration card ───────────────────────────────────────────
function ComingSoonCard({ icon, name, description }) {
  return (
    <div style={{
      border: '1px dashed var(--border)', borderRadius: 12, padding: '1.25rem',
      display: 'flex', alignItems: 'center', gap: 12, opacity: .6,
    }}>
      <span style={{ flexShrink: 0, display: 'inline-flex', alignItems: 'center', color: 'var(--text-3)' }}>{icon}</span>
      <div style={{ flex: 1 }}>
        <div style={{ fontWeight: 700, fontSize: '.875rem', color: 'var(--text)' }}>{name}</div>
        <div style={{ fontSize: '.75rem', color: 'var(--text-3)', marginTop: 2 }}>{description}</div>
      </div>
      <span style={{ background:'#f3f4f6', color:'#6b7280', borderRadius:999, padding:'.15rem .6rem', fontSize:'.7rem', fontWeight:700, flexShrink:0 }}>
        Coming Soon
      </span>
    </div>
  )
}

// ── Page picker (after returning from Facebook) ────────────────────────
function PagePicker({ pages, picking, filter, setFilter, onPick, onClose }) {
  const q     = filter.trim().toLowerCase()
  const shown = q ? pages.filter(p => `${p.page_name} ${p.page_id}`.toLowerCase().includes(q)) : pages
  return (
    <div onClick={onClose} style={{
      position:'fixed', inset:0, background:'rgba(0,0,0,.5)', zIndex:10000,
      display:'flex', alignItems:'center', justifyContent:'center', padding:20, backdropFilter:'blur(3px)',
    }}>
      <div onClick={e=>e.stopPropagation()} style={{
        background:'#fff', borderRadius:18, width:'100%', maxWidth:520,
        maxHeight:'90vh', display:'flex', flexDirection:'column', boxShadow:'0 24px 64px rgba(0,0,0,.22)',
      }}>
        <div style={{ padding:'1.25rem 1.5rem', borderBottom:'1px solid var(--border)', display:'flex', alignItems:'center', justifyContent:'space-between' }}>
          <div>
            <h3 style={{ fontWeight:800, fontSize:'.9375rem', margin:0 }}>Which Page runs your Lead Ads?</h3>
            <p style={{ margin:0, fontSize:'.75rem', color:'var(--text-3)' }}>
              {pages.length} {pages.length === 1 ? 'Page' : 'Pages'} on this Facebook account
            </p>
          </div>
          <button onClick={onClose} style={{ background:'none', border:'none', cursor:'pointer', color:'var(--text-3)', display:'inline-flex' }}><X size={18} strokeWidth={2} /></button>
        </div>
        <div style={{ padding:'1rem 1.5rem', overflow:'auto' }}>
          {pages.length > 6 && (
            <div style={{ position:'relative', marginBottom:'.75rem' }}>
              <Search size={14} style={{ position:'absolute', left:10, top:11, color:'var(--text-3)' }} />
              <input className="form-input" value={filter} onChange={e=>setFilter(e.target.value)} placeholder="Search Pages…" style={{ paddingLeft:30 }} />
            </div>
          )}
          {pages.length === 0 && (
            <div style={{ fontSize:'.8125rem', color:'var(--text-3)' }}>
              No Pages came back. Make sure you ticked the Page on Facebook's "what do you want to allow" step, then try again.
            </div>
          )}
          {shown.map(p => (
            <div key={p.page_id} style={{ display:'flex', alignItems:'center', gap:12, padding:'.6rem 0', borderBottom:'1px solid var(--border)' }}>
              <div style={{ flex:1, minWidth:0 }}>
                <div style={{ fontWeight:700, fontSize:'.875rem', whiteSpace:'nowrap', overflow:'hidden', textOverflow:'ellipsis' }}>{p.page_name}</div>
                <div style={{ fontSize:'.72rem', color:'var(--text-3)' }}>{p.page_id}{p.ig_username ? ` · @${p.ig_username}` : ''}</div>
              </div>
              <button className="btn btn-sm btn-primary" disabled={!!picking} onClick={() => onPick(p)} style={{ background:'#1877f2', flexShrink:0 }}>
                {picking === p.page_id ? 'Connecting…' : 'Use for Lead Ads'}
              </button>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

// Meta's own wording when the token predates a permission. Only a reconnect
// (a fresh token) fixes it, so the UI should say so rather than "try again".
function looksLikePermissionError(msg = '') {
  return /permission|#200|#10\b|OAuthException|pages_manage_metadata|leads_retrieval/i.test(msg)
}

// ── Main page ─────────────────────────────────────────────────────────────
export default function IntegrationsPage() {
  const [integrations, setIntegrations] = useState([])
  // From the API (app.baseURL) — Meta must be able to reach this URL.
  const [metaWebhookUrl, setMetaWebhookUrl] = useState('')
  const [connectedPages, setConnectedPages] = useState([]) // Social Planner Pages (Facebook Login)
  const [fbLogin, setFbLogin]           = useState({ configured: false, login_mode: 'classic' })
  const [loading, setLoading]           = useState(true)
  const [showModal, setShowModal]       = useState(false)
  const [confirmId, setConfirmId]       = useState(null)
  const [actionLoading, setActionLoading] = useState({})

  // Facebook Login round-trip
  const [starting, setStarting]         = useState(false)
  const [pickerState, setPickerState]   = useState('')
  const [pickerPages, setPickerPages]   = useState([])
  const [pickerFilter, setPickerFilter] = useState('')
  const [picking, setPicking]           = useState('')
  const [linking, setLinking]           = useState(0)      // social_account_id being linked
  const [linkResult, setLinkResult]     = useState(null)   // last link-page response
  const [searchParams, setSearchParams] = useSearchParams()

  useEffect(() => { load() }, [])

  // Coming back from Meta: the backend put either a state or an error on the
  // URL. Consume it, strip it (a refresh must not replay the flow), and open
  // the Page picker.
  useEffect(() => {
    const err   = searchParams.get('social_oauth_error')
    const state = searchParams.get('social_oauth_state')
    if (!err && !state) return

    setSearchParams({}, { replace: true })

    if (err) { toast.error('Facebook connection failed', err); return }

    setPickerState(state)
    socialApi.oauthPages(state)
      .then(r => {
        const pages = r.data ?? []
        setPickerPages(pages)
        if (pages.length === 0) toast.error('No Pages found on that Facebook account')
      })
      .catch(e => { setPickerState(''); toast.error('Could not list your Pages', e.message) })
  }, [searchParams, setSearchParams])

  async function load() {
    setLoading(true)
    try {
      const data = await billingApi.listIntegrations()
      setIntegrations(data.integrations ?? data.data ?? data ?? [])
      if (data.webhook_url) setMetaWebhookUrl(data.webhook_url)
      setConnectedPages(data.connected_pages ?? [])
      setFbLogin(data.facebook_login ?? { configured: false, login_mode: 'classic' })
    } catch (err) {
      toast.error('Failed to load integrations', err.message)
    } finally {
      setLoading(false)
    }
  }

  // Hand the browser to Meta's consent dialog; we come back with ?social_oauth_state.
  async function handleOauthStart() {
    setStarting(true)
    try {
      const r = await socialApi.oauthStart('integrations')
      window.location.href = r.data.auth_url
    } catch (err) {
      toast.error('Could not start Facebook login', err.message)
      setStarting(false)
    }
  }

  function applyLinkResult(data) {
    setLinkResult(data)
    if (data.subscribed) {
      toast.success('Lead Ads connected!', `${data.data?.config?.page_name ?? data.data?.page_id} is subscribed to leadgen`)
    } else {
      toast.error('Page linked, but the leadgen subscription was refused', data.subscribe_error ?? '')
    }
  }

  async function handlePickPage(page) {
    setPicking(page.page_id)
    try {
      const data = await billingApi.linkMetaPage({ state: pickerState, page_id: page.page_id })
      setPickerState(''); setPickerPages([]); setPickerFilter('')
      applyLinkResult(data)
      await load()
    } catch (err) {
      toast.error('Could not connect that Page', err.message)
    }
    setPicking('')
  }

  async function handleUseConnected(account) {
    setLinking(account.id)
    try {
      const data = await billingApi.linkMetaPage({ social_account_id: account.id })
      applyLinkResult(data)
      await load()
    } catch (err) {
      toast.error('Could not link that Page', err.message)
    }
    setLinking(0)
  }

  async function handleSubscribe(id) {
    setActionLoading(p => ({ ...p, [`sub_${id}`]: true }))
    try {
      await billingApi.subscribeIntegration(id)
      toast.success('Webhook subscribed!', 'Leads will now flow automatically')
      setLinkResult(null)
      await load()
    } catch (err) {
      toast.error('Subscribe failed', err.message)
      setLinkResult({ subscribed: false, subscribe_error: err.message })
    } finally {
      setActionLoading(p => ({ ...p, [`sub_${id}`]: false }))
    }
  }

  async function handleDisconnect(id) {
    setActionLoading(p => ({ ...p, [`del_${id}`]: true }))
    try {
      await billingApi.deleteIntegration(id)
      toast.success('Page disconnected')
      setConfirmId(null)
      setLinkResult(null)
      await load()
    } catch (err) {
      toast.error('Disconnect failed', err.message)
    } finally {
      setActionLoading(p => ({ ...p, [`del_${id}`]: false }))
    }
  }

  const linkedPageIds = new Set(integrations.map(i => String(i.page_id)))
  const connectBtn = fbLogin.configured
    ? (
      <button className="btn btn-primary" onClick={handleOauthStart} disabled={starting} style={{ background:'#1877f2', flexShrink:0 }}>
        <span style={{ fontWeight:900, marginRight:6 }}>f</span>{starting ? 'Opening Facebook…' : 'Connect with Facebook'}
      </button>
    ) : (
      <button className="btn btn-primary" onClick={() => setShowModal(true)} style={{ background:'#1877f2', flexShrink:0 }}>
        + Connect Facebook Page
      </button>
    )

  return (
    <div className="page">
      {/* ── Header ─────────────────────────────────────────────────── */}
      <div style={{ marginBottom: '1.5rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>Integrations</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>
          Connect third-party platforms to automatically import leads.
        </p>
      </div>

      {/* ── Meta Lead Ads section ─────────────────────────────────── */}
      <div style={{ marginBottom:'2rem' }}>
        {/* Section header */}
        <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:'1rem', gap:12, flexWrap:'wrap' }}>
          <div style={{ display:'flex', alignItems:'center', gap:12 }}>
            <div style={{ width:42, height:42, borderRadius:10, background:'#1877f2', display:'flex', alignItems:'center', justifyContent:'center', boxShadow:'0 2px 8px #1877f240' }}>
              <span style={{ color:'#fff', fontWeight:900, fontSize:'1.3rem', lineHeight:1 }}>f</span>
            </div>
            <div>
              <div style={{ fontWeight:800, fontSize:'1rem', color:'var(--text)' }}>Meta Lead Ads</div>
              <div style={{ fontSize:'.78rem', color:'var(--text-3)' }}>Every Facebook & Instagram Lead Ad form submission becomes a contact the moment it lands</div>
            </div>
          </div>
          <div style={{ display:'flex', alignItems:'center', gap:8 }}>
            {connectBtn}
            {fbLogin.configured && (
              <button className="btn btn-ghost btn-sm" onClick={() => setShowModal(true)} style={{ fontSize:'.75rem' }}>
                Paste a token instead
              </button>
            )}
          </div>
        </div>

        {/* How it works / what Meta needs */}
        <div style={{
          background:'#fffbeb', border:'1px solid #fde68a', borderRadius:12, padding:'1rem 1.25rem',
          display:'flex', gap:12, marginBottom:'1.25rem',
        }}>
          <AlertTriangle size={16} strokeWidth={2} style={{ flexShrink:0, color:'#b45309', marginTop:2 }} />
          <div style={{ fontSize:'.8125rem', lineHeight:1.5 }}>
            <strong>Two permissions make this work:</strong> <code>leads_retrieval</code> (read the form answers) and <code>pages_manage_metadata</code> (subscribe the Page to leadgen).
            {fbLogin.login_mode === 'business'
              ? <> They must be ticked in the app's <em>Facebook Login for Business</em> configuration — and a Page connected before they were added has to be <strong>reconnected</strong> to pick them up.</>
              : <> They are requested automatically when you connect with Facebook.</>}
            {' '}Your own Pages work straight away while the app is in development; other businesses' Pages need Meta App Review.
            <span style={{ color:'var(--text-3)', marginLeft:4 }}>See <code>/docs/META_APP_REVIEW.md</code>.</span>
          </div>
        </div>

        {/* Result of the last link attempt */}
        {linkResult && !linkResult.subscribed && (
          <div style={{ background:'#fff1f0', border:'1px solid #fca5a5', borderRadius:12, padding:'1rem 1.25rem', marginBottom:'1.25rem', fontSize:'.8125rem' }}>
            <div style={{ fontWeight:700, color:'#dc2626', marginBottom:4, display:'flex', alignItems:'center', gap:6 }}>
              <XCircle size={15} strokeWidth={2} /> The Page is linked, but Meta refused the leadgen subscription
            </div>
            <div style={{ color:'#7f1d1d', marginBottom:'.6rem' }}>{linkResult.subscribe_error}</div>
            {looksLikePermissionError(linkResult.subscribe_error) && fbLogin.configured && (
              <div style={{ display:'flex', alignItems:'center', gap:10, flexWrap:'wrap' }}>
                <span style={{ color:'#7f1d1d' }}>This token was issued before the Lead Ads permissions were granted. Reconnect to get a fresh one:</span>
                <button className="btn btn-sm btn-primary" onClick={handleOauthStart} disabled={starting} style={{ background:'#1877f2' }}>
                  <RefreshCw size={13} strokeWidth={2} /> {starting ? 'Opening Facebook…' : 'Reconnect with Facebook'}
                </button>
              </div>
            )}
          </div>
        )}

        {/* Pages the Social Planner already holds a token for */}
        {!loading && connectedPages.length > 0 && (
          <div style={{ background:'#f8fafc', border:'1px solid var(--border)', borderRadius:12, padding:'1rem 1.25rem', marginBottom:'1.25rem' }}>
            <div style={{ fontSize:'.72rem', fontWeight:700, color:'var(--text-3)', textTransform:'uppercase', letterSpacing:'.05em', marginBottom:'.25rem' }}>
              Pages already connected with Facebook
            </div>
            <div style={{ fontSize:'.78rem', color:'var(--text-3)', marginBottom:'.6rem' }}>
              Lead Ads must be linked to the Page the ads actually run on. If that is a different Page from the one your Social Planner posts to, use <strong>Connect with Facebook</strong> above and pick it there — don't reuse the one below.
            </div>
            {connectedPages.map(a => {
              const linked = linkedPageIds.has(String(a.page_id))
              return (
                <div key={a.id} style={{ display:'flex', alignItems:'center', gap:12, padding:'.45rem 0' }}>
                  <div style={{ flex:1, minWidth:0 }}>
                    <span style={{ fontWeight:700, fontSize:'.875rem' }}>{a.page_name || a.page_id}</span>
                    <span style={{ fontSize:'.72rem', color:'var(--text-3)', marginLeft:8 }}>{a.page_id}</span>
                    {a.status !== 'active' && <span style={{ fontSize:'.72rem', color:'#b45309', marginLeft:8 }}>needs reconnecting</span>}
                  </div>
                  <button
                    className={`btn btn-sm ${linked ? 'btn-ghost' : 'btn-primary'}`}
                    disabled={linking === a.id || a.status !== 'active'}
                    onClick={() => handleUseConnected(a)}
                    style={linked ? { fontSize:'.75rem' } : { background:'#1877f2', fontSize:'.75rem' }}>
                    <Link2 size={13} strokeWidth={2} /> {linking === a.id ? 'Linking…' : linked ? 'Linked · re-link' : 'Use for Lead Ads'}
                  </button>
                </div>
              )
            })}
          </div>
        )}

        {/* Connected pages */}
        {loading ? (
          <div style={{ display:'flex', flexDirection:'column', gap:'.75rem' }}>
            {[1].map(i => <div key={i} style={{ height:90, borderRadius:12, background:'#f3f4f6', animation:'lp-pulse 1.2s infinite' }} />)}
          </div>
        ) : integrations.length === 0 ? (
          <div style={{
            textAlign:'center', padding:'3rem 2rem',
            background:'#fff', borderRadius:12, border:'2px dashed var(--border)',
          }}>
            <div style={{ fontSize:'3rem', marginBottom:'.75rem' }}>
              <span style={{ display:'inline-block', width:56, height:56, borderRadius:14, background:'#1877f2', lineHeight:'56px', color:'#fff', fontWeight:900, fontSize:'1.5rem' }}>f</span>
            </div>
            <h3 style={{ fontWeight:800, fontSize:'1rem', marginBottom:'.35rem' }}>No Facebook Page connected for Lead Ads</h3>
            <p style={{ color:'var(--text-3)', fontSize:'.8125rem', maxWidth:360, margin:'0 auto .875rem' }}>
              Connect the Page your Lead Ads run on. New leads land in Contacts within a minute and can trigger a WhatsApp flow.
            </p>
            {connectBtn}
          </div>
        ) : (
          integrations.map(integration => (
            <IntegrationRow
              key={integration.id}
              integration={integration}
              metaWebhookUrl={metaWebhookUrl}
              onSubscribe={handleSubscribe}
              onDisconnect={handleDisconnect}
              subscribing={actionLoading[`sub_${integration.id}`]}
              disconnecting={actionLoading[`del_${integration.id}`]}
              confirmId={confirmId}
              setConfirmId={setConfirmId}
            />
          ))
        )}
      </div>

      {/* ── Meta Ads spend ─────────────────────────────────────────── */}
      <MetaAdsSection fbConfigured={fbLogin.configured} />

      {/* ── Google Ads Lead Forms section ─────────────────────────── */}
      <GoogleLeadFormsSection />

      <ImapInboundSection />

      {/* ── Coming soon integrations ─────────────────────────────── */}
      <div>
        <div style={{ fontSize:'.75rem', fontWeight:700, color:'var(--text-3)', textTransform:'uppercase', letterSpacing:'.1em', marginBottom:'.85rem' }}>
          More Integrations — Coming Soon
        </div>
        <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fill, minmax(280px, 1fr))', gap:'.75rem' }}>
          <ComingSoonCard icon={<Building2 size={26} strokeWidth={1.6} />} name="HubSpot" description="Two-way sync contacts with HubSpot CRM" />
          <ComingSoonCard icon={<Cloud size={26} strokeWidth={1.6} />} name="Salesforce" description="Sync leads to Salesforce opportunities" />
          <ComingSoonCard icon={<Target size={26} strokeWidth={1.6} />} name="ActiveCampaign" description="Trigger automations in ActiveCampaign" />
          <ComingSoonCard icon={<Mail size={26} strokeWidth={1.6} />} name="Mailchimp" description="Add contacts to Mailchimp audiences" />
          <ComingSoonCard icon={<Zap size={26} strokeWidth={1.6} />} name="Zapier" description="Connect to 5,000+ apps via Zapier" />
        </div>
      </div>

      {showModal && <ConnectModal onClose={() => setShowModal(false)} onConnected={load} />}

      {pickerState && (
        <PagePicker
          pages={pickerPages} picking={picking} filter={pickerFilter} setFilter={setPickerFilter}
          onPick={handlePickPage}
          onClose={() => { setPickerState(''); setPickerPages([]); setPickerFilter('') }}
        />
      )}
    </div>
  )
}
