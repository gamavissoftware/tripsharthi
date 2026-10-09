import { useState, useEffect } from 'react'
import { waba as wabaApi } from '../api/waba'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import { Copy, MessageCircle, CheckCircle2, XCircle, FlaskConical, Settings2, AlertTriangle, Phone } from 'lucide-react'

const PROVIDER_COLORS = {
  meta:      '#25D366',
  wati:      '#08569f',
  aisensy:   '#EF4444',
  '360dialog': '#F59E0B',
  twilio:    '#EF4444',
  custom:    '#6B7280',
}

const QUALITY_CONFIG = {
  green:  { dot: '#16a34a', label: 'High Quality',    bg: '#dcfce7', color: '#15803d' },
  yellow: { dot: '#d97706', label: 'Medium Quality',  bg: '#fef9c3', color: '#a16207' },
  red:    { dot: '#dc2626', label: 'Low Quality',     bg: '#fee2e2', color: '#b91c1c' },
}

const STATUS_STYLE = {
  active:   { bg: '#dcfce7', color: '#15803d' },
  pending:  { bg: '#fef9c3', color: '#a16207' },
  inactive: { bg: '#fee2e2', color: '#b91c1c' },
}

function initials(name) {
  if (!name) return '?'
  return name
    .split(/\s+/)
    .map(w => w[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
}

function trunc(str, n = 10) {
  if (!str) return '—'
  const s = String(str)
  return s.length > n ? s.slice(0, n) + '…' : s
}

async function copyToClipboard(text, label) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(`${label} copied!`)
  } catch {
    try {
      const el = document.createElement('textarea')
      el.value = text
      document.body.appendChild(el)
      el.select()
      document.execCommand('copy')
      document.body.removeChild(el)
      toast.success(`${label} copied!`)
    } catch {
      toast.error('Copy failed')
    }
  }
}

function StatusBadge({ status }) {
  const s = STATUS_STYLE[status] || { bg: '#f1f5f9', color: '#475569' }
  return (
    <span style={{
      display: 'inline-block',
      padding: '2px 10px',
      borderRadius: 999,
      fontSize: '.75rem',
      fontWeight: 600,
      background: s.bg,
      color: s.color,
      textTransform: 'capitalize',
    }}>
      {status || '—'}
    </span>
  )
}

function ProviderBadge({ providerId, providerName }) {
  const color = PROVIDER_COLORS[providerId] || '#6B7280'
  return (
    <span style={{
      display: 'inline-flex',
      alignItems: 'center',
      gap: 5,
      padding: '3px 10px',
      borderRadius: 999,
      fontSize: '.72rem',
      fontWeight: 700,
      background: color + '18',
      color: color,
      border: `1px solid ${color}30`,
      letterSpacing: '.01em',
    }}>
      <span style={{
        display: 'inline-block',
        width: 7,
        height: 7,
        borderRadius: '50%',
        background: color,
        flexShrink: 0,
      }} />
      {providerName || providerId}
    </span>
  )
}

function ProviderCard({ provider, selected, onSelect }) {
  const color = PROVIDER_COLORS[provider.id] || '#6B7280'
  const isSelected = selected === provider.id
  return (
    <button
      type="button"
      onClick={() => onSelect(provider.id)}
      style={{
        display: 'flex',
        alignItems: 'flex-start',
        gap: '1rem',
        padding: '1rem',
        borderRadius: 10,
        border: isSelected ? `2px solid ${color}` : '2px solid #e2e8f0',
        background: isSelected ? color + '0d' : '#fff',
        cursor: 'pointer',
        textAlign: 'left',
        transition: 'border-color .15s, background .15s',
        width: '100%',
      }}
    >
      <div style={{
        flexShrink: 0,
        width: 44,
        height: 44,
        borderRadius: '50%',
        background: color + '22',
        color: color,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontWeight: 700,
        fontSize: '1rem',
        letterSpacing: '-.02em',
      }}>
        {initials(provider.name)}
      </div>
      <div style={{ minWidth: 0 }}>
        <div style={{
          fontWeight: 700,
          fontSize: '.9rem',
          color: isSelected ? color : '#0f172a',
          marginBottom: 2,
        }}>
          {provider.name}
        </div>
        {provider.description && (
          <div style={{
            fontSize: '.775rem',
            color: '#64748b',
            lineHeight: 1.5,
          }}>
            {provider.description}
          </div>
        )}
      </div>
    </button>
  )
}

function DynamicField({ field, value, onChange, isEditing }) {
  const id = `field_${field.key}`

  if (field.type === 'select') {
    return (
      <div className="form-group">
        <label className="form-label" htmlFor={id}>
          {field.label}
          {field.required && <span style={{ color: '#ef4444' }}> *</span>}
        </label>
        <select
          id={id}
          className="form-input"
          value={value || ''}
          onChange={e => onChange(field.key, e.target.value)}
          required={field.required}
          style={{ background: '#fff' }}
        >
          <option value="">— Select —</option>
          {(field.options || []).map(opt => (
            <option key={opt.value ?? opt} value={opt.value ?? opt}>
              {opt.label ?? opt}
            </option>
          ))}
        </select>
        {field.hint && (
          <span style={{ fontSize: '.75rem', color: '#94a3b8', marginTop: 4, display: 'block' }}>
            {field.hint}
          </span>
        )}
      </div>
    )
  }

  return (
    <div className="form-group">
      <label className="form-label" htmlFor={id}>
        {field.label}
        {field.required && <span style={{ color: '#ef4444' }}> *</span>}
        {!field.required && (
          <span style={{ color: '#94a3b8', fontWeight: 400 }}> (optional)</span>
        )}
      </label>
      <input
        id={id}
        className="form-input"
        type={field.type === 'password' ? 'password' : field.type === 'email' ? 'email' : 'text'}
        value={value || ''}
        onChange={e => onChange(field.key, e.target.value)}
        placeholder={field.hint || ''}
        required={field.required && !isEditing}
        autoComplete={field.type === 'password' ? 'off' : undefined}
      />
      {field.type === 'password' && isEditing && (
        <span style={{ fontSize: '.75rem', color: '#94a3b8', marginTop: 4, display: 'block' }}>
          Leave blank to keep existing value.
        </span>
      )}
    </div>
  )
}

function buildSubmitPayload(selectedProvider, form, providerFields) {
  if (selectedProvider === 'meta') {
    return {
      provider: 'meta',
      waba_id: form.waba_id || '',
      phone_number_id: form.phone_number_id || '',
      access_token: form.access_token || '',
      display_name: form.display_name || '',
      business_id: form.business_id || '',
      app_id: form.app_id || '',
    }
  }

  const fields = providerFields[selectedProvider] || []
  const providerConfig = {}
  for (const field of fields) {
    if (field.key !== 'display_name' && form[field.key] !== undefined && form[field.key] !== '') {
      providerConfig[field.key] = form[field.key]
    }
  }

  return {
    provider: selectedProvider,
    display_name: form.display_name || '',
    provider_config: providerConfig,
  }
}

/* ─── Inline copy row (used inside cards) ─────────────────────── */
function WebhookRow({ label, value }) {
  if (!value) return null
  return (
    <div style={{
      display: 'flex',
      alignItems: 'center',
      gap: '1rem',
      padding: '.75rem 1rem',
      background: '#f8fafc',
      border: '1px solid #e2e8f0',
      borderRadius: 8,
    }}>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{
          fontSize: '.68rem',
          fontWeight: 700,
          color: '#94a3b8',
          textTransform: 'uppercase',
          letterSpacing: '.06em',
          marginBottom: 4,
        }}>
          {label}
        </div>
        <code style={{
          fontSize: '.8rem',
          color: '#0f172a',
          wordBreak: 'break-all',
          fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
        }}>
          {value}
        </code>
      </div>
      <button
        type="button"
        onClick={() => copyToClipboard(value, label)}
        style={{
          flexShrink: 0,
          display: 'inline-flex',
          alignItems: 'center',
          gap: 5,
          padding: '5px 12px',
          borderRadius: 6,
          border: '1px solid #e2e8f0',
          background: '#fff',
          color: '#475569',
          fontSize: '.78rem',
          fontWeight: 600,
          cursor: 'pointer',
          whiteSpace: 'nowrap',
        }}
      >
        <Copy size={13} strokeWidth={2} /> Copy
      </button>
    </div>
  )
}

/* ─── Post-connect webhook instructions (amber card) ──────────── */
function ConnectResultCard({ connectResult, selectedProvider, account }) {
  const provider = account?.provider || selectedProvider
  if (!connectResult || connectResult._shown) return null

  return (
    <div style={{
      marginTop: '1.5rem',
      padding: '1.25rem',
      background: '#fffbeb',
      border: '1px solid #fcd34d',
      borderRadius: 10,
    }}>
      {provider === 'meta' && connectResult.webhook_url ? (
        <>
          <div style={{ fontWeight: 700, color: '#92400e', marginBottom: '.75rem', fontSize: '.95rem' }}>
            Next steps: Configure Meta Webhook
          </div>
          <p style={{ fontSize: '.875rem', color: '#78350f', marginBottom: '1rem', lineHeight: 1.6 }}>
            In Meta for Developers, go to your app → WhatsApp → Configuration → Webhook, and enter:
          </p>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '.75rem' }}>
            <WebhookRow label="Callback URL" value={connectResult.webhook_url} />
            <WebhookRow label="Verify Token" value={connectResult.verify_token} />
          </div>
          <p style={{ fontSize: '.8rem', color: '#92400e', marginTop: '.75rem', marginBottom: 0 }}>
            After saving, Meta will send a verification request. Make sure your server is publicly accessible.
          </p>
        </>
      ) : (
        <>
          <div style={{ fontWeight: 700, color: '#92400e', marginBottom: '.5rem', fontSize: '.95rem' }}>
            Connected successfully
          </div>
          {connectResult.message && (
            <p style={{ fontSize: '.875rem', color: '#78350f', margin: 0 }}>
              {connectResult.message}
            </p>
          )}
          {connectResult.test_result && (
            <p style={{ fontSize: '.875rem', color: '#78350f', marginTop: '.5rem', marginBottom: 0 }}>
              Connection test: {connectResult.test_result}
            </p>
          )}
        </>
      )}
    </div>
  )
}

export default function WabaPage() {
  const [providers, setProviders]               = useState([])
  const [providerFields, setProviderFields]     = useState({})
  const [selectedProvider, setSelectedProvider] = useState('meta')
  const [form, setForm]                         = useState({})
  const [account, setAccount]                   = useState(undefined)
  // Comes from the API (app.baseURL). The browser origin is the wrong source —
  // on localhost it produces a callback URL Meta can never reach.
  const [webhookUrl, setWebhookUrl]             = useState('')
  const [reconfigure, setReconfigure]           = useState(false)
  const [phoneNumbers, setPhoneNumbers]         = useState([])

  const [loading, setLoading]                   = useState(true)
  const [phonesLoading, setPhonesLoading]       = useState(false)
  const [submitting, setSubmitting]             = useState(false)
  const [error, setError]                       = useState(null)
  const [testResult, setTestResult]             = useState(null)
  const [testLoading, setTestLoading]           = useState(false)
  const [connectResult, setConnectResult]       = useState(null)

  useEffect(() => {
    loadAll()
  }, [])

  async function loadAll() {
    setLoading(true)
    setError(null)
    const [wabaRes, providersRes] = await Promise.allSettled([
      wabaApi.get(),
      wabaApi.providers(),
    ])

    if (providersRes.status === 'fulfilled') {
      const pdata = providersRes.value
      setProviders(pdata.providers || [])
      setProviderFields(pdata.fields || {})
    }

    if (wabaRes.status === 'fulfilled' && wabaRes.value?.webhook_url) {
      setWebhookUrl(wabaRes.value.webhook_url)
    }

    if (wabaRes.status === 'fulfilled' && wabaRes.value?.connected) {
      const data = wabaRes.value
      setAccount(data)
      const detectedProvider = data.provider || 'meta'
      setSelectedProvider(detectedProvider)
      prefillForm(detectedProvider, data, (providersRes.value || {}).fields || {})
      if (detectedProvider === 'meta') {
        loadPhones()
      }
    } else {
      setAccount(null)
    }

    setLoading(false)
  }

  function prefillForm(provider, data, fields) {
    if (provider === 'meta') {
      setForm({
        waba_id: data.waba_id ?? '',
        phone_number_id: data.phone_number_id ?? '',
        access_token: '',
        display_name: data.display_name ?? '',
        business_id: data.business_id ?? '',
        app_id: data.app_id ?? '',
      })
      return
    }
    const providerFieldList = fields[provider] || []
    const prefilled = { display_name: data.display_name ?? '' }
    const config = data.provider_config || {}
    for (const field of providerFieldList) {
      if (field.key !== 'display_name') {
        prefilled[field.key] = field.type === 'password' ? '' : (config[field.key] ?? '')
      }
    }
    setForm(prefilled)
  }

  async function loadPhones() {
    setPhonesLoading(true)
    try {
      const res = await wabaApi.phoneNumbers()
      setPhoneNumbers(res.data ?? res ?? [])
    } catch {
      setPhoneNumbers([])
    } finally {
      setPhonesLoading(false)
    }
  }

  function setField(k, v) {
    setForm(f => ({ ...f, [k]: v }))
  }

  function handleProviderSelect(id) {
    setSelectedProvider(id)
    setForm({})
    setError(null)
    setConnectResult(null)
  }

  async function handleConnect(e) {
    e.preventDefault()
    setSubmitting(true)
    setError(null)
    setConnectResult(null)
    try {
      const payload = buildSubmitPayload(selectedProvider, form, providerFields)
      const res = await wabaApi.connect(payload)
      setConnectResult(res)
      await loadAll()
      setReconfigure(false)
    } catch (err) {
      const msg = err.message ?? 'Failed to connect.'
      setError(msg)
      toast.error(msg)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleTest() {
    setTestLoading(true)
    setTestResult(null)
    try {
      const res = await wabaApi.test()
      setTestResult({ ok: true, message: res.message ?? 'Connection is healthy.' })
    } catch (err) {
      setTestResult({ ok: false, message: err.message ?? 'Connection test failed.' })
    } finally {
      setTestLoading(false)
    }
  }

  const isConnected           = account != null
  const showForm              = !isConnected || reconfigure
  const currentFields         = providerFields[selectedProvider] || []
  const connectedProviderName = isConnected
    ? (providers.find(p => p.id === (account.provider || 'meta'))?.name || account.provider || 'Meta Cloud API')
    : ''

  const verifyToken  = account?.verify_token || null

  return (
    <div className="page">
      {/* ── Pulse animation ── */}
      <style>{`
        @keyframes lp-pulse {
          0%, 100% { opacity: 1; transform: scale(1); }
          50%       { opacity: .55; transform: scale(1.35); }
        }
        @keyframes lp-spin {
          from { transform: rotate(0deg); }
          to   { transform: rotate(360deg); }
        }
      `}</style>

      {/* SECTION 1: Page header */}
      <div style={{ marginBottom: '1.5rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>WhatsApp Settings</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Connect your WhatsApp Business Account and manage phone numbers & quality.</p>
      </div>

      {loading && (
        <div className="card" style={{ padding: '3rem', textAlign: 'center' }}>
          <div style={{ color: '#94a3b8', fontSize: '.95rem' }}>Loading…</div>
        </div>
      )}

      {!loading && (
        <>
          {/* SECTION 2: CONNECTION STATUS CARD */}
          {isConnected && !reconfigure && (
            <div className="card" style={{ marginBottom: '1.5rem', overflow: 'hidden' }}>
              {/* Green gradient header bar */}
              <div style={{
                height: 6,
                background: 'linear-gradient(90deg, #25D366 0%, #128C7E 100%)',
              }} />

              <div style={{ padding: '1.75rem 1.75rem 1.5rem' }}>
                {/* Top row: icon + status + actions */}
                <div style={{
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: '1.25rem',
                  marginBottom: '1.25rem',
                }}>
                  {/* WhatsApp icon circle */}
                  <div style={{
                    flexShrink: 0,
                    width: 56,
                    height: 56,
                    borderRadius: '50%',
                    background: 'linear-gradient(135deg, #25D366 0%, #128C7E 100%)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: '1.6rem',
                    boxShadow: '0 2px 8px #25d36630',
                  }}>
                    <MessageCircle size={26} strokeWidth={2} color="#fff" />
                  </div>

                  <div style={{ flex: 1, minWidth: 0 }}>
                    {/* Connected indicator */}
                    <div style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: 6,
                      marginBottom: 6,
                    }}>
                      <span style={{
                        display: 'inline-block',
                        width: 8,
                        height: 8,
                        borderRadius: '50%',
                        background: '#16a34a',
                        animation: 'lp-pulse 2s ease-in-out infinite',
                        flexShrink: 0,
                      }} />
                      <span style={{
                        fontSize: '.78rem',
                        fontWeight: 700,
                        color: '#16a34a',
                        letterSpacing: '.03em',
                        textTransform: 'uppercase',
                      }}>
                        Connected
                      </span>
                    </div>

                    {/* Display name */}
                    <div style={{
                      fontSize: '1.2rem',
                      fontWeight: 800,
                      color: '#0f172a',
                      lineHeight: 1.2,
                      marginBottom: 6,
                    }}>
                      {account.display_name || connectedProviderName}
                    </div>

                    {/* Provider badge */}
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                      <ProviderBadge
                        providerId={account.provider || 'meta'}
                        providerName={connectedProviderName}
                      />
                      {account.status && <StatusBadge status={account.status} />}
                    </div>
                  </div>
                </div>

                {/* WABA ID + Business ID info boxes */}
                {(account.waba_id || account.business_id) && (
                  <div style={{
                    display: 'grid',
                    gridTemplateColumns: account.waba_id && account.business_id ? '1fr 1fr' : '1fr',
                    gap: '1rem',
                    marginBottom: '1.5rem',
                  }}>
                    {account.waba_id && (
                      <div style={{
                        padding: '.875rem 1rem',
                        background: '#f8fafc',
                        border: '1px solid #e2e8f0',
                        borderRadius: 8,
                      }}>
                        <div style={{
                          fontSize: '.68rem',
                          fontWeight: 700,
                          color: '#94a3b8',
                          textTransform: 'uppercase',
                          letterSpacing: '.06em',
                          marginBottom: 5,
                        }}>
                          WABA ID
                        </div>
                        <div style={{
                          fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
                          fontSize: '.85rem',
                          fontWeight: 600,
                          color: '#1e293b',
                        }}>
                          {trunc(account.waba_id, 12)}
                        </div>
                      </div>
                    )}
                    {account.business_id && (
                      <div style={{
                        padding: '.875rem 1rem',
                        background: '#f8fafc',
                        border: '1px solid #e2e8f0',
                        borderRadius: 8,
                      }}>
                        <div style={{
                          fontSize: '.68rem',
                          fontWeight: 700,
                          color: '#94a3b8',
                          textTransform: 'uppercase',
                          letterSpacing: '.06em',
                          marginBottom: 5,
                        }}>
                          Business ID
                        </div>
                        <div style={{
                          fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
                          fontSize: '.85rem',
                          fontWeight: 600,
                          color: '#1e293b',
                        }}>
                          {trunc(account.business_id, 12)}
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {/* Test result banner */}
                {testResult && (
                  <div style={{
                    padding: '.875rem 1rem',
                    marginBottom: '1.25rem',
                    background: testResult.ok ? '#dcfce7' : '#fee2e2',
                    color: testResult.ok ? '#15803d' : '#b91c1c',
                    borderRadius: 8,
                    fontSize: '.875rem',
                    fontWeight: 500,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                  }}>
                    {testResult.ok
                      ? <CheckCircle2 size={15} strokeWidth={2} style={{ flexShrink: 0 }} />
                      : <XCircle size={15} strokeWidth={2} style={{ flexShrink: 0 }} />}
                    {testResult.message}
                  </div>
                )}

                {/* Action buttons */}
                <div style={{ display: 'flex', gap: '.75rem', flexWrap: 'wrap' }}>
                  <button
                    type="button"
                    onClick={handleTest}
                    disabled={testLoading}
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: 6,
                      padding: '8px 18px',
                      borderRadius: 8,
                      border: '1.5px solid #25D366',
                      background: testLoading ? '#f1f5f9' : '#fff',
                      color: '#128C7E',
                      fontSize: '.85rem',
                      fontWeight: 700,
                      cursor: testLoading ? 'not-allowed' : 'pointer',
                      transition: 'background .15s',
                    }}
                  >
                    {testLoading
                      ? (
                        <>
                          <span style={{
                            display: 'inline-block',
                            width: 13,
                            height: 13,
                            borderRadius: '50%',
                            border: '2px solid #25D36660',
                            borderTopColor: '#25D366',
                            animation: 'lp-spin .7s linear infinite',
                          }} />
                          Testing…
                        </>
                      )
                      : (
                        <>
                          <FlaskConical size={15} strokeWidth={2} />
                          Test Connection
                        </>
                      )
                    }
                  </button>

                  <button
                    type="button"
                    onClick={() => {
                      setReconfigure(true)
                      setTestResult(null)
                      setConnectResult(null)
                      prefillForm(account.provider || 'meta', account, providerFields)
                      setSelectedProvider(account.provider || 'meta')
                    }}
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: 6,
                      padding: '8px 18px',
                      borderRadius: 8,
                      border: '1.5px solid #e2e8f0',
                      background: '#fff',
                      color: '#475569',
                      fontSize: '.85rem',
                      fontWeight: 600,
                      cursor: 'pointer',
                    }}
                  >
                    <Settings2 size={15} strokeWidth={2} /> Reconfigure
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* SECTION 3: WEBHOOK CONFIGURATION CARD */}
          {isConnected && !reconfigure && (
            <div className="card" style={{ marginBottom: '1.5rem' }}>
              <div style={{ padding: '1.25rem 1.5rem', borderBottom: '1px solid #f1f5f9' }}>
                <h2 style={{ fontSize: '1rem', fontWeight: 700, color: '#0f172a', margin: 0 }}>
                  Webhook Configuration
                </h2>
                <p style={{ margin: '4px 0 0', fontSize: '.8rem', color: '#64748b' }}>
                  Register these in Meta for Developers → App → WhatsApp → Configuration
                </p>
              </div>

              <div style={{ padding: '1.25rem 1.5rem', display: 'flex', flexDirection: 'column', gap: '.75rem' }}>
                <WebhookRow label="Callback URL" value={webhookUrl} />
                {verifyToken && <WebhookRow label="Verify Token" value={verifyToken} />}

                <div style={{
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: 8,
                  padding: '.75rem 1rem',
                  background: '#fffbeb',
                  border: '1px solid #fde68a',
                  borderRadius: 8,
                  fontSize: '.8rem',
                  color: '#92400e',
                  lineHeight: 1.55,
                }}>
                  <AlertTriangle size={14} strokeWidth={2} style={{ flexShrink: 0, marginTop: 2 }} />
                  <span>
                    After saving, subscribe to the <strong>messages</strong> webhook field in Meta for Developers.
                  </span>
                </div>
              </div>
            </div>
          )}

          {/* SECTION 4: PHONE NUMBERS CARD */}
          {isConnected && !reconfigure && (account?.provider || 'meta') === 'meta' && (
            <div className="card" style={{ marginBottom: '1.5rem' }}>
              <div style={{ padding: '1.25rem 1.5rem', borderBottom: '1px solid #f1f5f9' }}>
                <h2 style={{ fontSize: '1rem', fontWeight: 700, color: '#0f172a', margin: 0 }}>
                  Phone Numbers
                </h2>
              </div>

              <div style={{ padding: 0 }}>
                {phonesLoading ? (
                  <div className="empty-state">
                    <div className="empty-state-text">Loading phone numbers…</div>
                  </div>
                ) : phoneNumbers.length === 0 ? (
                  <div style={{
                    padding: '2.5rem',
                    textAlign: 'center',
                    color: '#94a3b8',
                    fontSize: '.875rem',
                  }}>
                    No phone numbers configured. They will appear here once synced from Meta.
                  </div>
                ) : (
                  <div style={{ padding: '1rem 1.5rem', display: 'flex', flexDirection: 'column', gap: '.75rem' }}>
                    {phoneNumbers.map(phone => {
                      const ratingKey = (phone.quality_rating || '').toLowerCase()
                      const qc = QUALITY_CONFIG[ratingKey]
                      return (
                        <div
                          key={phone.phone_number_id ?? phone.id}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '1rem',
                            padding: '1rem 1.25rem',
                            background: '#f8fafc',
                            border: '1px solid #e2e8f0',
                            borderRadius: 10,
                          }}
                        >
                          {/* Number chip */}
                          <div style={{
                            flexShrink: 0,
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 7,
                            padding: '5px 14px',
                            borderRadius: 999,
                            background: '#25D36618',
                            border: '1.5px solid #25D36640',
                            color: '#128C7E',
                            fontWeight: 700,
                            fontSize: '.85rem',
                          }}>
                            <Phone size={13} strokeWidth={2} /> {phone.display_number ?? phone.display_name ?? '—'}
                          </div>

                          {/* Middle info */}
                          <div style={{ flex: 1, minWidth: 0 }}>
                            {phone.display_name && phone.display_number && (
                              <div style={{
                                fontSize: '.8rem',
                                color: '#475569',
                                fontWeight: 500,
                                marginBottom: 2,
                                whiteSpace: 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                              }}>
                                {phone.display_name}
                              </div>
                            )}
                            {phone.phone_number_id && (
                              <div style={{ fontSize: '.75rem', color: '#94a3b8', fontFamily: 'ui-monospace, monospace' }}>
                                ID: {phone.phone_number_id}
                              </div>
                            )}
                          </div>

                          {/* Quality */}
                          {phone.quality_rating && (
                            <div style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: 5,
                              padding: '4px 10px',
                              borderRadius: 999,
                              background: qc ? qc.bg : '#f1f5f9',
                              color: qc ? qc.color : '#475569',
                              fontSize: '.75rem',
                              fontWeight: 700,
                              flexShrink: 0,
                            }}>
                              <span style={{
                                display: 'inline-block',
                                width: 7,
                                height: 7,
                                borderRadius: '50%',
                                background: qc ? qc.dot : '#94a3b8',
                                flexShrink: 0,
                              }} />
                              {qc ? qc.label : (phone.quality_rating || '—')}
                            </div>
                          )}

                          {/* Default badge */}
                          {phone.is_default && (
                            <span style={{
                              flexShrink: 0,
                              display: 'inline-block',
                              padding: '4px 12px',
                              borderRadius: 999,
                              fontSize: '.75rem',
                              fontWeight: 700,
                              background: '#eff6ff',
                              color: '#074a8c',
                              border: '1px solid #bcdcf6',
                            }}>
                              Default
                            </span>
                          )}
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>
            </div>
          )}

          {/* SECTION 5: NOT CONNECTED / RECONFIGURE FORM */}
          {showForm && (
            <div className="card" style={{ marginBottom: '1.5rem' }}>
              {/* Card header */}
              <div style={{ padding: '1.25rem 1.5rem', borderBottom: '1px solid #f1f5f9' }}>
                <h2 style={{ fontSize: '1rem', fontWeight: 700, color: '#0f172a', margin: 0 }}>
                  {reconfigure ? 'Reconfigure WhatsApp' : 'Connect WhatsApp'}
                </h2>
                {!reconfigure && (
                  <p style={{ margin: '4px 0 0', fontSize: '.8rem', color: '#64748b' }}>
                    Connect your WhatsApp Business Account to start sending and receiving messages.
                  </p>
                )}
              </div>

              <div style={{ padding: '1.5rem' }}>
                {/* Provider selector grid */}
                <div style={{ marginBottom: '1.5rem' }}>
                  <div style={{
                    fontSize: '.75rem',
                    fontWeight: 700,
                    color: '#475569',
                    textTransform: 'uppercase',
                    letterSpacing: '.06em',
                    marginBottom: '.75rem',
                  }}>
                    Select Provider
                  </div>
                  <div style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))',
                    gap: '.75rem',
                  }}>
                    {providers.length > 0
                      ? providers.map(p => (
                          <ProviderCard
                            key={p.id}
                            provider={p}
                            selected={selectedProvider}
                            onSelect={handleProviderSelect}
                          />
                        ))
                      : [
                          { id: 'meta',      name: 'Meta Cloud API',  description: 'Official WhatsApp Business API by Meta' },
                          { id: 'wati',      name: 'WATI',            description: 'WhatsApp Team Inbox by WATI' },
                          { id: 'aisensy',   name: 'AiSensy',         description: 'AiSensy WhatsApp platform' },
                          { id: '360dialog', name: '360dialog',       description: '360dialog WhatsApp API' },
                          { id: 'twilio',    name: 'Twilio',          description: 'Twilio Conversations API' },
                          { id: 'custom',    name: 'Custom API',      description: 'Connect any compatible API endpoint' },
                        ].map(p => (
                          <ProviderCard
                            key={p.id}
                            provider={p}
                            selected={selectedProvider}
                            onSelect={handleProviderSelect}
                          />
                        ))
                    }
                  </div>
                </div>

                {/* Dynamic connection form */}
                <form onSubmit={handleConnect}>
                  <div style={{ marginBottom: '1rem' }}>
                    <div style={{
                      fontSize: '.75rem',
                      fontWeight: 700,
                      color: '#475569',
                      textTransform: 'uppercase',
                      letterSpacing: '.06em',
                      marginBottom: '.75rem',
                    }}>
                      Connection Details
                    </div>

                    {/* Meta provider: static known fields */}
                    {selectedProvider === 'meta' && currentFields.length === 0 && (
                      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                        <div className="form-group">
                          <label className="form-label" htmlFor="meta_waba_id">
                            WABA ID <span style={{ color: '#ef4444' }}>*</span>
                          </label>
                          <input
                            id="meta_waba_id"
                            className="form-input"
                            type="text"
                            value={form.waba_id || ''}
                            onChange={e => setField('waba_id', e.target.value)}
                            placeholder="e.g. 123456789012345"
                            required
                          />
                        </div>
                        <div className="form-group">
                          <label className="form-label" htmlFor="meta_phone_number_id">
                            Phone Number ID <span style={{ color: '#ef4444' }}>*</span>
                          </label>
                          <input
                            id="meta_phone_number_id"
                            className="form-input"
                            type="text"
                            value={form.phone_number_id || ''}
                            onChange={e => setField('phone_number_id', e.target.value)}
                            placeholder="e.g. 987654321098765"
                            required
                          />
                        </div>
                        <div className="form-group" style={{ gridColumn: '1 / -1' }}>
                          <label className="form-label" htmlFor="meta_access_token">
                            Access Token <span style={{ color: '#ef4444' }}>*</span>
                          </label>
                          <input
                            id="meta_access_token"
                            className="form-input"
                            type="password"
                            value={form.access_token || ''}
                            onChange={e => setField('access_token', e.target.value)}
                            placeholder="Permanent or long-lived system user token"
                            required={!isConnected}
                            autoComplete="off"
                          />
                          {isConnected && (
                            <span style={{ fontSize: '.75rem', color: '#94a3b8', marginTop: 4, display: 'block' }}>
                              Leave blank to keep existing token.
                            </span>
                          )}
                        </div>
                        <div className="form-group">
                          <label className="form-label" htmlFor="meta_display_name">
                            Display Name <span style={{ color: '#ef4444' }}>*</span>
                          </label>
                          <input
                            id="meta_display_name"
                            className="form-input"
                            type="text"
                            value={form.display_name || ''}
                            onChange={e => setField('display_name', e.target.value)}
                            placeholder="e.g. Acme Corp"
                            required
                          />
                        </div>
                        <div className="form-group">
                          <label className="form-label" htmlFor="meta_business_id">
                            Business ID{' '}
                            <span style={{ color: '#94a3b8', fontWeight: 400 }}>(optional)</span>
                          </label>
                          <input
                            id="meta_business_id"
                            className="form-input"
                            type="text"
                            value={form.business_id || ''}
                            onChange={e => setField('business_id', e.target.value)}
                            placeholder="Meta Business Manager ID"
                          />
                        </div>
                        <div className="form-group">
                          <label className="form-label" htmlFor="meta_app_id">
                            App ID{' '}
                            <span style={{ color: '#94a3b8', fontWeight: 400 }}>(needed for carousel/media templates)</span>
                          </label>
                          <input
                            id="meta_app_id"
                            className="form-input"
                            type="text"
                            value={form.app_id || ''}
                            onChange={e => setField('app_id', e.target.value)}
                            placeholder="Meta App ID (for image-template uploads)"
                          />
                        </div>
                      </div>
                    )}

                    {/* Dynamic fields from API */}
                    {currentFields.length > 0 && (
                      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                        {!currentFields.some(f => f.key === 'display_name') && (
                          <div className="form-group">
                            <label className="form-label" htmlFor="dyn_display_name">
                              Display Name <span style={{ color: '#ef4444' }}>*</span>
                            </label>
                            <input
                              id="dyn_display_name"
                              className="form-input"
                              type="text"
                              value={form.display_name || ''}
                              onChange={e => setField('display_name', e.target.value)}
                              placeholder="e.g. Acme Corp"
                              required
                            />
                          </div>
                        )}
                        {currentFields.map(field => (
                          <div
                            key={field.key}
                            style={field.fullWidth || field.type === 'password' ? { gridColumn: '1 / -1' } : {}}
                          >
                            <DynamicField
                              field={field}
                              value={form[field.key]}
                              onChange={setField}
                              isEditing={isConnected}
                            />
                          </div>
                        ))}
                      </div>
                    )}
                  </div>

                  {error && (
                    <div style={{
                      marginTop: '1rem',
                      padding: '.75rem 1rem',
                      background: '#fee2e2',
                      color: '#b91c1c',
                      borderRadius: 8,
                      fontSize: '.875rem',
                    }}>
                      {error}
                    </div>
                  )}

                  <div className="flex gap-2" style={{ marginTop: '1.25rem' }}>
                    <button
                      type="submit"
                      className="btn btn-primary"
                      disabled={submitting}
                    >
                      {submitting ? 'Connecting…' : isConnected ? 'Update' : 'Connect'}
                    </button>
                    {isConnected && reconfigure && (
                      <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => { setReconfigure(false); setError(null) }}
                      >
                        Cancel
                      </button>
                    )}
                  </div>
                </form>

                {/* Post-connect result */}
                <ConnectResultCard
                  connectResult={connectResult}
                  selectedProvider={selectedProvider}
                  account={account}
                />
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
