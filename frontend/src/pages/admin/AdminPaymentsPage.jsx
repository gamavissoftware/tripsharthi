import { useEffect, useState } from 'react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Pill } from './AdminUi'
import { fmtDate } from './adminUtil'

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
    const live = f.key_id.startsWith('rzp_live_')
    if (live && !window.confirm('These are LIVE keys: customers will be charged real money through this account. Save them?')) return
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
    if (!window.confirm('Remove the keys saved here? Checkout will use the server settings if there are any, otherwise customers cannot pay.')) return
    setBusy('clear')
    try { const d = await admin.clearRazorpay(); setSt(d); setF({ key_id: d.key_id || '', key_secret: '', webhook_secret: '' }); toast.success('Saved keys removed') }
    catch (err) { toast.error('Could not remove', err.message) }
    setBusy('')
  }

  async function copy(v) { try { await navigator.clipboard.writeText(v); toast.success('Copied') } catch { toast.error('Could not copy') } }

  if (!st) return <div className="page"><div className="page-header"><h1 className="page-title">Payments</h1></div><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  const [modeLabel, modeColor] = MODE[st.mode] || MODE.none

  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Payments · Razorpay</h1></div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <Pill color={modeColor}>{modeLabel}</Pill>
          <b>{st.configured ? 'Customers can pay their subscription' : 'Not ready: customers cannot pay yet'}</b>
        </div>
        <div style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 6 }}>
          Key ID: <code>{st.key_id || '—'}</code> · Secret: {st.secret_set ? 'set' : 'not set'} · Webhook secret: {st.webhook_set ? 'set' : 'not set'} · {SOURCE[st.source]}
          {st.changed_at && <> · changed {fmtDate(st.changed_at)}</>}
        </div>
        {st.warnings.map(w => <div key={w} style={{ marginTop: 8, fontSize: 13, color: 'var(--warning)' }}>⚠ {w}</div>)}
      </div>

      <form className="card card-body" onSubmit={save} autoComplete="off" style={{ marginBottom: 14 }}>
        <div style={{ fontWeight: 700, marginBottom: 2 }}>Razorpay keys</div>
        <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 12 }}>From the Razorpay dashboard → Account &amp; Settings → API keys. Razorpay is asked to confirm the pair before anything is saved. Secrets are never shown again; leave a secret box empty to keep the one already saved.</div>
        <div style={{ display: 'grid', gap: 12, maxWidth: 520 }}>
          <label>Key ID<input className="form-input" value={f.key_id} onChange={e => setF({ ...f, key_id: e.target.value })} placeholder="rzp_live_…" spellCheck={false} autoComplete="off" required /></label>
          <label>Key Secret<input className="form-input" type="password" value={f.key_secret} onChange={e => setF({ ...f, key_secret: e.target.value })} placeholder={st.secret_set ? '•••••••• saved — leave empty to keep' : 'Paste the Key Secret'} autoComplete="new-password" spellCheck={false} /></label>
          <label>Webhook secret <span style={{ color: 'var(--text-3)' }}>(optional now, needed for cancelled / halted subscriptions)</span>
            <input className="form-input" type="password" value={f.webhook_secret} onChange={e => setF({ ...f, webhook_secret: e.target.value })} placeholder={st.webhook_set ? '•••••••• saved — leave empty to keep' : 'The secret you typed in Razorpay → Webhooks'} autoComplete="new-password" spellCheck={false} /></label>
        </div>
        <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
          <button className="btn btn-primary" disabled={busy !== ''}>{busy === 'save' ? 'Checking with Razorpay…' : 'Check & save'}</button>
          <button type="button" className="btn btn-ghost" onClick={test} disabled={busy !== '' || !st.configured}>{busy === 'test' ? 'Checking…' : 'Test saved keys'}</button>
          {st.source === 'admin' && <button type="button" className="btn btn-ghost" onClick={clear} disabled={busy !== ''} style={{ color: 'var(--danger)' }}>Remove saved keys</button>}
        </div>
      </form>

      <div className="card card-body">
        <div style={{ fontWeight: 700, marginBottom: 4 }}>Webhook (in the Razorpay dashboard)</div>
        <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 8 }}>Settings → Webhooks → Add. Use this URL, choose your own secret (paste the same one above), and tick the subscription events (activated, charged, halted, cancelled, completed).</div>
        <div style={{ display: 'flex', gap: 8, maxWidth: 620 }}>
          <input className="form-input" readOnly value={st.webhook_url} onFocus={e => e.target.select()} style={{ fontFamily: 'ui-monospace,monospace', fontSize: 12 }} />
          <button type="button" className="btn btn-ghost" onClick={() => copy(st.webhook_url)}>Copy</button>
        </div>
        <div style={{ fontSize: 12.5, color: 'var(--text-3)', marginTop: 10 }}>Payments themselves don&apos;t need the webhook: they are confirmed by a signed callback straight after checkout.</div>
      </div>
    </div>
  )
}
