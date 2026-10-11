import { useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, Trash2, XCircle } from 'lucide-react'

const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
const MAX_CHIPS = 3

// The backend stores naive UTC strings; read them as UTC, show them in the viewer's time.
function parse(s) { return s ? new Date(s.replace(' ', 'T') + 'Z') : null }
const key = (d) => `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}`
const timeOf = (d) => d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })

/** Month grid of scheduled / published posts. `posts` come from the API; `onRemove(post)` deletes one. */
export default function SocialCalendar({ posts, onRemove, renderPlatform }) {
  const today = new Date()
  const [cursor, setCursor] = useState(new Date(today.getFullYear(), today.getMonth(), 1))
  const [picked, setPicked] = useState(key(today))

  const byDay = useMemo(() => {
    const m = {}
    for (const p of posts) {
      const d = parse(p.published_at ?? p.scheduled_at)
      if (!d) continue
      ;(m[key(d)] ||= []).push({ ...p, _d: d })
    }
    for (const k of Object.keys(m)) m[k].sort((a, b) => a._d - b._d)
    return m
  }, [posts])

  const cells = useMemo(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1)
    const lead = (first.getDay() + 6) % 7 // Monday-first
    const start = new Date(first); start.setDate(1 - lead)
    return Array.from({ length: 42 }, (_, i) => { const d = new Date(start); d.setDate(start.getDate() + i); return d })
  }, [cursor])

  const step = (n) => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + n, 1))
  const monthPosts = cells.filter(d => d.getMonth() === cursor.getMonth()).reduce((n, d) => n + (byDay[key(d)]?.length ?? 0), 0)
  const dayPosts = byDay[picked] ?? []
  const lastRowEmpty = cells.slice(35).every(d => d.getMonth() !== cursor.getMonth())
  const shown = lastRowEmpty ? cells.slice(0, 35) : cells

  return (
    <div className="soc-cal">
      <div className="soc-cal-bar">
        <div className="soc-cal-nav">
          <button className="btn btn-ghost btn-sm" onClick={() => step(-1)} aria-label="Previous month"><ChevronLeft size={16} /></button>
          <h3>{cursor.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</h3>
          <button className="btn btn-ghost btn-sm" onClick={() => step(1)} aria-label="Next month"><ChevronRight size={16} /></button>
        </div>
        <div className="soc-cal-right">
          <span className="soc-count">{monthPosts} {monthPosts === 1 ? 'post' : 'posts'} this month</span>
          <button className="btn btn-ghost btn-sm" onClick={() => { setCursor(new Date(today.getFullYear(), today.getMonth(), 1)); setPicked(key(today)) }}>Today</button>
        </div>
      </div>

      <div className="soc-cal-grid">
        {DOW.map(d => <div key={d} className="soc-cal-dow">{d}</div>)}
        {shown.map(d => {
          const k = key(d), list = byDay[k] ?? []
          const out = d.getMonth() !== cursor.getMonth()
          const cls = 'soc-cal-day' + (out ? ' out' : '') + (k === key(today) ? ' today' : '') + (k === picked ? ' picked' : '')
          return (
            <button type="button" key={k + (out ? 'o' : '')} className={cls} onClick={() => setPicked(k)}>
              <span className="soc-cal-num">{d.getDate()}</span>
              <span className="soc-cal-chips">
                {list.slice(0, MAX_CHIPS).map(p => (
                  <span key={p.id} className={'soc-chip ' + p.status} title={p.message}>
                    <i /><b>{timeOf(p._d)}</b><em>{p.message}</em>
                  </span>
                ))}
                {list.length > MAX_CHIPS && <span className="soc-more">+{list.length - MAX_CHIPS} more</span>}
              </span>
              {list.length > 0 && <span className="soc-dots">{list.slice(0, 4).map(p => <i key={p.id} className={p.status} />)}</span>}
            </button>
          )
        })}
      </div>

      <div className="soc-cal-legend">
        <span><i className="scheduled" />Scheduled</span><span><i className="publishing" />Publishing</span>
        <span><i className="published" />Published</span><span><i className="failed" />Failed</span>
      </div>

      <div className="soc-cal-detail">
        <h4>{(() => { const [y, m, dd] = picked.split('-').map(Number); return new Date(y, m - 1, dd).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }) })()}</h4>
        {dayPosts.length === 0 && <p className="soc-cal-empty">No posts on this day.</p>}
        {dayPosts.map(p => (
          <div key={p.id} className="soc-cal-item">
            <span className="soc-cal-time">{timeOf(p._d)}</span>
            <span>{renderPlatform(p.platform)}</span>
            <div className="soc-cal-msg">
              <div>{p.message}</div>
              {p.status === 'failed' && p.error && <div className="soc-cal-err"><XCircle size={11} /> {p.error}</div>}
            </div>
            <span className={'soc-pill ' + p.status}>{p.status}</span>
            {p.status !== 'publishing' && <button className="btn btn-ghost btn-sm" title="Delete" onClick={() => onRemove(p)}><Trash2 size={13} /></button>}
          </div>
        ))}
      </div>
    </div>
  )
}
