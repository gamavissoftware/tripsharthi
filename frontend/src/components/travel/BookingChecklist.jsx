import { useState, useEffect, useCallback } from 'react'
import { travel } from '../../api/travel'
import { toast } from '../Toast'

function ReviewRow({ item, onChanged }) {
  const [files, setFiles] = useState(null)
  useEffect(() => { travel.portalUploads(item.id).then(setFiles).catch(() => setFiles([])) }, [item.id])
  const latest = files?.find(f => f.status === 'pending')
  if (!latest) return null
  const decide = async (decision) => {
    let reason = ''
    if (decision === 'reject') { reason = window.prompt('Why is it rejected? The customer will see this.', 'Photo is blurry'); if (!reason) return }
    try { await travel.portalReview(latest.id, decision, reason); toast.success(decision === 'accept' ? 'Accepted' : 'Sent back to the customer'); onChanged() } catch (e) { toast.error('Not saved', e.message) }
  }
  return (
    <div style={{ background: '#fef3c7', borderRadius: 8, padding: '6px 10px', margin: '2px 0 6px 26px', fontSize: 13, display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
      <span>📎 Customer uploaded <b>{latest.name}</b> ({Math.max(1, Math.round(latest.size / 1024))} KB)</span>
      <button type="button" className="btn btn-sm btn-ghost" onClick={() => travel.portalOpenUpload(latest.id).catch(e => toast.error('Could not open', e.message))}>View</button>
      <button type="button" className="btn btn-sm btn-success" onClick={() => decide('accept')}>Accept</button>
      <button type="button" className="btn btn-sm btn-ghost" onClick={() => decide('reject')}>Reject</button>
    </div>)
}

const CAT = { passport: 'Passport & ID', visa: 'Visa', forms: 'Forms', insurance: 'Insurance', other: 'Other' }

export default function BookingChecklist({ bookingId, refreshKey }) {
  const [c, setC] = useState(null)
  const load = useCallback(() => travel.checklist(bookingId).then(setC).catch(e => toast.error('Could not load the checklist', e.message)), [bookingId])
  useEffect(() => { load() }, [load, refreshKey])
  if (!c) return null

  const set = async (item, status) => {
    try { setC(await travel.checklistSet(item.id, status)) } catch (e) { toast.error('Not saved', e.message) }
  }
  const sync = async () => { try { const r = await travel.checklistGenerate(bookingId); setC(r); toast.success(r.added ? `${r.added} item(s) added` : 'Already up to date') } catch (e) { toast.error('Failed', e.message) } }
  const p = c.progress
  const groups = Object.keys(CAT).map(k => [k, c.items.filter(i => i.category === k)]).filter(([, l]) => l.length)

  return (
    <div className="card card-body">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
        <h3 style={{ margin: 0 }}>Document checklist {p.ready && <span style={{ color: 'var(--success)' }}>✓ ready</span>}</h3>
        <button className="btn btn-sm btn-ghost" onClick={sync} title="Add items for newly added travellers">Refresh list</button>
      </div>
      <div style={{ height: 8, background: 'var(--border)', borderRadius: 999, margin: '10px 0' }}><div style={{ width: `${p.percent}%`, height: '100%', background: p.ready ? 'var(--success)' : 'var(--primary, #08569f)', borderRadius: 999 }} /></div>
      <small style={{ color: 'var(--text-3)' }}>{p.done} of {p.total} required items collected</small>
      {groups.map(([k, list]) => (
        <div key={k} style={{ marginTop: 10 }}>
          <div style={{ fontSize: 11.5, textTransform: 'uppercase', color: 'var(--text-3)', fontWeight: 700 }}>{CAT[k]}</div>
          {list.map(i => (
            <div key={i.id}>
            <label style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '6px 0', borderTop: '1px solid var(--border)', opacity: i.status === 'not_applicable' ? 0.55 : 1 }}>
              <input type="checkbox" checked={i.status === 'received'} disabled={i.status === 'uploaded'} onChange={e => set(i, e.target.checked ? 'received' : 'pending')} />
              <span style={{ flex: 1, textDecoration: ['received', 'not_applicable'].includes(i.status) ? 'line-through' : 'none' }}>{i.label}{!i.required && <small style={{ color: 'var(--text-3)' }}> (optional)</small>}</span>
              {i.due_date && i.status === 'pending' && <small style={{ color: i.overdue ? 'var(--danger)' : 'var(--text-3)' }}>{i.overdue ? '⚠ was due ' : 'by '}{i.due_date}</small>}
              {i.status === 'pending' && <button type="button" className="btn btn-sm btn-ghost" onClick={(e) => { e.preventDefault(); set(i, 'not_applicable') }}>N/A</button>}
              {i.status === 'not_applicable' && <button type="button" className="btn btn-sm btn-ghost" onClick={(e) => { e.preventDefault(); set(i, 'pending') }}>undo</button>}
            </label>
            {i.status === 'uploaded' && <ReviewRow item={i} onChanged={load} />}
            </div>))}
        </div>))}
    </div>
  )
}
