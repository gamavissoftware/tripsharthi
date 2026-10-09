import { useState, useEffect } from 'react'
import { Clipboard, FlaskConical, X } from 'lucide-react'
import { billing as billingApi } from '../api/billing'
import { toast } from '../components/Toast'

async function copyText(text, label) {
  try { await navigator.clipboard.writeText(text); toast.success(`${label} copied!`) }
  catch { toast.error('Copy failed') }
}

const GBLUE = '#4285F4'

// ── A copyable field (label + monospace value + copy button) ────────────────
function CopyField({ label, value }) {
  return (
    <div style={{ marginBottom: '.6rem' }}>
      <div style={{ fontSize: '.72rem', fontWeight: 600, color: 'var(--text-3)', marginBottom: '.2rem' }}>{label}</div>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <code style={{ flex: 1, background: '#fff', border: '1px solid var(--border)', borderRadius: 6, padding: '.35rem .7rem', fontSize: '.75rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {value}
        </code>
        <button onClick={() => copyText(value, label)} className="btn btn-sm btn-ghost" style={{ flexShrink: 0, fontSize: '.72rem' }}><Clipboard size={13} strokeWidth={2} /> Copy</button>
      </div>
    </div>
  )
}

// ── Connected Google Lead Form card ─────────────────────────────────────────
function GoogleRow({ integration, webhookUrl, onDisconnect, disconnecting, confirmId, setConfirmId }) {
  const [showWebhook, setShowWebhook] = useState(false)
  const [testing, setTesting] = useState(false)
  const [testResult, setTestResult] = useState(null)
  const label = integration.config?.label || 'Google Lead Form'

  async function handleTestLead() {
    setTesting(true); setTestResult(null)
    try {
      const token = localStorage.getItem('tp_token')
      const resp = await fetch(`/api/v1/google-integrations/${integration.id}/test`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      })
      const data = await resp.json().catch(() => ({}))
      if (data.success) {
        setTestResult({ ok: true, message: `Test lead created! Contact ID: ${data.contact_id} — check the Contacts page.` })
        toast.success('Test lead created!', 'New contact added from Google Lead Forms')
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
    <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: '1.25rem', background: '#fff', marginBottom: '.75rem' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '.85rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 40, height: 40, borderRadius: 10, background: GBLUE, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
            <span style={{ color: '#fff', fontWeight: 900, fontSize: '1.2rem', lineHeight: 1 }}>G</span>
          </div>
          <div>
            <div style={{ fontWeight: 700, fontSize: '.9375rem', color: 'var(--text)' }}>{label}</div>
            <div style={{ fontSize: '.75rem', color: 'var(--text-3)', marginTop: 2 }}>
              Connected {integration.created_at ? new Date(integration.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : ''}
            </div>
          </div>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ background: '#dcfce7', color: '#15803d', border: '1px solid #86efac', borderRadius: 999, padding: '.18rem .65rem', fontSize: '.72rem', fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: 4 }}>
            <span style={{ fontSize: '.55rem' }}>●</span> Webhook ready
          </span>
        </div>
      </div>

      {/* Test result */}
      {testResult && (
        <div style={{ padding: '.75rem 1rem', borderRadius: 8, fontSize: '.8125rem', marginBottom: '.75rem', background: testResult.ok ? '#f0fdf4' : '#fff1f0', color: testResult.ok ? '#15803d' : '#dc2626', border: `1px solid ${testResult.ok ? '#86efac' : '#fca5a5'}` }}>
          {testResult.message}
        </div>
      )}

      {/* Webhook details — always available, since the user must paste them into Google Ads */}
      <div style={{ marginBottom: '.85rem' }}>
        <button onClick={() => setShowWebhook(v => !v)} style={{ background: 'none', border: 'none', color: GBLUE, fontSize: '.78rem', fontWeight: 600, cursor: 'pointer', padding: 0 }}>
          {showWebhook ? '▲ Hide webhook details' : '▼ Show webhook URL & key'}
        </button>
        {showWebhook && (
          <div style={{ marginTop: '.75rem', background: '#f8fafc', borderRadius: 10, padding: '1rem', border: '1px solid var(--border)' }}>
            <div style={{ fontSize: '.72rem', fontWeight: 700, color: 'var(--text-3)', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: '.75rem' }}>
              Paste into Google Ads → Lead form → “Deliver new leads” → Webhook
            </div>
            <CopyField label="Webhook URL" value={webhookUrl} />
            <CopyField label="Key" value={integration.google_key || integration.verify_token || '—'} />
          </div>
        )}
      </div>

      {/* Actions */}
      {confirmId === integration.id ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '.6rem .85rem', background: '#fff5f5', borderRadius: 8, border: '1px solid #fca5a5' }}>
          <span style={{ fontSize: '.8125rem', color: '#dc2626', fontWeight: 600 }}>Disconnect this lead form?</span>
          <button onClick={() => onDisconnect(integration.id)} disabled={disconnecting} style={{ marginLeft: 'auto', background: '#dc2626', color: '#fff', border: 'none', borderRadius: 6, padding: '4px 14px', fontSize: '.78rem', cursor: 'pointer', fontWeight: 600 }}>
            {disconnecting ? 'Disconnecting…' : 'Yes, disconnect'}
          </button>
          <button onClick={() => setConfirmId(null)} style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 6, padding: '4px 12px', fontSize: '.78rem', cursor: 'pointer' }}>Cancel</button>
        </div>
      ) : (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button onClick={handleTestLead} disabled={testing} className="btn btn-sm" style={{ fontSize: '.78rem', background: '#eff6ff', border: `1px solid #bfdbfe`, color: GBLUE, fontWeight: 600 }}>
            {testing ? 'Sending…' : <><FlaskConical size={13} strokeWidth={2} /> Send Test Lead</>}
          </button>
          <button onClick={() => setConfirmId(integration.id)} className="btn btn-sm" style={{ fontSize: '.78rem', background: '#fff1f0', border: '1px solid #fca5a5', color: '#dc2626' }}>
            Disconnect
          </button>
        </div>
      )}
    </div>
  )
}

// ── Connect modal ───────────────────────────────────────────────────────────
function ConnectModal({ onClose, onConnected, webhookUrl }) {
  const [form, setForm] = useState({ label: '', default_country_code: '' })
  const [connecting, setConnecting] = useState(false)
  const [result, setResult] = useState(null)
  const [err, setErr] = useState(null)

  async function handleConnect(e) {
    e.preventDefault()
    setConnecting(true); setErr(null); setResult(null)
    try {
      const data = await billingApi.connectGoogleIntegration(form)
      setResult(data)
      toast.success('Google Lead Form connected!', 'Now paste the webhook into Google Ads')
      onConnected()
    } catch (error) {
      setErr(error.message ?? 'Failed to connect.')
      toast.error('Connection failed', error.message)
    } finally {
      setConnecting(false)
    }
  }

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <div onClick={e => e.stopPropagation()} style={{ background: '#fff', borderRadius: 18, width: '100%', maxWidth: 540, maxHeight: '90vh', overflow: 'auto', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }}>
        <div style={{ padding: '1.25rem 1.5rem', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <div style={{ width: 36, height: 36, borderRadius: 8, background: GBLUE, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <span style={{ color: '#fff', fontWeight: 900, fontSize: '1.1rem' }}>G</span>
            </div>
            <div>
              <h3 style={{ fontWeight: 800, fontSize: '.9375rem', margin: 0 }}>Connect Google Lead Form</h3>
              <p style={{ margin: 0, fontSize: '.75rem', color: 'var(--text-3)' }}>Import leads from Google Ads lead form assets</p>
            </div>
          </div>
          <button onClick={onClose} style={{ background: 'none', border: 'none', display: 'flex', alignItems: 'center', cursor: 'pointer', color: 'var(--text-3)' }}><X size={18} strokeWidth={2} /></button>
        </div>

        <form onSubmit={handleConnect} style={{ padding: '1.5rem' }}>
          {err && <div className="field-error" style={{ marginBottom: '1rem', fontSize: '.875rem' }}>{err}</div>}

          {!result && (
            <>
              <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 12, padding: '1rem 1.25rem', marginBottom: '1.25rem', fontSize: '.8125rem', color: '#1e40af' }}>
                Google pushes leads straight to TripSarthi — no Google login needed here. We’ll generate a secret <strong>Key</strong>; paste it plus the webhook URL into your Google Ads lead form, then click “Send test data”.
              </div>

              <div className="form-group" style={{ marginBottom: '1.25rem' }}>
                <label className="form-label" style={{ fontWeight: 700 }}>Label <span style={{ fontWeight: 400, color: 'var(--text-3)' }}>(optional)</span></label>
                <input className="form-input" value={form.label} onChange={e => setForm(p => ({ ...p, label: e.target.value }))} placeholder="e.g. Spring Campaign Form" autoFocus />
                <div className="field-hint">Helps you tell multiple forms apart</div>
              </div>

              <div className="form-group" style={{ marginBottom: '1.5rem' }}>
                <label className="form-label" style={{ fontWeight: 700 }}>Default Country Code <span style={{ fontWeight: 400, color: 'var(--text-3)' }}>(optional)</span></label>
                <input className="form-input" value={form.default_country_code} onChange={e => setForm(p => ({ ...p, default_country_code: e.target.value }))} placeholder="+91" style={{ maxWidth: 120 }} />
                <div className="field-hint">Applied to phone numbers without a country code</div>
              </div>
            </>
          )}

          {/* Success result — show webhook URL + key to paste into Google Ads */}
          {result && (result.google_key || result.webhook_url) && (
            <div style={{ background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 12, padding: '1.25rem', marginBottom: '1.25rem' }}>
              <div style={{ fontWeight: 700, color: '#15803d', marginBottom: '.75rem', display: 'flex', alignItems: 'center', gap: 8 }}>
                Connected! Paste these into Google Ads:
              </div>
              <CopyField label="Webhook URL" value={result.webhook_url || webhookUrl} />
              <CopyField label="Key" value={result.google_key} />
              <div style={{ fontSize: '.75rem', color: '#15803d', marginTop: '.4rem' }}>
                Google Ads → your Lead form → “Deliver new leads” → Webhook integration → paste both → “Send test data”.
              </div>
            </div>
          )}

          <div style={{ display: 'flex', gap: 10 }}>
            <button type="button" onClick={onClose} className="btn btn-ghost" style={{ flex: 1 }}>{result ? 'Done' : 'Cancel'}</button>
            {!result && (
              <button type="submit" disabled={connecting} className="btn btn-primary" style={{ flex: 2, justifyContent: 'center', background: GBLUE }}>
                {connecting ? 'Connecting…' : 'Generate Webhook'}
              </button>
            )}
          </div>
        </form>
      </div>
    </div>
  )
}

// ── Section ─────────────────────────────────────────────────────────────────
export default function GoogleLeadFormsSection() {
  const [integrations, setIntegrations] = useState([])
  const [webhookUrl, setWebhookUrl] = useState('')
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [confirmId, setConfirmId] = useState(null)
  const [actionLoading, setActionLoading] = useState({})

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const data = await billingApi.listGoogleIntegrations()
      setIntegrations(data.data ?? [])
      setWebhookUrl(data.webhook_url ?? `${window.location.origin}/webhooks/google-leads`)
    } catch (err) {
      toast.error('Failed to load Google integrations', err.message)
    } finally {
      setLoading(false)
    }
  }

  async function handleDisconnect(id) {
    setActionLoading(p => ({ ...p, [`del_${id}`]: true }))
    try {
      await billingApi.deleteGoogleIntegration(id)
      toast.success('Lead form disconnected')
      setConfirmId(null)
      await load()
    } catch (err) {
      toast.error('Disconnect failed', err.message)
    } finally {
      setActionLoading(p => ({ ...p, [`del_${id}`]: false }))
    }
  }

  return (
    <div style={{ marginBottom: '2rem' }}>
      {/* Section header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 42, height: 42, borderRadius: 10, background: GBLUE, display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: `0 2px 8px ${GBLUE}40` }}>
            <span style={{ color: '#fff', fontWeight: 900, fontSize: '1.3rem', lineHeight: 1 }}>G</span>
          </div>
          <div>
            <div style={{ fontWeight: 800, fontSize: '1rem', color: 'var(--text)' }}>Google Ads Lead Forms</div>
            <div style={{ fontSize: '.78rem', color: 'var(--text-3)' }}>Import leads from Google Lead Form assets automatically via webhook</div>
          </div>
        </div>
        <button className="btn btn-primary" onClick={() => setShowModal(true)} style={{ background: GBLUE, flexShrink: 0 }}>
          + Connect Lead Form
        </button>
      </div>

      {loading ? (
        <div style={{ height: 90, borderRadius: 12, background: '#f3f4f6', animation: 'lp-pulse 1.2s infinite' }} />
      ) : integrations.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 12, border: '2px dashed var(--border)' }}>
          <div style={{ marginBottom: '.75rem' }}>
            <span style={{ display: 'inline-block', width: 56, height: 56, borderRadius: 14, background: GBLUE, lineHeight: '56px', color: '#fff', fontWeight: 900, fontSize: '1.5rem' }}>G</span>
          </div>
          <h3 style={{ fontWeight: 800, fontSize: '1rem', marginBottom: '.35rem' }}>No Google Lead Forms connected</h3>
          <p style={{ color: 'var(--text-3)', fontSize: '.8125rem', maxWidth: 360, margin: '0 auto .875rem' }}>
            Connect a Google Ads lead form to capture leads into TripSarthi the moment someone submits — no Zapier required.
          </p>
          <button className="btn btn-primary" onClick={() => setShowModal(true)} style={{ background: GBLUE }}>
            + Connect Lead Form
          </button>
        </div>
      ) : (
        integrations.map(integration => (
          <GoogleRow
            key={integration.id}
            integration={integration}
            webhookUrl={webhookUrl}
            onDisconnect={handleDisconnect}
            disconnecting={actionLoading[`del_${integration.id}`]}
            confirmId={confirmId}
            setConfirmId={setConfirmId}
          />
        ))
      )}

      {showModal && (
        <ConnectModal
          webhookUrl={webhookUrl}
          onClose={() => setShowModal(false)}
          onConnected={load}
        />
      )}
    </div>
  )
}
