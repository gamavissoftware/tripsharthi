/**
 * Meta Ads spend — connect the ad accounts whose monthly spend should show
 * on Analytics → Meta cost.
 *
 * Uses the same Facebook Login as the Page connections but with
 * return_to=ads, so the backend keeps the USER token (ad-account insights
 * cannot be read with a Page token). Coming back, the URL carries
 * ?meta_ads_state=… which this section exchanges for a connection, then the
 * ad-account picker appears.
 */
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { BarChart3, RefreshCw, X, CheckCircle2 } from 'lucide-react'
import { metaAds } from '../api/metaAds'
import { social as socialApi } from '../api/social'
import { toast } from '../components/Toast'

const money = (v, ccy) => {
  try { return new Intl.NumberFormat('en-IN', { style: 'currency', currency: ccy || 'INR', maximumFractionDigits: 0 }).format(Number(v ?? 0)) }
  catch { return `${ccy} ${v}` }
}

export default function MetaAdsSection({ fbConfigured }) {
  const [status, setStatus]     = useState(null)
  const [starting, setStarting] = useState(false)
  const [picker, setPicker]     = useState(null)   // list of ad accounts, or null
  const [chosen, setChosen]     = useState({})     // id => true
  const [saving, setSaving]     = useState(false)
  const [syncing, setSyncing]   = useState(false)
  const [searchParams, setSearchParams] = useSearchParams()

  async function load() {
    try { setStatus((await metaAds.status()).data ?? null) } catch { setStatus(null) }
  }
  useEffect(() => { load() }, [])

  // Back from Meta with a state for an ads connection.
  useEffect(() => {
    const err   = searchParams.get('meta_ads_error')
    const state = searchParams.get('meta_ads_state')
    if (!err && !state) return
    setSearchParams({}, { replace: true })
    if (err) { toast.error('Meta Ads connection failed', err); return }
    metaAds.connect(state)
      .then(async r => { setStatus(r.data ?? null); toast.success('Meta Ads connected'); await openPicker(r.data) })
      .catch(e => toast.error('Could not connect Meta Ads', e.message))
  }, [searchParams, setSearchParams])

  async function openPicker(current = status) {
    try {
      const r = await metaAds.accounts()
      const list = r.data ?? []
      setPicker(list)
      const pre = {}
      for (const a of (current?.ad_accounts ?? [])) pre[a.id] = true
      if (Object.keys(pre).length === 0) for (const a of list) if (Number(a.amount_spent) > 0) pre[a.id] = true
      setChosen(pre)
      if (list.length === 0) toast.error('No ad accounts visible to this Facebook account')
    } catch (e) {
      toast.error('Could not list ad accounts', e.message)
    }
  }

  async function start() {
    setStarting(true)
    try {
      const r = await socialApi.oauthStart('ads')
      window.location.href = r.data.auth_url
    } catch (e) {
      toast.error('Could not start Facebook login', e.message)
      setStarting(false)
    }
  }

  async function save() {
    setSaving(true)
    try {
      const accounts = (picker ?? []).filter(a => chosen[a.id]).map(a => ({ id: a.id, name: a.name, currency: a.currency }))
      const r = await metaAds.select(accounts)
      setStatus(r.data ?? null)
      setPicker(null)
      toast.success('Ad accounts saved', `${accounts.length} selected`)
      await sync()
    } catch (e) {
      toast.error('Could not save ad accounts', e.message)
    } finally {
      setSaving(false)
    }
  }

  async function sync() {
    setSyncing(true)
    try {
      const r = await metaAds.sync(12)
      toast.success('Ad spend synced', `${r.sync?.rows ?? 0} monthly rows${r.sync?.errors?.length ? ' · some accounts refused' : ''}`)
      await load()
    } catch (e) {
      toast.error('Ad spend sync failed', e.message)
    } finally {
      setSyncing(false)
    }
  }

  async function disconnect() {
    if (!window.confirm('Disconnect Meta Ads? Spend history stays; new months stop syncing.')) return
    try { await metaAds.disconnect(); await load(); toast.success('Meta Ads disconnected') }
    catch (e) { toast.error('Disconnect failed', e.message) }
  }

  const connected = !!status?.connected
  const accounts  = status?.ad_accounts ?? []

  return (
    <div style={{ marginBottom: '2rem' }}>
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:'1rem', gap:12, flexWrap:'wrap' }}>
        <div style={{ display:'flex', alignItems:'center', gap:12 }}>
          <div style={{ width:42, height:42, borderRadius:10, background:'#0866ff', display:'flex', alignItems:'center', justifyContent:'center', boxShadow:'0 2px 8px #0866ff40' }}>
            <BarChart3 size={22} color="#fff" strokeWidth={2} />
          </div>
          <div>
            <div style={{ fontWeight:800, fontSize:'1rem', color:'var(--text)' }}>Meta Ads spend</div>
            <div style={{ fontSize:'.78rem', color:'var(--text-3)' }}>Facebook & Instagram ad spend, month on month, on Analytics → Meta cost</div>
          </div>
        </div>
        <div style={{ display:'flex', gap:8 }}>
          {connected && (
            <button className="btn btn-ghost btn-sm" onClick={() => openPicker()} style={{ fontSize:'.78rem' }}>Choose ad accounts</button>
          )}
          {fbConfigured && (
            <button className="btn btn-primary btn-sm" onClick={start} disabled={starting} style={{ background:'#0866ff' }}>
              <span style={{ fontWeight:900, marginRight:6 }}>f</span>{starting ? 'Opening Facebook…' : connected ? 'Reconnect' : 'Connect with Facebook'}
            </button>
          )}
        </div>
      </div>

      {!connected ? (
        <div style={{ textAlign:'center', padding:'2rem', background:'#fff', borderRadius:12, border:'2px dashed var(--border)', fontSize:'.8125rem', color:'var(--text-3)' }}>
          Not connected. Connect with Facebook and pick the ad accounts running your campaigns. Needs the <code>ads_read</code> permission.
        </div>
      ) : (
        <div style={{ border:'1px solid var(--border)', borderRadius:12, padding:'1.1rem 1.25rem', background:'#fff' }}>
          <div style={{ display:'flex', alignItems:'center', gap:8, marginBottom:'.6rem', fontSize:'.8125rem' }}>
            <CheckCircle2 size={15} color="#15803d" />
            <span style={{ fontWeight:700 }}>Connected</span>
            <span style={{ color:'var(--text-3)' }}>
              {accounts.length ? `${accounts.length} ad account${accounts.length > 1 ? 's' : ''}` : 'no ad account chosen yet'}
              {status?.last_sync_at ? ` · synced ${new Date(status.last_sync_at.replace(' ', 'T') + 'Z').toLocaleString('en-IN')}` : ''}
            </span>
          </div>
          {accounts.map(a => (
            <div key={a.id} style={{ fontSize:'.8125rem', padding:'.25rem 0' }}>
              <span style={{ fontWeight:600 }}>{a.name}</span> <span style={{ color:'var(--text-3)' }}>{a.id}{a.currency ? ` · ${a.currency}` : ''}</span>
            </div>
          ))}
          {status?.last_error && <div style={{ fontSize:'.78rem', color:'#b45309', marginTop:6 }}>Last sync warning: {status.last_error}</div>}
          <div style={{ display:'flex', gap:8, marginTop:'.85rem', flexWrap:'wrap' }}>
            <button className="btn btn-sm btn-primary" onClick={sync} disabled={syncing || accounts.length === 0} style={{ fontSize:'.78rem' }}>
              <RefreshCw size={13} /> {syncing ? 'Syncing…' : 'Sync spend now'}
            </button>
            <button className="btn btn-sm" onClick={disconnect} style={{ fontSize:'.78rem', background:'#fff1f0', border:'1px solid #fca5a5', color:'#dc2626' }}>Disconnect</button>
          </div>
        </div>
      )}

      {picker && (
        <div onClick={() => setPicker(null)} style={{ position:'fixed', inset:0, background:'rgba(0,0,0,.5)', zIndex:10000, display:'flex', alignItems:'center', justifyContent:'center', padding:20, backdropFilter:'blur(3px)' }}>
          <div onClick={e => e.stopPropagation()} style={{ background:'#fff', borderRadius:18, width:'100%', maxWidth:560, maxHeight:'90vh', display:'flex', flexDirection:'column', boxShadow:'0 24px 64px rgba(0,0,0,.22)' }}>
            <div style={{ padding:'1.25rem 1.5rem', borderBottom:'1px solid var(--border)', display:'flex', alignItems:'center', justifyContent:'space-between' }}>
              <div>
                <h3 style={{ fontWeight:800, fontSize:'.9375rem', margin:0 }}>Which ad accounts run your campaigns?</h3>
                <p style={{ margin:0, fontSize:'.75rem', color:'var(--text-3)' }}>{picker.length} visible to this Facebook account · lifetime spend shown</p>
              </div>
              <button onClick={() => setPicker(null)} style={{ background:'none', border:'none', cursor:'pointer', color:'var(--text-3)', display:'inline-flex' }}><X size={18} /></button>
            </div>
            <div style={{ padding:'1rem 1.5rem', overflow:'auto' }}>
              {picker.map(a => (
                <label key={a.id} style={{ display:'flex', alignItems:'center', gap:12, padding:'.55rem 0', borderBottom:'1px solid var(--border)', cursor:'pointer' }}>
                  <input type="checkbox" checked={!!chosen[a.id]} onChange={e => setChosen(c => ({ ...c, [a.id]: e.target.checked }))} />
                  <div style={{ flex:1, minWidth:0 }}>
                    <div style={{ fontWeight:700, fontSize:'.875rem' }}>{a.name}</div>
                    <div style={{ fontSize:'.72rem', color:'var(--text-3)' }}>{a.id} · {a.currency}{a.status !== 1 ? ' · not active' : ''}</div>
                  </div>
                  <div style={{ fontSize:'.8rem', fontWeight:600, whiteSpace:'nowrap' }}>{money(Number(a.amount_spent) / 100, a.currency)}</div>
                </label>
              ))}
            </div>
            <div style={{ padding:'1rem 1.5rem', borderTop:'1px solid var(--border)', display:'flex', gap:10 }}>
              <button className="btn btn-ghost" onClick={() => setPicker(null)} style={{ flex:1 }}>Cancel</button>
              <button className="btn btn-primary" onClick={save} disabled={saving} style={{ flex:2, justifyContent:'center', background:'#0866ff' }}>
                {saving ? 'Saving…' : 'Save and sync spend'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
