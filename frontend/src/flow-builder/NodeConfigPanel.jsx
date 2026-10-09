/**
 * Config panel for the selected flow node.
 *
 * ALL string values emitted for operator/type/unit/status/match
 * use EXACTLY the vocabulary FlowEngine::executeNode() switches on.
 * Any drift between a panel label and the engine string causes silent mis-routing.
 */
import { useState, useEffect } from 'react'
import { api } from '../api/client'

// ── Exact engine vocabulary ────────────────────────────────────────────
const CONTACT_STATUS_OPTIONS  = ['new','contacted','qualified','won','lost']
const DELAY_UNITS              = ['seconds','minutes','hours','days']

const CONDITION_TYPES = [
  { value:'contact_field', label:'Contact field' },
  { value:'custom_field',  label:'Custom field' },
  { value:'state',         label:'Run state variable' },
  { value:'tag',           label:'Tag membership' },
]

// These values MUST match FlowEngine::execCondition() match($operator)
const STANDARD_OPERATORS = [
  { value:'equals',       label:'equals' },
  { value:'not_equals',   label:'does not equal' },
  { value:'contains',     label:'contains' },
  { value:'starts_with',  label:'starts with' },
  { value:'ends_with',    label:'ends with' },
  { value:'is_empty',     label:'is empty' },
  { value:'is_not_empty', label:'is not empty' },
]
const TAG_OPERATORS = [
  { value:'has_tag',     label:'has tag' },
  { value:'not_has_tag', label:'does not have tag' },
]

// Standard contact fields FlowEngine reads from the contact row
const CONTACT_FIELDS = ['status','source','name','email','wa_number','opt_in']

// ── Helpers ────────────────────────────────────────────────────────────
const inp = {
  display:'block', width:'100%', padding:'6px 8px',
  border:'1px solid #d1d5db', borderRadius:6, fontSize:13, marginTop:4,
  boxSizing:'border-box',
}
const lbl = { fontSize:12, color:'#6b7280', fontWeight:500, marginTop:10, display:'block' }
const row = { marginBottom:8 }

function Field({ label, children }) {
  return (
    <div style={row}>
      <span style={lbl}>{label}</span>
      {children}
    </div>
  )
}

// ── Main panel ─────────────────────────────────────────────────────────
export default function NodeConfigPanel({ node, onChange, readOnly, tags, users }) {
  const [templates, setTemplates] = useState([])
  const [cfKeys, setCfKeys]       = useState([])

  const [products, setProducts] = useState([])

  useEffect(() => {
    api.get('/templates?meta_status=approved').then(r => setTemplates(r.data ?? [])).catch(() => {})
    api.get('/custom-fields').then(r => setCfKeys(r.data ?? [])).catch(() => {})
    api.get('/products').then(r => setProducts(r.data ?? [])).catch(() => {})
  }, [])

  if (!node) {
    return (
      <div style={{ padding:16, color:'#9ca3af', fontSize:13, textAlign:'center' }}>
        Click a node to configure it
      </div>
    )
  }

  const { type, data } = node

  function set(key, val) {
    if (readOnly) return
    onChange({ ...data, [key]: val })
  }

  // ── Trigger nodes ─────────────────────────────────────────────────
  if (['lead_created','form_submitted','meta_lead_received','google_lead_received','inbound_message','deal_created','deal_stage_changed','deal_won','deal_lost','ticket_created','ticket_resolved','task_due'].includes(type)) {
    const isDeal = type.startsWith('deal_'); const isTicket = type.startsWith('ticket_')
    return (
      <div style={{ padding:12 }}>
        <p style={{ fontSize:12, color:'#6b7280' }}>
          {isDeal ? 'Fires for the deal’s primary contact. The flow’s WhatsApp/CRM actions run against that contact.'
            : isTicket ? 'Fires for the ticket’s contact — e.g. send a CSAT survey when a ticket is resolved.'
            : 'No additional configuration needed for this trigger. Set trigger-specific conditions in the Flow Settings panel above.'}
        </p>
      </div>
    )
  }

  if (type === 'create_ticket') {
    return (
      <div style={{ padding:12, display:'grid', gap:10 }}>
        <label style={lbl}>Subject
          <input style={inp} value={data.subject ?? ''} disabled={readOnly} onChange={e => set('subject', e.target.value)} placeholder="Support request" />
        </label>
        <label style={lbl}>Priority
          <select style={inp} value={data.priority ?? 'medium'} disabled={readOnly} onChange={e => set('priority', e.target.value)}>
            {['low','medium','high','urgent'].map(p => <option key={p} value={p}>{p}</option>)}
          </select>
        </label>
        <p style={{ fontSize:11.5, color:'#9ca3af', margin:0 }}>Opens a ticket for the contact (source = WhatsApp), with an SLA based on priority.</p>
      </div>
    )
  }

  if (type === 'send_email') {
    return <SendEmailConfig data={data} set={set} readOnly={readOnly} lbl={lbl} inp={inp} />
  }

  // ── CRM actions (Phase C) ─────────────────────────────────────────
  if (type === 'create_task') {
    return (
      <div style={{ padding:12, display:'grid', gap:10 }}>
        <label style={lbl}>Task title
          <input style={inp} value={data.title ?? ''} disabled={readOnly} onChange={e => set('title', e.target.value)} placeholder="Follow up" />
        </label>
        <label style={lbl}>Type
          <select style={inp} value={data.type ?? 'todo'} disabled={readOnly} onChange={e => set('type', e.target.value)}>
            {['todo','call','whatsapp','email','meeting'].map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </label>
        <label style={lbl}>Priority
          <select style={inp} value={data.priority ?? 'medium'} disabled={readOnly} onChange={e => set('priority', e.target.value)}>
            {['low','medium','high'].map(t => <option key={t} value={t}>{t}</option>)}
          </select>
        </label>
        <label style={lbl}>Due in (days)
          <input type="number" style={inp} value={data.due_in_days ?? 0} disabled={readOnly} onChange={e => set('due_in_days', Number(e.target.value))} />
        </label>
      </div>
    )
  }

  if (type === 'update_field') {
    const FIELDS = { lifecycle_stage: ['subscriber','lead','mql','sql','opportunity','customer','evangelist','other'], status: ['new','contacted','qualified','won','lost'] }
    const opts = FIELDS[data.field] ?? null
    return (
      <div style={{ padding:12, display:'grid', gap:10 }}>
        <label style={lbl}>Field
          <select style={inp} value={data.field ?? 'lifecycle_stage'} disabled={readOnly} onChange={e => set('field', e.target.value)}>
            <option value="lifecycle_stage">Lifecycle stage</option>
            <option value="status">Status</option>
            <option value="owner_id">Owner (user id)</option>
            <option value="lead_score">Lead score</option>
          </select>
        </label>
        <label style={lbl}>Value
          {opts
            ? <select style={inp} value={data.value ?? ''} disabled={readOnly} onChange={e => set('value', e.target.value)}>{opts.map(o => <option key={o} value={o}>{o}</option>)}</select>
            : <input style={inp} value={data.value ?? ''} disabled={readOnly} onChange={e => set('value', e.target.value)} />}
        </label>
      </div>
    )
  }

  if (type === 'create_deal') {
    return (
      <div style={{ padding:12, display:'grid', gap:10 }}>
        <label style={lbl}>Deal title <span style={{ color:'#9ca3af', fontWeight:400 }}>(blank = “Deal — contact name”)</span>
          <input style={inp} value={data.title ?? ''} disabled={readOnly} onChange={e => set('title', e.target.value)} placeholder="New opportunity" />
        </label>
        <label style={lbl}>Value (₹)
          <input type="number" style={inp} value={(data.value_amount ?? 0) / 100} disabled={readOnly} onChange={e => set('value_amount', Math.round(parseFloat(e.target.value || 0) * 100))} />
        </label>
        <p style={{ fontSize:11.5, color:'#9ca3af', margin:0 }}>Created in your default pipeline’s first stage, linked to the contact.</p>
      </div>
    )
  }

  if (type === 'tag_added' || type === 'keyword_reply') {
    return (
      <div style={{ padding:12 }}>
        <p style={{ fontSize:12, color:'#6b7280' }}>
          Configure the matching rule in Flow Settings ↑
        </p>
      </div>
    )
  }

  // ── send_template ──────────────────────────────────────────────────
  if (type === 'send_template') {
    const varMap = (() => { try { return JSON.parse(data.variable_mapping || '{}') } catch { return {} } })()
    const varDef = (() => { try { return JSON.parse(data.variable_defaults || '{}') } catch { return {} } })()

    const selectedTpl = templates.find(t => String(t.id) === String(data.template_id))
    // Detect {{n}} variables in body
    const varIndices = selectedTpl
      ? [...new Set([...(selectedTpl.body ?? '').matchAll(/\{\{(\d+)\}\}/g)].map(m => m[1]))]
      : []

    function setVarMap(idx, field) {
      const m = { ...varMap, [idx]: field }
      set('variable_mapping', JSON.stringify(m))
    }
    function setVarDef(idx, val) {
      const d = { ...varDef, [idx]: val }
      set('variable_defaults', JSON.stringify(d))
    }

    return (
      <div style={{ padding:12 }}>
        <Field label="Template (approved only)">
          <select style={inp} value={data.template_id ?? ''} onChange={e => {
            const t = templates.find(x => String(x.id) === e.target.value)
            set('template_id', parseInt(e.target.value, 10))
            if (t) set('template_name', t.name)
          }} disabled={readOnly}>
            <option value="">— select —</option>
            {templates.map(t => (
              <option key={t.id} value={t.id}>{t.display_name || t.name} [{t.category}]</option>
            ))}
          </select>
        </Field>

        {varIndices.length > 0 && (
          <>
            <span style={lbl}>{'Variable mapping ({{n}}) → contact field'}</span>
            <table style={{ width:'100%', fontSize:12, borderCollapse:'collapse', marginTop:4 }}>
              <thead>
                <tr>
                  <th style={{ textAlign:'left', color:'#9ca3af', paddingBottom:4 }}>Var</th>
                  <th style={{ textAlign:'left', color:'#9ca3af', paddingBottom:4 }}>From field</th>
                  <th style={{ textAlign:'left', color:'#9ca3af', paddingBottom:4 }}>Fallback</th>
                </tr>
              </thead>
              <tbody>
                {varIndices.map(idx => (
                  <tr key={idx}>
                    <td style={{ color:'#08569f', fontWeight:600, paddingRight:6 }}>{`{{${idx}}}`}</td>
                    <td style={{ paddingRight:6 }}>
                      <select style={{ ...inp, marginTop:0, fontSize:12 }} value={varMap[idx] ?? ''} onChange={e => setVarMap(idx, e.target.value)} disabled={readOnly}>
                        <option value="">— none —</option>
                        {CONTACT_FIELDS.map(f => <option key={f} value={f}>{f}</option>)}
                        {cfKeys.map(cf => <option key={cf.field_key} value={cf.field_key}>{cf.label}</option>)}
                        {TRAVEL_STATE_VARS.map(([group, opts]) => (
                          <optgroup key={group} label={`Travel · ${group} (travel triggers)`}>
                            {opts.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                          </optgroup>
                        ))}
                      </select>
                    </td>
                    <td>
                      <input style={{ ...inp, marginTop:0, fontSize:12 }} value={varDef[idx] ?? ''} placeholder="fallback ('there')" onChange={e => setVarDef(idx, e.target.value)} readOnly={readOnly} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p style={{ fontSize:11, color:'#9ca3af', marginTop:4 }}>
              Blank fallback defaults to "there" (engine never sends empty variable).
            </p>
          </>
        )}
      </div>
    )
  }

  // ── send_freeform ──────────────────────────────────────────────────
  if (type === 'send_freeform') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Message content">
          <textarea style={{ ...inp, minHeight:80, resize:'vertical' }} value={data.content ?? ''} onChange={e => set('content', e.target.value)} readOnly={readOnly} />
        </Field>
        <div style={{ marginTop:6 }}>
          <span style={{ fontSize:11, color:'#6b7280' }}>Insert (travel triggers):</span>
          <div style={{ display:'flex', flexWrap:'wrap', gap:4, marginTop:4 }}>
            {['{{contact.name}}', ...TRAVEL_TOKENS].map(t => (
              <button key={t} type="button" disabled={readOnly} onClick={() => set('content', (data.content ?? '') + t)}
                style={{ fontSize:10.5, padding:'1px 6px', border:'1px solid #d1d5db', borderRadius:10, background:'#f9fafb', cursor:'pointer', color:'#074a8c' }}>{t}</button>
            ))}
          </div>
        </div>
        <p style={{ fontSize:11, color:'#f97316', marginTop:8 }}>
          ⚠ Only sent when the 24h service window is open.<br/>
          Wire the orange "window↓" handle to a send_template node as a fallback.
        </p>
      </div>
    )
  }

  // ── send_media ─────────────────────────────────────────────────────
  if (type === 'send_media') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Media type">
          <select style={inp} value={data.type ?? 'image'} onChange={e => set('type', e.target.value)} disabled={readOnly}>
            <option value="image">Image</option>
            <option value="document">Document</option>
            <option value="video">Video</option>
          </select>
        </Field>
        <Field label="Public URL">
          <input style={inp} value={data.url ?? ''} placeholder="https://..." onChange={e => set('url', e.target.value)} readOnly={readOnly} />
        </Field>
        <Field label="Caption (optional)">
          <input style={inp} value={data.caption ?? ''} onChange={e => set('caption', e.target.value)} readOnly={readOnly} />
        </Field>
        <p style={{ fontSize:11, color:'#f97316', marginTop:4 }}>
          ⚠ Window-gated. Wire the orange "window↓" handle to a template fallback.
        </p>
      </div>
    )
  }

  // ── add_tag / remove_tag ───────────────────────────────────────────
  if (type === 'add_tag' || type === 'remove_tag') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Tag">
          <select style={inp} value={data.tag_id ?? ''} onChange={e => {
            const t = tags.find(x => String(x.id) === e.target.value)
            set('tag_id', parseInt(e.target.value, 10))
            if (t) set('tag_name', t.name)
          }} disabled={readOnly}>
            <option value="">— select tag —</option>
            {tags.map(t => <option key={t.id} value={t.id}>#{t.name}</option>)}
          </select>
        </Field>
      </div>
    )
  }

  // ── update_status ──────────────────────────────────────────────────
  if (type === 'update_status') {
    return (
      <div style={{ padding:12 }}>
        <Field label="New status">
          <select style={inp} value={data.status ?? 'contacted'} onChange={e => set('status', e.target.value)} disabled={readOnly}>
            {CONTACT_STATUS_OPTIONS.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </Field>
      </div>
    )
  }

  // ── assign_agent ───────────────────────────────────────────────────
  if (type === 'assign_agent') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Agent (user ID)">
          <input type="number" style={inp} value={data.user_id ?? ''} min={1} onChange={e => set('user_id', parseInt(e.target.value, 10) || 0)} readOnly={readOnly} />
        </Field>
        <p style={{ fontSize:11, color:'#9ca3af' }}>User management UI: Sprint 7</p>
      </div>
    )
  }

  // ── notify_number ──────────────────────────────────────────────────
  // Alerts the business's own phone, not the contact. Each body variable
  // of the approved template is a free-text parameter that may contain
  // tokens; the engine resolves them (FlowEngine::resolveAlertToken).
  if (type === 'notify_number') {
    const params = Array.isArray(data.params)
      ? data.params
      : (() => { try { return JSON.parse(data.params || '[]') } catch { return [] } })()
    const selectedTpl = templates.find(t => String(t.id) === String(data.template_id))
    const varIndices = selectedTpl
      ? [...new Set([...(selectedTpl.body ?? '').matchAll(/\{\{(\d+)\}\}/g)].map(m => parseInt(m[1], 10)))].sort((a, b) => a - b)
      : []
    function setParam(i, val) {
      const next = [...params]
      while (next.length < i + 1) next.push('')
      next[i] = val
      set('params', next)
    }
    const TOKENS = [
      ['{{contact.name}}', 'lead name'], ['{{contact.wa_number}}', 'WhatsApp number'], ['{{contact.email}}', 'email'],
      ['{{contact.company}}', 'company'], ['{{lead.source}}', 'source, e.g. Meta Lead Ads'], ['{{lead.answers}}', 'every form answer, one line'],
      ['{{custom.<field_key>}}', 'one custom field'], ['{{button_title}}', 'button just tapped'], ['{{slot}}', 'slot just booked'],
    ]
    return (
      <div style={{ padding:12 }}>
        <Field label="Alert this WhatsApp number">
          <input style={inp} value={data.to ?? ''} placeholder="+919718991797" onChange={e => set('to', e.target.value)} readOnly={readOnly} />
        </Field>
        <Field label="Alert template (approved, usually utility)">
          <select style={inp} value={data.template_id ?? ''} onChange={e => set('template_id', parseInt(e.target.value, 10))} disabled={readOnly}>
            <option value="">— select —</option>
            {templates.map(t => (
              <option key={t.id} value={t.id}>{t.display_name || t.name} [{t.category}]</option>
            ))}
          </select>
        </Field>
        {selectedTpl && (
          <pre style={{ whiteSpace:'pre-wrap', fontSize:11, color:'#6b7280', background:'#f9fafb', border:'1px solid #e5e7eb', borderRadius:6, padding:8, margin:'6px 0 0' }}>
            {selectedTpl.body}
          </pre>
        )}
        {varIndices.length > 0 && (
          <>
            <span style={lbl}>What goes into each variable</span>
            {varIndices.map(idx => (
              <div key={idx} style={{ display:'flex', gap:6, alignItems:'center', marginTop:4 }}>
                <span style={{ color:'#08569f', fontWeight:600, fontSize:12, width:36, flexShrink:0 }}>{`{{${idx}}}`}</span>
                <input style={{ ...inp, marginTop:0, fontSize:12 }} value={params[idx - 1] ?? ''}
                  placeholder="{{contact.name}} or free text with tokens"
                  onChange={e => setParam(idx - 1, e.target.value)} readOnly={readOnly} />
              </div>
            ))}
          </>
        )}
        <span style={lbl}>Tokens you can use (also inside a sentence)</span>
        <div style={{ fontSize:11, color:'#6b7280', lineHeight:1.6 }}>
          {TOKENS.map(([t, d]) => (
            <div key={t}><code style={{ background:'#f3f4f6', borderRadius:4, padding:'0 4px' }}>{t}</code> — {d}</div>
          ))}
        </div>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:8 }}>
          Nothing is written to the lead's conversation; a blank value is sent as "-" because Meta rejects empty variables.
        </p>
      </div>
    )
  }

  // ── send_slots / book_slot ─────────────────────────────────────────
  if (type === 'send_slots') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Message above the list">
          <textarea style={{ ...inp, minHeight:80 }} value={data.body ?? ''} onChange={e => set('body', e.target.value)} readOnly={readOnly}
            placeholder="Here are the next few openings — tap the one that suits you." />
        </Field>
        <div style={{ display:'flex', gap:8 }}>
          <Field label="How many slots"><input type="number" min="1" max="10" style={inp} value={data.count ?? 6} onChange={e => set('count', parseInt(e.target.value, 10) || 6)} readOnly={readOnly} /></Field>
          <Field label="Timezone"><input style={inp} value={data.timezone ?? 'Asia/Kolkata'} onChange={e => set('timezone', e.target.value)} readOnly={readOnly} /></Field>
        </div>
        <div style={{ display:'flex', gap:8 }}>
          <Field label="List button text (≤20)"><input style={inp} maxLength={20} value={data.button_text ?? 'See times'} onChange={e => set('button_text', e.target.value)} readOnly={readOnly} /></Field>
          <Field label="Section title (≤24)"><input style={inp} maxLength={24} value={data.section_title ?? 'Available'} onChange={e => set('section_title', e.target.value)} readOnly={readOnly} /></Field>
        </div>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:4 }}>
          Slots come from Settings → Business hours at send time. Free-form message: the 24h window must be open. Connect <b>tapped</b> to a Book Tapped Slot node.
        </p>
      </div>
    )
  }
  if (type === 'book_slot') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Meeting title"><input style={inp} value={data.title ?? ''} placeholder="Intro call with Gamavis" onChange={e => set('title', e.target.value)} readOnly={readOnly} /></Field>
        <Field label="Timezone"><input style={inp} value={data.timezone ?? 'Asia/Kolkata'} onChange={e => set('timezone', e.target.value)} readOnly={readOnly} /></Field>
        <Field label="Confirmation text (optional; {{slot}} and {{link}} are filled in)">
          <textarea style={{ ...inp, minHeight:60 }} value={data.confirm_text ?? ''} onChange={e => set('confirm_text', e.target.value)} readOnly={readOnly} placeholder="Done — you're booked for {{slot}}." />
        </Field>
        <Field label="Text when the slot was just taken (optional)">
          <textarea style={{ ...inp, minHeight:48 }} value={data.failed_text ?? ''} onChange={e => set('failed_text', e.target.value)} readOnly={readOnly} />
        </Field>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:4 }}>
          Later nodes can use <code>{'{{slot}}'}</code> (e.g. an Alert My Number node).
        </p>
      </div>
    )
  }

  // ── handoff ────────────────────────────────────────────────────────
  if (type === 'handoff') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Agent (user ID) — leave blank for auto-routing">
          <input type="number" style={inp} value={data.user_id ?? ''} min={1}
            placeholder="Auto (routing rules / least-loaded)"
            onChange={e => set('user_id', parseInt(e.target.value, 10) || 0)} readOnly={readOnly} />
        </Field>
        <label style={{ display:'flex', alignItems:'center', gap:8, fontSize:13, marginTop:8 }}>
          <input type="checkbox" checked={!!data.stop}
            onChange={e => set('stop', e.target.checked)} disabled={readOnly} />
          Stop the flow after handoff (let the human take over)
        </label>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:8 }}>
          Blank agent → assigns via your inbox routing rules, falling back to the
          least-loaded agent. The conversation is flagged for a human in the inbox.
        </p>
      </div>
    )
  }

  // ── send_payment ───────────────────────────────────────────────────
  if (type === 'send_payment') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Amount (₹)">
          <input type="number" style={inp} value={data.amount ?? ''} min={1} step="0.01"
            placeholder="e.g. 499"
            onChange={e => set('amount', parseFloat(e.target.value) || 0)} readOnly={readOnly} />
        </Field>
        <Field label="Description">
          <input style={inp} value={data.description ?? ''} maxLength={255}
            placeholder="e.g. Order #1234"
            onChange={e => set('description', e.target.value)} readOnly={readOnly} />
        </Field>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:8 }}>
          Creates a Razorpay payment link and sends it as a WhatsApp message.
          Delivery needs an open 24h window — place a Window Check before this node
          for strict compliance. Connect Razorpay in Settings → Payments.
        </p>
      </div>
    )
  }

  // ── send_product ───────────────────────────────────────────────────
  if (type === 'send_product') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Product">
          {products.length > 0 ? (
            <select style={inp} value={data.retailer_id ?? ''}
              onChange={e => set('retailer_id', e.target.value)} disabled={readOnly}>
              <option value="">— choose product —</option>
              {products.map(p => <option key={p.id} value={p.retailer_id}>{p.name} ({p.retailer_id})</option>)}
            </select>
          ) : (
            <input style={inp} value={data.retailer_id ?? ''} placeholder="product SKU (retailer_id)"
              onChange={e => set('retailer_id', e.target.value)} readOnly={readOnly} />
          )}
        </Field>
        <Field label="Message (optional)">
          <input style={inp} value={data.body ?? ''} maxLength={1024}
            placeholder="e.g. Check out our bestseller!"
            onChange={e => set('body', e.target.value)} readOnly={readOnly} />
        </Field>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:8 }}>
          Sends a catalog product card. Needs an open 24h window and a connected
          Meta catalog (Products page). Add products under Products.
        </p>
      </div>
    )
  }

  // ── ai_reply ───────────────────────────────────────────────────────
  if (type === 'ai_reply') {
    return (
      <div style={{ padding:12 }}>
        <Field label="System prompt (persona & instructions)">
          <textarea style={{ ...inp, minHeight: 90, resize: 'vertical' }} value={data.system_prompt ?? ''}
            placeholder="e.g. You are a friendly support agent for Acme. Answer questions about orders and shipping. Keep replies under 3 sentences."
            onChange={e => set('system_prompt', e.target.value)} readOnly={readOnly} />
        </Field>
        <Field label="Max reply length (tokens)">
          <input type="number" style={inp} value={data.max_tokens ?? 400} min={50} max={1024}
            onChange={e => set('max_tokens', parseInt(e.target.value, 10) || 400)} readOnly={readOnly} />
        </Field>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:8 }}>
          Generates a reply from the recent conversation and sends it. Needs an open
          24h window. Best placed after an inbound trigger. Requires AI to be enabled
          on the server.
        </p>
      </div>
    )
  }

  // ── send_flow (WhatsApp Flow / native form) ────────────────────────
  if (type === 'send_flow') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Flow ID (from Meta WhatsApp Manager)">
          <input style={inp} value={data.flow_id ?? ''} placeholder="e.g. 1234567890123456"
            onChange={e => set('flow_id', e.target.value)} readOnly={readOnly} />
        </Field>
        <Field label="Button text (CTA)">
          <input style={inp} value={data.cta_text ?? ''} maxLength={30} placeholder="e.g. Book appointment"
            onChange={e => set('cta_text', e.target.value)} readOnly={readOnly} />
        </Field>
        <Field label="Message">
          <input style={inp} value={data.body ?? ''} placeholder="e.g. Tap below to book your slot"
            onChange={e => set('body', e.target.value)} readOnly={readOnly} />
        </Field>
        <Field label="Start screen (optional)">
          <input style={inp} value={data.screen ?? ''} placeholder="screen id, e.g. WELCOME"
            onChange={e => set('screen', e.target.value)} readOnly={readOnly} />
        </Field>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:8 }}>
          Sends a published WhatsApp Flow. Build & publish the Flow in Meta WhatsApp
          Manager first, then paste its Flow ID here. Submissions trigger the
          “Form Response” trigger.
        </p>
      </div>
    )
  }

  // ── webhook_call ───────────────────────────────────────────────────
  if (type === 'webhook_call') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Webhook URL (POST)">
          <input style={inp} value={data.url ?? ''} placeholder="https://your-service.com/hook" onChange={e => set('url', e.target.value)} readOnly={readOnly} />
        </Field>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:4 }}>
          Receives: {"{"}"contact", "flow_run_id", "state"{"}"}. Retried on failure.
        </p>
      </div>
    )
  }

  // ── send_interactive ──────────────────────────────────────────────────────
  if (type === 'send_interactive') {
    const itype = data.interactive_type ?? 'button'
    const buttons = data.buttons ?? [{id:'btn_1',title:'Option 1'},{id:'btn_2',title:'Option 2'}]
    const sections = data.sections ?? [{title:'Options',rows:[{id:'row_1',title:'Item 1',description:''}]}]

    function setButtons(newButtons) { set('buttons', newButtons) }
    function setSections(newSections) { set('sections', newSections) }

    return (
      <div style={{ padding: 12 }}>
        <Field label="Type">
          <select style={inp} value={itype} onChange={e => set('interactive_type', e.target.value)} disabled={readOnly}>
            <option value="button">Quick Reply Buttons (up to 3)</option>
            <option value="cta_url">CTA Link Button</option>
            <option value="list">List Menu (up to 10 items)</option>
          </select>
        </Field>

        <Field label="Header (optional)">
          <input style={inp} value={data.header ?? ''} placeholder="e.g. Welcome!" onChange={e => set('header', e.target.value)} readOnly={readOnly} />
        </Field>

        <Field label="Body text *">
          <textarea
            style={{ ...inp, height: 60, resize: 'none' }}
            value={data.body ?? ''}
            placeholder="Message body (required)"
            onChange={e => set('body', e.target.value)}
            readOnly={readOnly}
          />
        </Field>

        <Field label="Footer (optional)">
          <input style={inp} value={data.footer ?? ''} placeholder="e.g. Reply anytime" onChange={e => set('footer', e.target.value)} readOnly={readOnly} />
        </Field>

        {itype === 'button' && (
          <div>
            <div style={{ fontSize: 11, fontWeight: 600, color: '#374151', marginBottom: 4 }}>Buttons (up to 3)</div>
            {buttons.map((btn, i) => (
              <div key={i} style={{ display: 'flex', gap: 4, marginBottom: 4 }}>
                <input
                  style={{ ...inp, marginTop: 0, flex: 1 }}
                  value={btn.id}
                  placeholder="btn_id (no spaces)"
                  onChange={e => { const b=[...buttons]; b[i]={...b[i],id:e.target.value}; setButtons(b) }}
                  readOnly={readOnly}
                />
                <input
                  style={{ ...inp, marginTop: 0, flex: 2 }}
                  value={btn.title}
                  placeholder="Button label (max 20 chars)"
                  maxLength={20}
                  onChange={e => { const b=[...buttons]; b[i]={...b[i],title:e.target.value}; setButtons(b) }}
                  readOnly={readOnly}
                />
                {!readOnly && buttons.length > 1 && (
                  <button onClick={() => setButtons(buttons.filter((_,j)=>j!==i))} style={{ background:'#fee2e2',border:'none',borderRadius:4,padding:'0 6px',cursor:'pointer',color:'#dc2626' }}>✕</button>
                )}
              </div>
            ))}
            {!readOnly && buttons.length < 3 && (
              <button onClick={() => setButtons([...buttons,{id:`btn_${buttons.length+1}`,title:`Option ${buttons.length+1}`}])}
                style={{ fontSize:11, background:'#e0e7ff', border:'1px solid #bcdcf6', borderRadius:4, padding:'2px 8px', cursor:'pointer', color:'#074a8c' }}>
                + Add button
              </button>
            )}
            <p style={{ fontSize:10, color:'#9ca3af', marginTop:4 }}>Button IDs become edge handles — connect each to a different flow path.</p>
          </div>
        )}

        {itype === 'cta_url' && (
          <div>
            <Field label="Button text">
              <input style={inp} value={data.cta_text ?? 'Visit Website'} placeholder="Button label" onChange={e => set('cta_text', e.target.value)} readOnly={readOnly} />
            </Field>
            <Field label="URL">
              <input style={inp} value={data.cta_url ?? ''} placeholder="https://example.com" type="url" onChange={e => set('cta_url', e.target.value)} readOnly={readOnly} />
            </Field>
          </div>
        )}

        {itype === 'list' && (
          <div>
            <Field label="Button label">
              <input style={inp} value={data.button_text ?? 'View Options'} placeholder="e.g. View Options" onChange={e => set('button_text', e.target.value)} readOnly={readOnly} />
            </Field>
            {sections.map((sec, si) => (
              <div key={si} style={{ border:'1px solid #e5e7eb', borderRadius:6, padding:8, marginBottom:6 }}>
                <input
                  style={{ ...inp, marginBottom:6, fontWeight:600 }}
                  value={sec.title}
                  placeholder="Section title"
                  onChange={e => { const s=[...sections]; s[si]={...s[si],title:e.target.value}; setSections(s) }}
                  readOnly={readOnly}
                />
                {sec.rows.map((row,ri) => (
                  <div key={ri} style={{ display:'flex', gap:4, marginBottom:4 }}>
                    <input style={{ ...inp, marginTop:0, width:70 }} value={row.id} placeholder="id" onChange={e => { const s=[...sections]; s[si].rows[ri]={...s[si].rows[ri],id:e.target.value}; setSections(s) }} readOnly={readOnly} />
                    <input style={{ ...inp, marginTop:0, flex:1 }} value={row.title} placeholder="Title (24 chars)" maxLength={24} onChange={e => { const s=[...sections]; s[si].rows[ri]={...s[si].rows[ri],title:e.target.value}; setSections(s) }} readOnly={readOnly} />
                  </div>
                ))}
                {!readOnly && sec.rows.length < 10 && (
                  <button onClick={() => { const s=[...sections]; s[si].rows.push({id:`row_${sec.rows.length+1}`,title:`Item ${sec.rows.length+1}`,description:''}); setSections(s) }}
                    style={{ fontSize:10, background:'#f0fdf4', border:'1px solid #bbf7d0', borderRadius:4, padding:'1px 6px', cursor:'pointer', color:'#15803d' }}>
                    + Add row
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    )
  }

  // ── delay ──────────────────────────────────────────────────────────
  // unit values MUST match FlowEngine::DELAY_UNIT_SECONDS keys
  if (type === 'delay') {
    return (
      <div style={{ padding:12 }}>
        <Field label="Wait for">
          <div style={{ display:'flex', gap:8, marginTop:4 }}>
            <input type="number" style={{ ...inp, marginTop:0, width:80, flex:'none' }} value={data.value ?? 1} min={1} onChange={e => set('value', parseInt(e.target.value, 10) || 1)} readOnly={readOnly} />
            <select style={{ ...inp, marginTop:0, flex:1 }} value={data.unit ?? 'minutes'} onChange={e => set('unit', e.target.value)} disabled={readOnly}>
              {DELAY_UNITS.map(u => <option key={u} value={u}>{u}</option>)}
            </select>
          </div>
        </Field>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:4 }}>
          Use "seconds" for testing locally (WHATSAPP_MOCK_MODE=true).
        </p>
      </div>
    )
  }

  // ── condition ──────────────────────────────────────────────────────
  // ALL values match FlowEngine::execCondition() EXACTLY
  if (type === 'condition') {
    const condType = data.type ?? 'contact_field'
    const operators = condType === 'tag' ? TAG_OPERATORS : STANDARD_OPERATORS

    return (
      <div style={{ padding:12 }}>
        <Field label="Source type">
          <select style={inp} value={condType} onChange={e => {
            set('type', e.target.value)
            // Reset operator to first valid for new type
            const ops = e.target.value === 'tag' ? TAG_OPERATORS : STANDARD_OPERATORS
            set('operator', ops[0].value)
          }} disabled={readOnly}>
            {CONDITION_TYPES.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </Field>

        {condType === 'tag' ? (
          <Field label="Tag">
            <select style={inp} value={data.tag_id ?? ''} onChange={e => set('tag_id', parseInt(e.target.value,10))} disabled={readOnly}>
              <option value="">— select tag —</option>
              {tags.map(t => <option key={t.id} value={t.id}>#{t.name}</option>)}
            </select>
          </Field>
        ) : (
          <Field label="Field name">
            {condType === 'contact_field' ? (
              <select style={inp} value={data.field ?? ''} onChange={e => set('field', e.target.value)} disabled={readOnly}>
                <option value="">— select field —</option>
                {CONTACT_FIELDS.map(f => <option key={f} value={f}>{f}</option>)}
              </select>
            ) : (
              <input style={inp} value={data.field ?? ''} placeholder={condType === 'state' ? 'state key' : 'field_key'} onChange={e => set('field', e.target.value)} readOnly={readOnly} />
            )}
          </Field>
        )}

        <Field label="Operator">
          <select style={inp} value={data.operator ?? operators[0].value} onChange={e => set('operator', e.target.value)} disabled={readOnly}>
            {operators.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </Field>

        {condType !== 'tag' && !['is_empty','is_not_empty'].includes(data.operator) && (
          <Field label="Value">
            <input style={inp} value={data.value ?? ''} onChange={e => set('value', e.target.value)} readOnly={readOnly} />
          </Field>
        )}

        <div style={{ display:'flex', gap:12, marginTop:8, fontSize:12 }}>
          <span style={{ color:'#16a34a' }}>✓ true edge</span>
          <span style={{ color:'#dc2626' }}>✗ false edge</span>
        </div>
      </div>
    )
  }

  // ── window_check ───────────────────────────────────────────────────
  if (type === 'window_check') {
    return (
      <div style={{ padding:12 }}>
        <p style={{ fontSize:13, color:'#0e8f8c', lineHeight:1.5 }}>
          Branches on whether the contact's 24-hour WhatsApp service window is currently open.
        </p>
        <ul style={{ fontSize:12, color:'#6b7280', marginTop:8, paddingLeft:16 }}>
          <li><strong style={{ color:'#3b82f6' }}>open</strong> — free-form messages and templates allowed</li>
          <li><strong style={{ color:'#9ca3af' }}>closed</strong> — only approved templates allowed</li>
        </ul>
        <p style={{ fontSize:11, color:'#9ca3af', marginTop:8 }}>
          No configuration needed. Wire "open" to a send_freeform/send_template, "closed" to a send_template.
        </p>
      </div>
    )
  }

  return <div style={{ padding:12, color:'#9ca3af', fontSize:13 }}>No config for "{type}"</div>
}

// ── send_email ─────────────────────────────────────────────────────────
// Email is its own channel: the WhatsApp 24h window does not apply, the
// suppression list does. Content comes from a saved email template.
function SendEmailConfig({ data, set, readOnly, lbl, inp }) {
  const [templates, setTemplates] = useState(null)

  useEffect(() => {
    api.get('/email-marketing/templates')
      .then(r => setTemplates(r.data ?? []))
      .catch(() => setTemplates([]))
  }, [])

  return (
    <div style={{ padding:12, display:'grid', gap:10 }}>
      <label style={lbl}>Email template
        <select style={inp} value={data.email_template_id ?? 0} disabled={readOnly}
                onChange={e => set('email_template_id', Number(e.target.value))}>
          <option value={0}>— choose a template —</option>
          {(templates ?? []).map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
      </label>
      {templates !== null && templates.length === 0 && (
        <p style={{ fontSize:11.5, color:'#b45309', margin:0 }}>
          No email templates yet — create one under Messaging → Email Marketing → Templates.
        </p>
      )}
      <label style={lbl}>Subject override (optional)
        <input style={inp} value={data.subject ?? ''} disabled={readOnly} onChange={e => set('subject', e.target.value)}
               placeholder="Leave blank to use the template's subject" />
      </label>
      <label style={lbl}>Reply-to (optional)
        <input style={inp} value={data.reply_to ?? ''} disabled={readOnly} onChange={e => set('reply_to', e.target.value)} placeholder="sales@yourdomain.com" />
      </label>
      <p style={{ fontSize:11.5, color:'#9ca3af', margin:0 }}>
        Sent through your own SMTP server. Contacts without an email, or who unsubscribed, are skipped and the flow continues.
        Opens and clicks show on the contact's timeline.
      </p>
    </div>
  )
}
