import { useState, useEffect, useRef } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'
import { firstInvalid, shake } from '../lib/formFocus'
import ContactPicker from '../components/crm/ContactPicker'
import { CalendarDays } from 'lucide-react'
import { StatRow, StatTile } from '../components/StatTile'
import { CalendarDays as IcCal, Clock as IcClock } from 'lucide-react'

const STATUS = {
  scheduled: { label: 'Scheduled', bg: '#e8f3fc', color: '#074a8c' },
  completed: { label: 'Completed', bg: '#dcfce7', color: '#15803d' },
  canceled:  { label: 'Canceled',  bg: '#fee2e2', color: '#b91c1c' },
}

/**
 * The API stores and returns UTC as 'YYYY-MM-DD HH:MM:SS', with no zone marker.
 * JavaScript parses a string like that as LOCAL time, so reading it straight
 * shifts every meeting by the browser's offset — a 9:30 AM IST booking renders
 * as 4:00 am. Mark it as UTC explicitly and let toLocale* do the conversion.
 */
function utcToDate(dt) {
  if (!dt) return null
  const iso = String(dt).trim().replace(' ', 'T')
  const hasZone = /([zZ]|[+-]\d{2}:?\d{2})$/.test(iso)
  const d = new Date(hasZone ? iso : iso + 'Z')
  return Number.isNaN(d.getTime()) ? null : d
}

/**
 * The inverse, for the create form: <input type="datetime-local"> yields local
 * wall-clock, which must be converted to UTC before it is stored — otherwise a
 * meeting typed as 9:30 is saved as 9:30 UTC and shows up at 3:00 PM IST.
 */
function localInputToUtc(v) {
  if (!v) return null
  const d = new Date(v)
  if (Number.isNaN(d.getTime())) return null
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())} `
       + `${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:00`
}

function dayKey(dt) {
  const d = utcToDate(dt)
  return d ? d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : '—'
}
function timeLabel(dt) {
  const d = utcToDate(dt)
  return d ? d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }) : '—'
}

export default function MeetingsPage() {
  const [meetings, setMeetings] = useState([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState({ title: '', start_at: '', end_at: '', location: '', notes: '', contact_id: '' })
  const [saving, setSaving] = useState(false)
  const [endErr, setEndErr] = useState('')
  const formRef = useRef(null)

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const r = await crm.listMeetings()
      setMeetings(r.data ?? [])
    } catch (e) { toast.error('Failed to load meetings', e.message) }
    finally { setLoading(false) }
  }

  async function add(e) {
    e.preventDefault()
    if (!form.title.trim()) { toast.error('Meeting title is required'); return }
    if (!form.start_at)     { toast.error('Start time is required'); return }
    if (form.end_at && form.end_at <= form.start_at) {
      const msg = 'The end must be after the start'
      setEndErr(msg); toast.warning('One thing needs fixing', msg)
      setTimeout(() => { firstInvalid(formRef.current); shake(formRef.current) }, 0)
      return
    }
    setSaving(true)
    try {
      const payload = {
        title:    form.title.trim(),
        start_at: localInputToUtc(form.start_at),
        end_at:   localInputToUtc(form.end_at),
        location: form.location.trim() || null,
        notes:    form.notes.trim() || null,
        contact_id: form.contact_id ? Number(form.contact_id) : null,
      }
      await crm.createMeeting(payload)
      setForm({ title: '', start_at: '', end_at: '', location: '', notes: '', contact_id: '' })
      toast.success('Meeting scheduled')
      await load()
    } catch (e) { toast.error('Could not schedule meeting', e.message) }
    finally { setSaving(false) }
  }

  async function setStatus(id, status) {
    try { await crm.updateMeeting(id, { status }); await load() }
    catch (e) { toast.error('Could not update', e.message) }
  }

  async function remove(id) {
    try { await crm.deleteMeeting(id); setMeetings(m => m.filter(x => x.id !== id)) }
    catch (e) { toast.error('Could not delete', e.message) }
  }

  // Group by day for an agenda view.
  const groups = {}
  for (const m of meetings) {
    const k = dayKey(m.start_at)
    ;(groups[k] ||= []).push(m)
  }

  return (
    <div className="page page-wide">
      <div style={{ marginBottom: '.9rem' }}>
        <h1 className="ps-h1"><span className="ps-ico"><CalendarDays size={18} /></span>Meetings</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Your agenda for the next 30 days. Booking a meeting can fire a flow.</p>
      </div>
      {!loading && meetings.length > 0 && (() => {
        const t0 = new Date(); t0.setHours(0, 0, 0, 0)
        const dd = (m) => { const d = utcToDate(m.start_at); return d ? Math.floor((new Date(d).setHours(0, 0, 0, 0) - t0) / 86400000) : null }
        const nToday = meetings.filter(m => dd(m) === 0).length
        const nWeek = meetings.filter(m => { const d = dd(m); return d !== null && d >= 0 && d < 7 }).length
        const next = meetings.map(m => utcToDate(m.start_at)).filter(d => d && d > new Date()).sort((a, b) => a - b)[0]
        return (
          <StatRow>
            <StatTile icon={IcCal} tone="#0a6cc4" label="Today" value={nToday} />
            <StatTile icon={IcCal} tone="#12a89e" label="Next 7 days" value={nWeek} />
            <StatTile icon={IcClock} tone="#f98f26" label="Next meeting" value={next ? next.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }) : '—'} sub={next ? next.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }) : 'nothing booked'} />
            <StatTile icon={IcCal} tone="#6366f1" label="Next 30 days" value={meetings.length} />
          </StatRow>)
      })()}

      {/* Quick add */}
      <form ref={formRef} onSubmit={add} style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, padding: '.85rem', marginBottom: '1rem', display: 'grid', gap: 8 }}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <input aria-label="Meeting title" required maxLength={190} value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
            placeholder="Meeting title…" style={{ flex: '2 1 220px', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem' }} />
          <input aria-label="Location or link" maxLength={255} value={form.location} onChange={e => setForm(f => ({ ...f, location: e.target.value }))}
            placeholder="Location / link" style={{ flex: '1 1 160px', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem' }} />
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
          <label style={{ fontSize: '.78rem', color: '#6b7280' }}>Start *
            <input type="datetime-local" required value={form.start_at} onChange={e => { setForm(f => ({ ...f, start_at: e.target.value })); setEndErr('') }}
              style={{ display: 'block', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.4rem .5rem', fontSize: '.85rem' }} />
          </label>
          <label style={{ fontSize: '.78rem', color: '#6b7280' }}>End
            <input type="datetime-local" className={endErr ? 'is-invalid' : ''} aria-invalid={endErr ? 'true' : undefined} value={form.end_at} onChange={e => { setForm(f => ({ ...f, end_at: e.target.value })); setEndErr('') }}
              style={{ display: 'block', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.4rem .5rem', fontSize: '.85rem' }} />
            {endErr && <div className="ff-err" role="alert" style={{ marginTop: 4 }}>{endErr}</div>}
          </label>
          <ContactPicker value={form.contact_id ? Number(form.contact_id) : null}
            onChange={(id) => setForm(f => ({ ...f, contact_id: id || '' }))}
            placeholder="Link a contact (optional)" />
          <button type="submit" disabled={saving} style={{ marginLeft: 'auto', background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.5rem 1.1rem', fontSize: '.85rem', fontWeight: 600, cursor: 'pointer' }}>
            {saving ? '…' : 'Schedule'}
          </button>
        </div>
      </form>

      {/* Agenda */}
      {loading ? (
        <div style={{ height: 64, borderRadius: 12, background: '#f3f4f6', animation: 'lp-pulse 1.2s infinite' }} />
      ) : meetings.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 12, border: '2px dashed #e5e7eb' }}>
          <div style={{ fontSize: '2.5rem' }}>📅</div>
          <h3 style={{ fontWeight: 800, fontSize: '1rem', margin: '.5rem 0 .25rem' }}>Nothing scheduled</h3>
          <p style={{ color: '#6b7280', fontSize: '.85rem' }}>No meetings in the next 30 days.</p>
        </div>
      ) : (
        Object.entries(groups).map(([day, rows]) => (
          <div key={day} style={{ marginBottom: '1.25rem' }}>
            <div style={{ fontSize: '.78rem', fontWeight: 700, color: '#64748b', textTransform: 'uppercase', letterSpacing: '.04em', margin: '0 0 .4rem .2rem' }}>{day}</div>
            <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, overflow: 'hidden' }}>
              {rows.map((m, i) => {
                const st = STATUS[m.status] ?? STATUS.scheduled
                return (
                  <div key={m.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '.8rem 1rem', borderTop: i ? '1px solid #f1f5f9' : 'none' }}>
                    <div style={{ width: 64, flexShrink: 0, fontSize: '.8rem', fontWeight: 700, color: '#334155' }}>
                      {timeLabel(m.start_at)}
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontWeight: 600, fontSize: '.92rem', color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{m.title}</div>
                      <div style={{ fontSize: '.74rem', color: '#94a3b8' }}>
                        {m.location ? `📍 ${m.location}` : ''}{m.location && m.contact_id ? ' · ' : ''}{m.contact_id ? `contact #${m.contact_id}` : ''}
                      </div>
                    </div>
                    <span style={{ background: st.bg, color: st.color, borderRadius: 999, padding: '.12rem .55rem', fontSize: '.7rem', fontWeight: 700 }}>{st.label}</span>
                    {m.status === 'scheduled' && (
                      <>
                        <button onClick={() => setStatus(m.id, 'completed')} title="Mark completed" style={iconBtn}>✓</button>
                        <button onClick={() => setStatus(m.id, 'canceled')} title="Cancel" style={iconBtn}>⊘</button>
                      </>
                    )}
                    <button onClick={() => remove(m.id)} title="Delete" style={{ ...iconBtn, color: '#cbd5e1' }}>✕</button>
                  </div>
                )
              })}
            </div>
          </div>
        ))
      )}
      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>
    </div>
  )
}

const iconBtn = { background: 'none', border: 'none', color: '#94a3b8', cursor: 'pointer', fontSize: '1rem', padding: '0 .15rem' }
