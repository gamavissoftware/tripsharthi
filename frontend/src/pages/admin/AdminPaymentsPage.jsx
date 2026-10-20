import { useEffect, useState } from 'react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { KeyRound, ShieldCheck, Webhook, Radio } from 'lucide-react'
import { Kpi } from './AdminUi'
import { fmtDate } from './adminUtil'
import { confirmAsk } from '../../components/confirmAsk'

const MODE = { live: ['LIVE', 'var(--success)'], test: ['TEST', 'var(--warning)'], none: ['Not set', 'var(--text-3)'] }
const SOURCE = { admin: 'Saved here (encrypted)', server: 'From the server settings', none: 'Nothing saved' }

/** TripSarthi's own Razorpay account: the keys customers' subscription payments go through. Secrets are write-only. */
export default function AdminPaymentsPage() {
  const [st, setSt] = useState(null)
  const [f, setF] = useState({ key_id: '', key_secret: '', webhook_secret: '' })
  const [busy, setBusy] = useState('')

  const load = () => admin.razorpay().then(d => { setSt(d); setF(x => ({ ...x, key_id: d.key_id || '' })) }).catch(e => toast.error('Could not load', e.message))
  useEffect(() => { load() }, [])

  async function save(e) {
    e.preventDefault()
    if (f.key_id.trim() !== (st.key_id || '') && !f.key_secret.trim()) {
      toast.warning('One thing needs fixing', 'A new Key ID needs its Key Secret too'); document.getElementById('rzp-secret')?.focus(); return
    }
    const live = f.key_id.startsWith('rzp_live_')
    if (live && !(await confirmAsk('These are LIVE keys: customers will be charged real money through this account. Save them?'))) return
    setBusy('save')
    try {
      const d = await admin.saveRazorpay(f)
      setSt(d); setF({ key_id: d.key_id, key_secret: '', webhook_secret: '' })
      toast.success('Razorpay keys saved', 'Razorpay accepted them, and they are stored encrypted.')
    } catch (err) { toast.error('Not saved', err.message) }
    setBusy('')
  }

  async function test() {
    setBusy('test')
    try { const r = await admin.testRazorpay(); toast.success('Razorpay accepted the saved keys', `Mode: ${r.mode}`) }
    catch (err) { toast.error('Check failed', err.message) }
    setBusy('')
  }

  async function clear() {
    if (!(await confirmAsk('Remove the keys saved here? Checkout will use the server settings if there are any, otherwise customers cannot pay.'))) return
    setBusy('clear')
    try { const d = await admin.clearRazorpay(); setSt(d); setF({ key_id: d.key_id || '', key_secret: '', webhook_secret: '' }); toast.success('Saved keys removed') }
    catch (err) { toast.error('Could not remove', err.message) }
    setBusy('')
  }

  async function copy(v) { try { await navigator.clipboard.writeText(v); toast.success('Copied') } catch { toast.error('Could not copy') } }

  if (!st) return <div className="page"><div className="page-header"><h1 className="page-title">Payments</h1></div><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  const [modeLabel] = MODE[st.mode] || MODE.none
  const steps = [[!!st.key_id && st.secret_set, 'Key ID and secret are saved'], [st.mode === 'live', st.mode === 'test' ? 'Using TEST keys — switch to live keys to take real money' : 'Using LIVE keys'], [st.webhook_set, 'Webhook secret is saved (the safety net for closed browser tabs)']]
  return (
    <div className="page">
      <div className="page-header"><div><h1 className="page-title">Payments · Razorpay</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>The Razorpay account customers' subscription payments go through. Secrets are write-only.</div></div></div>

      <div className={'wa-banner ' + (st.configured ? 'ok' : 'bad')}>
        <div className="grow"><b>{st.configured ? 'Customers can pay their subscription.' : 'Not ready: customers cannot pay yet.'}</b> {SOURCE[st.source]}{st.changed_at && <> · changed {fmtDate(st.changed_at)}</>}</div>
        <button type="button" className="btn btn-ghost btn-sm" onClick={test} disabled={busy !== '' || !st.configured}>{busy === 'test' ? 'Checking…' : 'Test saved keys'}</button>
      </div>
      {st.warnings.map(w => <div key={w} className="wa-banner">⚠ <div className="grow">{w}</div></div>)}

      <div className="cu-kpis">
        <Kpi icon={Radio} tone={st.mode === 'live' ? '#1a9d57' : '#f59e0b'} label="Mode" value={modeLabel} sub="from the key prefix" />
        <Kpi icon={KeyRound} tone="#0a6cc4" label="Key ID" value={st.key_id ? `${st.key_id.slice(0, 9)}…` : '—'} sub={st.secret_set ? 'secret saved' : 'secret not set'} />
        <Kpi icon={Webhook} tone={st.webhook_set ? '#1a9d57' : '#f59e0b'} label="Webhook" value={st.webhook_set ? 'Set' : 'Not set'} sub={st.webhook_set ? 'confirms closed-tab payments' : 'recommended'} />
        <Kpi icon={ShieldCheck} tone="#12a89e" label="Stored" value={st.source === 'admin' ? 'Encrypted' : st.source === 'server' ? 'Server file' : '—'} sub="secrets are never shown" />
      </div>

      <div className="em-grid">
        <div style={{ display: 'grid', gap: 14 }}>
          <form className="card card-body" onSubmit={save} autoComplete="off">
            <div style={{ fontWeight: 800, color: '#0a1f44', marginBottom: 2 }}>Razorpay keys</div>
            <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 12 }}>From the Razorpay dashboard → Account &amp; Settings → API keys. Razorpay is asked to confirm the pair before anything is saved. Leave a secret box empty to keep the one already saved.</div>
            <div style={{ display: 'grid', gap: 12 }}>
              <label>Key ID *<input className="form-input" value={f.key_id} onChange={e => setF({ ...f, key_id: e.target.value.trim() })} placeholder="rzp_live_…" spellCheck={false} autoComplete="off" autoCapitalize="none" required pattern="rzp_(live|test)_[A-Za-z0-9]{8,}" title="A Razorpay Key ID starts with rzp_live_ or rzp_test_" /></label>
              <label>Key Secret<input id="rzp-secret" className="form-input" type="password" minLength={10} title="Paste the full Key Secret from Razorpay" value={f.key_secret} onChange={e => setF({ ...f, key_secret: e.target.value })} placeholder={st.secret_set ? '•••••••• saved — leave empty to keep' : 'Paste the Key Secret'} autoComplete="new-password" spellCheck={false} /></label>
              <label>Webhook secret <span style={{ color: 'var(--text-3)', fontWeight: 400 }}>(recommended)</span>
                <input className="form-input" type="password" minLength={6} title="At least 6 characters" value={f.webhook_secret} onChange={e => setF({ ...f, webhook_secret: e.target.value })} placeholder={st.webhook_set ? '•••••••• saved — leave empty to keep' : 'The secret you typed in Razorpay → Webhooks'} autoComplete="new-password" spellCheck={false} /></label>
            </div>
            <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
              <button className="btn btn-primary" disabled={busy !== ''}>{busy === 'save' ? 'Checking with Razorpay…' : 'Check & save'}</button>
              {st.source === 'admin' && <button type="button" className="btn btn-ghost" onClick={clear} disabled={busy !== ''} style={{ color: 'var(--danger)', marginLeft: 'auto' }}>Remove saved keys</button>}
            </div>
          </form>
        </div>

        <div style={{ display: 'grid', gap: 14 }}>
          <div className="card card-body">
            <div style={{ fontWeight: 800, color: '#0a1f44', marginBottom: 4 }}>Ready to take payments?</div>
            {steps.map(([ok, text]) => <div key={text} className={'wa-check' + (ok ? '' : ' no')} style={ok ? undefined : { color: '#7a5a12' }}><span style={ok ? undefined : { background: '#f59e0b' }}>{ok ? '✓' : '·'}</span>{text}</div>)}
          </div>
          <div className="card card-body">
            <div style={{ fontWeight: 800, color: '#0a1f44', marginBottom: 4 }}>Webhook (in the Razorpay dashboard)</div>
            <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 8 }}>Settings → Webhooks → Add. Use this URL, choose your own secret (paste the same one on the left), and tick Order → order.paid and Payment → payment.captured.</div>
            <div style={{ display: 'flex', gap: 8 }}>
              <input className="form-input" readOnly value={st.webhook_url} onFocus={e => e.target.select()} style={{ fontFamily: 'ui-monospace,monospace', fontSize: 12 }} />
              <button type="button" className="btn btn-ghost" onClick={() => copy(st.webhook_url)}>Copy</button>
            </div>
            <div style={{ fontSize: 12.5, color: 'var(--text-3)', marginTop: 10 }}>Normally a payment is confirmed by a signed callback straight after checkout. The webhook is the safety net: if the customer pays and closes the page, Razorpay tells us and the plan still opens.</div>
          </div>
        </div>
      </div>
    </div>
  )
}
