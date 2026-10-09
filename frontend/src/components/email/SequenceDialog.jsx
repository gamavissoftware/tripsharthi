import { useEffect, useState } from 'react'
import { Plus, Trash2, Repeat } from 'lucide-react'
import { emailMarketing } from '../../api/emailMarketing'
import { toast } from '../Toast'
import { ENGAGEMENT_OPTIONS, CONTACT_STATUSES } from './emailShared'

const LOCAL_TZ = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Kolkata'
const DEFAULT_DAYS = [3, 7, 12, 18]

/**
 * Schedule a nurture sequence after a campaign: each step is its own
 * scheduled campaign to the people the first email reached, filtered by
 * engagement across the sequence. The backend makes every step wait for the
 * one before it, so a large throttled send is never overtaken.
 */
export default function SequenceDialog({ campaign, onClose, onCreated }) {
  const [templates, setTemplates] = useState([])
  const [steps, setSteps]         = useState([])
  const [engagement, setEngagement] = useState('not_clicked')
  const [exclude, setExclude]     = useState(['qualified', 'won', 'lost'])
  const [sendTime, setSendTime]   = useState('10:30')
  const [saving, setSaving]       = useState(false)

  useEffect(() => {
    emailMarketing.templates().then(r => {
      const list = r.data ?? []
      setTemplates(list)
      // Pre-fill with a numbered series ("… Nurture 2 …" to "… Nurture 5 …") when one exists.
      const byNumber = n => list.find(t => new RegExp(`nurture\\s*${n}\\b`, 'i').test(t.name))
      const series = [2, 3, 4, 5].map(byNumber)
      setSteps(DEFAULT_DAYS.map((d, i) => ({ days_after: d, email_template_id: series[i]?.id ?? '' })))
    }).catch(e => toast.error('Could not load templates', e.message))
  }, [])

  const setStep = (i, patch) => setSteps(s => s.map((x, j) => (j === i ? { ...x, ...patch } : x)))

  async function save() {
    setSaving(true)
    try {
      const r = await emailMarketing.sequence(campaign.id, {
        steps: steps.map(s => ({ email_template_id: Number(s.email_template_id), days_after: Number(s.days_after) })),
        engagement, exclude_statuses: exclude, send_time: sendTime, timezone: LOCAL_TZ,
      })
      toast.success(`${r.data.length} follow-up email${r.data.length === 1 ? '' : 's'} scheduled`)
      onCreated?.(r.data)
    } catch (e) { toast.error('Could not schedule the sequence', e.message) }
    setSaving(false)
  }

  const valid = steps.length > 0 && steps.every((s, i) => s.email_template_id && Number(s.days_after) > (i ? Number(steps[i - 1].days_after) : 0))

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: 640, width: '100%' }}>
        <div className="modal-header">
          <strong className="flex items-center gap-2"><Repeat size={16} /> Follow-up sequence</strong>
        </div>
        <div className="modal-body" style={{ display: 'grid', gap: 14 }}>
          <p className="text-muted text-sm" style={{ margin: 0 }}>
            Automatic follow-ups after <strong>{campaign.name}</strong>. Days are counted from its send date; each email
            waits until the one before it has finished sending. Unsubscribes are always skipped.
          </p>

          <div style={{ display: 'grid', gap: 8 }}>
            {steps.map((s, i) => (
              <div key={i} className="flex items-center gap-2" style={{ flexWrap: 'wrap' }}>
                <span className="text-sm" style={{ width: 88 }}>Follow-up {i + 1}</span>
                <span className="text-sm text-muted">day</span>
                <input type="number" min={1} max={120} className="form-input" style={{ width: 72 }}
                       value={s.days_after} onChange={e => setStep(i, { days_after: e.target.value })} />
                <select className="form-select" style={{ flex: 1, minWidth: 220 }} value={s.email_template_id}
                        onChange={e => setStep(i, { email_template_id: e.target.value })}>
                  <option value="">— choose a template —</option>
                  {templates.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
                <button type="button" className="btn btn-ghost btn-sm" title="Remove" onClick={() => setSteps(x => x.filter((_, j) => j !== i))}><Trash2 size={14} /></button>
              </div>
            ))}
            {steps.length < 8 && (
              <button type="button" className="btn btn-ghost btn-sm" style={{ justifySelf: 'start' }}
                      onClick={() => setSteps(x => [...x, { days_after: (Number(x[x.length - 1]?.days_after) || 0) + 5, email_template_id: '' }])}>
                <Plus size={14} /> Add a step
              </button>
            )}
          </div>

          <label className="text-sm">Send each follow-up to
            <select className="form-select w-full mt-1" value={engagement} onChange={e => setEngagement(e.target.value)}>
              {ENGAGEMENT_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </label>

          <div className="text-sm">Stop following up once a contact's status is
            <div className="flex gap-2 mt-1" style={{ flexWrap: 'wrap' }}>
              {CONTACT_STATUSES.map(st => {
                const on = exclude.includes(st)
                return (
                  <button key={st} type="button" style={{ textTransform: 'capitalize' }}
                          className={`btn btn-sm ${on ? 'btn-primary' : 'btn-ghost'}`}
                          onClick={() => setExclude(x => (on ? x.filter(y => y !== st) : [...x, st]))}>{st}</button>
                )
              })}
            </div>
            <div className="text-muted" style={{ fontSize: 12, marginTop: 4 }}>Tip: when someone replies with interest, set them to Qualified and the rest of the sequence skips them.</div>
          </div>

          <label className="text-sm flex items-center gap-2">Send at
            <input type="time" className="form-input" style={{ width: 120 }} value={sendTime} onChange={e => setSendTime(e.target.value)} />
            <span className="text-muted">{LOCAL_TZ}</span>
          </label>
        </div>
        <div className="modal-footer flex gap-2" style={{ justifyContent: 'flex-end' }}>
          <button className="btn btn-ghost" onClick={onClose}>Cancel</button>
          <button className="btn btn-primary" disabled={!valid || saving} onClick={save}>{saving ? 'Scheduling…' : `Schedule ${steps.length} follow-up${steps.length === 1 ? '' : 's'}`}</button>
        </div>
      </div>
    </div>
  )
}
