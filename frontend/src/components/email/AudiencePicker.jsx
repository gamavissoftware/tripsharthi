import { useEffect, useState } from 'react'
import { Users } from 'lucide-react'
import { tags as tagsApi } from '../../api/tags'
import { segments as segmentsApi } from '../../api/segments'
import { emailMarketing } from '../../api/emailMarketing'
import { audienceKind, ENGAGEMENT_OPTIONS, CONTACT_STATUSES } from './emailShared'

const STATUSES = CONTACT_STATUSES

export default function AudiencePicker({ segment, onChange, disabled }) {
  const [tagList, setTagList] = useState([])
  const [segList, setSegList] = useState([])
  const [count, setCount]     = useState(null)
  const kind = audienceKind(segment)

  useEffect(() => {
    tagsApi.list().then(r => setTagList(r.data ?? [])).catch(() => {})
    segmentsApi.list().then(r => setSegList(r.data ?? [])).catch(() => {})
  }, [])

  const key = JSON.stringify(segment ?? {})
  useEffect(() => {
    let live = true
    const t = setTimeout(() => {
      emailMarketing.audienceCount(segment ?? { all: true })
        .then(r => { if (live) setCount(r.data) })
        .catch(() => { if (live) setCount(null) })
    }, 300)
    return () => { live = false; clearTimeout(t) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  function setKind(k) {
    if (k === 'all')     onChange({ all: true })
    if (k === 'tag')     onChange({ tag_ids: tagList[0] ? [tagList[0].id] : [] })
    if (k === 'status')  onChange({ statuses: ['new'] })
    if (k === 'segment') onChange(segList[0] ? { segment_id: segList[0].id, name: segList[0].name } : { segment_id: '' })
  }

  const selectedTags = segment?.tag_ids ?? []
  const selectedStatuses = segment?.statuses ?? (segment?.status ? [segment.status] : [])

  return (
    <div>
      <div className="flex gap-2" style={{ flexWrap: 'wrap', marginBottom: 12 }}>
        {[['all', 'All contacts'], ['tag', 'By tag'], ['status', 'By status'], ['segment', 'Saved segment']].map(([k, l]) => (
          <button key={k} type="button" disabled={disabled}
                  className={`btn btn-sm ${kind === k ? 'btn-primary' : 'btn-ghost'}`}
                  onClick={() => setKind(k)}>{l}</button>
        ))}
      </div>

      {kind === 'followup' && (
        <div className="card" style={{ background: '#f8fafc', marginBottom: 4 }}>
          <div className="card-body text-sm" style={{ display: 'grid', gap: 10 }}>
            <div>Follow-up to <strong>{segment.name ?? `campaign #${segment.followup_of}`}</strong> — only people that email actually reached.</div>
            <label>Send to
              <select className="form-select" disabled={disabled} value={segment.engagement ?? 'not_clicked'}
                      onChange={e => onChange({ ...segment, engagement: e.target.value })} style={{ marginTop: 4 }}>
                {ENGAGEMENT_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </label>
            <div>Skip contacts whose status is
              <div className="flex gap-2 mt-1" style={{ flexWrap: 'wrap' }}>
                {STATUSES.map(st => {
                  const ex = segment.exclude_statuses ?? []
                  const on = ex.includes(st)
                  return (
                    <button key={st} type="button" disabled={disabled} style={{ textTransform: 'capitalize' }}
                            className={`btn btn-sm ${on ? 'btn-primary' : 'btn-ghost'}`}
                            onClick={() => onChange({ ...segment, exclude_statuses: on ? ex.filter(x => x !== st) : [...ex, st] })}>{st}</button>
                  )
                })}
              </div>
            </div>
          </div>
        </div>
      )}

      {kind === 'tag' && (
        <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
          {tagList.length === 0 && <span className="text-muted text-sm">No tags yet.</span>}
          {tagList.map(t => {
            const on = selectedTags.includes(t.id)
            return (
              <button key={t.id} type="button" disabled={disabled}
                      className={`btn btn-sm ${on ? 'btn-primary' : 'btn-ghost'}`}
                      onClick={() => onChange({ tag_ids: on ? selectedTags.filter(x => x !== t.id) : [...selectedTags, t.id] })}>
                {t.name}
              </button>
            )
          })}
        </div>
      )}

      {kind === 'status' && (
        <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
          {STATUSES.map(s => {
            const on = selectedStatuses.includes(s)
            return (
              <button key={s} type="button" disabled={disabled}
                      className={`btn btn-sm ${on ? 'btn-primary' : 'btn-ghost'}`} style={{ textTransform: 'capitalize' }}
                      onClick={() => onChange({ statuses: on ? selectedStatuses.filter(x => x !== s) : [...selectedStatuses, s] })}>
                {s}
              </button>
            )
          })}
        </div>
      )}

      {kind === 'segment' && (
        <select className="form-select" disabled={disabled} value={segment?.segment_id ?? ''}
                onChange={e => {
                  const s = segList.find(x => String(x.id) === e.target.value)
                  onChange(s ? { segment_id: s.id, name: s.name } : { segment_id: '' })
                }}>
          <option value="">— choose a segment —</option>
          {segList.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
      )}

      <div className="flex items-center gap-2 text-sm" style={{ marginTop: 12, color: '#374151' }}>
        <Users size={14} />
        {count === null ? <span className="text-muted">Counting…</span> : (
          <span>
            <strong>{count.sendable.toLocaleString()}</strong> will receive it
            <span className="text-muted"> · {count.with_email.toLocaleString()} have an email
              {count.suppressed > 0 && <> · {count.suppressed.toLocaleString()} unsubscribed/suppressed</>}</span>
          </span>
        )}
      </div>
    </div>
  )
}
