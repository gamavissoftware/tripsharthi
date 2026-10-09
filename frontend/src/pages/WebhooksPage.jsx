import { useState, useEffect } from 'react'
import { webhooks as api } from '../api/webhooks'
import { useToast } from '../components/Toast'
import { Plus, Copy, AlertTriangle } from 'lucide-react'

export default function WebhooksPage() {
  const { toast } = useToast()
  const [rows, setRows] = useState([])
  const [events, setEvents] = useState([])
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ url: '', events: ['*'] })

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const r = await api.list()
      setRows(r.data ?? [])
      setEvents(r.available_events ?? [])
    } catch (err) { toast.error('Failed to load webhooks', err?.message) }
    setLoading(false)
  }

  function toggleEvent(ev) {
    setForm(f => {
      const has = f.events.includes(ev)
      let next = has ? f.events.filter(e => e !== ev) : [...f.events.filter(e => e !== '*'), ev]
      if (next.length === 0) next = ['*']
      return { ...f, events: next }
    })
  }

  async function create() {
    if (!form.url.trim()) { toast.error('Endpoint URL is required'); return }
    setCreating(true)
    try {
      await api.create(form)
      toast.success('Webhook added')
      setForm({ url: '', events: ['*'] })
      load()
    } catch (err) { toast.error('Failed to add webhook', err?.message) }
    setCreating(false)
  }

  async function remove(id) {
    try { await api.remove(id); setRows(rs => rs.filter(r => r.id !== id)); toast.success('Webhook removed') }
    catch (err) { toast.error('Failed to remove', err?.message) }
  }

  async function test(id) {
    try { await api.test(id); toast.success('Test ping queued', 'Check your endpoint shortly.') }
    catch (err) { toast.error('Failed to send test', err?.message) }
  }

  async function toggleActive(sub) {
    try { await api.update(sub.id, { is_active: !Number(sub.is_active) }); load() }
    catch (err) { toast.error('Failed to update', err?.message) }
  }

  function copy(text) { navigator.clipboard?.writeText(text); toast.success('Copied') }

  const allChecked = form.events.includes('*')

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div className="page-header">
        <div>
          <h1 className="page-title">Webhooks</h1>
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 4 }}>
            Send events to Zapier, Make, n8n, or your own backend. Each delivery is signed with HMAC-SHA256 (header <code>X-TravelPilot-Signature</code>).
          </p>
        </div>
      </div>

      {/* Create */}
      <div className="card" style={{ padding: '1.25rem', marginBottom: '1.25rem' }}>
        <div style={{ fontWeight: 700, marginBottom: 12 }}>Add endpoint</div>
        <label style={lbl}>Endpoint URL</label>
        <input className="form-input" value={form.url} onChange={e => setForm(f => ({ ...f, url: e.target.value }))} placeholder="https://hooks.zapier.com/..." />
        <div style={{ margin: '14px 0 6px', fontSize: 13, fontWeight: 600, color: '#374151' }}>Events</div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <Chip active={allChecked} onClick={() => setForm(f => ({ ...f, events: ['*'] }))}>All events</Chip>
          {events.map(ev => <Chip key={ev} active={!allChecked && form.events.includes(ev)} onClick={() => toggleEvent(ev)}>{ev}</Chip>)}
        </div>
        <button className="btn btn-primary" style={{ marginTop: 16 }} onClick={create} disabled={creating}>
          {creating ? 'Adding…' : <><Plus size={15} strokeWidth={2} /> Add webhook</>}
        </button>
      </div>

      {/* List */}
      <div className="card" style={{ overflow: 'hidden' }}>
        {loading ? (
          <div className="empty-state"><span className="empty-state-text">Loading…</span></div>
        ) : rows.length === 0 ? (
          <div className="empty-state" style={{ padding: '40px 24px', textAlign: 'center', color: 'var(--text-3)' }}>
            No webhooks yet.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}><table className="data-table">
            <thead><tr><th>Endpoint</th><th>Events</th><th>Secret</th><th>Last</th><th></th></tr></thead>
            <tbody>
              {rows.map(s => {
                const evs = typeof s.events === 'string' ? JSON.parse(s.events || '[]') : (s.events ?? [])
                return (
                  <tr key={s.id} style={{ opacity: Number(s.is_active) ? 1 : 0.55 }}>
                    <td style={{ maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={s.url}>{s.url}</td>
                    <td style={{ fontSize: 12 }}>{evs.includes('*') ? 'All' : evs.join(', ')}</td>
                    <td>
                      <button className="btn btn-ghost btn-sm" onClick={() => copy(s.secret)} title={s.secret}><Copy size={13} strokeWidth={2} /> Copy</button>
                    </td>
                    <td style={{ fontSize: 12, color: 'var(--text-3)' }}>
                      {s.last_status ? `${s.last_status} · ${s.last_delivered_at?.slice(0, 16).replace('T', ' ')}` : '—'}
                      {s.failure_count > 0 && <span style={{ color: '#dc2626', display: 'inline-flex', alignItems: 'center', gap: 3 }}> <AlertTriangle size={12} strokeWidth={2} /> {s.failure_count} fails</span>}
                    </td>
                    <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                      <button className="btn btn-ghost btn-sm" onClick={() => test(s.id)}>Test</button>
                      <button className="btn btn-ghost btn-sm" onClick={() => toggleActive(s)}>{Number(s.is_active) ? 'Pause' : 'Enable'}</button>
                      <button className="btn btn-ghost btn-sm" style={{ color: '#dc2626' }} onClick={() => remove(s.id)}>Delete</button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table></div>
        )}
      </div>
    </div>
  )
}

function Chip({ active, onClick, children }) {
  return (
    <button onClick={onClick} style={{
      padding: '5px 12px', borderRadius: 999, fontSize: 12.5, fontWeight: 600, cursor: 'pointer',
      border: '1.5px solid ' + (active ? '#08569f' : '#e5e7eb'),
      background: active ? '#e8f3fc' : '#fff', color: active ? '#08569f' : '#374151',
    }}>{children}</button>
  )
}

const lbl = { display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 6 }
