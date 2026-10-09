import { useState, useEffect } from 'react'
import { TRAVEL_TRIGGERS, TRAVEL_TRIGGER_KEYS } from '../flow-builder/travelTriggers'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, FlaskConical, Pause, Play } from 'lucide-react'
import { flows as flowsApi } from '../api/flows'
import { api } from '../api/client'
import FlowCanvas from '../flow-builder/FlowCanvas'
import ValidationBanner from '../flow-builder/ValidationBanner'
import RunsList from '../flow-builder/RunsList'

const TRIGGER_LABELS = {
  lead_created:'Lead Created', tag_added:'Tag Added', form_submitted:'Form Submitted',
  meta_lead_received:'Meta Lead', google_lead_received:'Google Lead', keyword_reply:'Keyword Reply', inbound_message:'Any Inbound',
  order_placed:'Order Placed', order_fulfilled:'Order Fulfilled', abandoned_cart:'Abandoned Cart',
  flow_response:'Form Response', date_reached:'Date Reached',
  deal_created:'Deal Created', deal_stage_changed:'Deal Stage Moved', deal_won:'Deal Won', deal_lost:'Deal Lost',
  ticket_created:'Ticket Created', ticket_resolved:'Ticket Resolved',
  ...Object.fromEntries(TRAVEL_TRIGGERS.map(t => [t.key, t.label])),
}

const STATUS_STYLE = {
  draft:  { bg:'#f3f4f6', color:'#374151' },
  active: { bg:'#dcfce7', color:'#15803d' },
  paused: { bg:'#fef9c3', color:'#854d0e' },
}

const OUTCOME_COLOR = {
  advance:        '#15803d',
  completed:      '#15803d',
  delayed:        '#b45309',
  blocked_window: '#dc2626',
  failed:         '#dc2626',
}

export default function FlowBuilderPage() {
  const { id }     = useParams()
  const navigate   = useNavigate()

  const [flow, setFlow]           = useState(null)
  const [validation, setValidation] = useState({ valid:true, errors:[], warnings:[] })
  const [tab, setTab]             = useState('canvas')
  const [saving, setSaving]       = useState(false)
  const [activating, setActivating] = useState(false)
  const [error, setError]         = useState(null)
  const [selectedNode, setSelectedNode] = useState(null)

  // Flow settings (editable while draft/paused)
  const [name, setName]           = useState('')
  const [reentry, setReentry]     = useState('once')
  const [triggerConfig, setTriggerConfig] = useState({})

  // Supporting data
  const [tags, setTags]   = useState([])
  const [users, setUsers] = useState([])

  // Test modal state
  const [showTestModal, setShowTestModal] = useState(false)
  const [testContactId, setTestContactId] = useState('')
  const [testRunning, setTestRunning]     = useState(false)
  const [testResult, setTestResult]       = useState(null)
  const [contacts, setContacts]           = useState([])

  useEffect(() => {
    load()
    api.get('/tags').then(r => setTags(r.data ?? [])).catch(() => {})
  }, [id])

  useEffect(() => {
    api.get('/contacts?per_page=50').then(r => setContacts(r.data ?? [])).catch(() => {})
  }, [])

  async function load() {
    try {
      const r = await flowsApi.get(id)
      const f = r.data
      setFlow(f)
      setName(f.name ?? '')
      setReentry(f.reentry_policy ?? 'once')
      setTriggerConfig(f.trigger_config ? (typeof f.trigger_config === 'string' ? JSON.parse(f.trigger_config) : f.trigger_config) : {})
      setValidation(r.validation ?? { valid:true, errors:[], warnings:[] })
    } catch (e) {
      setError('Failed to load flow.')
    }
  }

  const readOnly = flow?.status === 'active'

  // Save graph — called by canvas Save button
  async function handleSaveGraph(graph) {
    setSaving(true); setError(null)
    try {
      await flowsApi.update(id, {
        name,
        trigger_config: Object.keys(triggerConfig).length ? triggerConfig : null,
        reentry_policy: reentry,
        graph,
      })
      const r = await flowsApi.get(id)
      setFlow(r.data)
      setValidation(r.validation ?? { valid:true, errors:[], warnings:[] })
    } catch (e) {
      setError(e.message ?? 'Save failed.')
    } finally {
      setSaving(false)
    }
  }

  // Activate flow — server validates, blocks on errors
  async function handleActivate() {
    setActivating(true); setError(null)
    try {
      const r = await flowsApi.setStatus(id, 'active')
      await load()
    } catch (e) {
      // 422 from server — validation errors block activation
      if (e.errors) {
        setValidation(v => ({ ...v, errors: Array.isArray(e.errors) ? e.errors : Object.values(e.errors) }))
        setError('Flow has validation errors — fix them before activating.')
      } else {
        setError(e.message ?? 'Activation failed.')
      }
    } finally {
      setActivating(false)
    }
  }

  async function handlePause() {
    await flowsApi.setStatus(id, 'paused').catch(() => {})
    await load()
  }

  async function handleSaveSettings() {
    if (readOnly) return
    setSaving(true)
    try {
      await flowsApi.update(id, {
        name,
        trigger_config: Object.keys(triggerConfig).length ? triggerConfig : null,
        reentry_policy: reentry,
      })
      await load()
    } finally {
      setSaving(false)
    }
  }

  async function handleTest() {
    if (!testContactId) return
    setTestRunning(true)
    try {
      const r = await api.post(`/flows/${id}/test`, { contact_id: parseInt(testContactId) })
      setTestResult(r)
    } catch (err) {
      setTestResult({ error: err.message })
    } finally {
      setTestRunning(false)
    }
  }

  if (!flow) return <div style={{ padding:32, color:'#9ca3af' }}>{error ?? 'Loading…'}</div>

  const st = STATUS_STYLE[flow.status] ?? STATUS_STYLE.draft
  const canActivate = !readOnly && validation.errors.length === 0

  return (
    <div style={{ display:'flex', flexDirection:'column', height:'100vh', overflow:'hidden', fontFamily:'sans-serif' }}>
      {/* ── Header ─────────────────────────────────────────────────── */}
      <div style={{ background:'#fff', borderBottom:'1px solid #e5e7eb', padding:'10px 16px', display:'flex', alignItems:'center', gap:12, flexShrink:0 }}>
        <button onClick={() => navigate('/flows')} style={{ background:'none', border:'none', cursor:'pointer', color:'#6b7280', display:'inline-flex', alignItems:'center' }}><ArrowLeft size={20} /></button>

        {readOnly ? (
          <span style={{ fontWeight:700, fontSize:15 }}>{flow.name}</span>
        ) : (
          <input
            value={name}
            onChange={e => setName(e.target.value)}
            onBlur={handleSaveSettings}
            style={{ fontWeight:700, fontSize:15, border:'none', outline:'none', background:'transparent', flex:1, maxWidth:280 }}
          />
        )}

        <span style={{
          background: st.bg, color: st.color, padding:'2px 10px',
          borderRadius:12, fontSize:11, fontWeight:600,
        }}>
          {flow.status}
        </span>
        <span style={{ color:'#9ca3af', fontSize:12 }}>{TRIGGER_LABELS[flow.trigger_type] ?? flow.trigger_type}</span>

        <div style={{ marginLeft:'auto', display:'flex', gap:8 }}>
          {flow.status !== 'active' && (
            <button
              onClick={() => setShowTestModal(true)}
              style={btnStyle('#0e8f8c')}
            >
              <FlaskConical size={13} strokeWidth={2} /> Test
            </button>
          )}
          {flow.status === 'active' ? (
            <button onClick={handlePause} style={btnStyle('#f97316')}><Pause size={13} strokeWidth={2} /> Pause</button>
          ) : (
            <button
              onClick={handleActivate}
              disabled={!canActivate || activating}
              title={validation.errors.length > 0 ? 'Fix errors first' : 'Activate this flow'}
              style={btnStyle(canActivate ? '#16a34a' : '#9ca3af', !canActivate)}
            >
              {activating ? '…' : <><Play size={13} strokeWidth={2} /> Activate</>}
            </button>
          )}
        </div>
      </div>

      {/* ── Validation banner (server-authoritative) ─────────────── */}
      {(validation.errors.length > 0 || validation.warnings.length > 0) && (
        <div style={{ flexShrink:0 }}>
          <ValidationBanner errors={validation.errors} warnings={validation.warnings} />
        </div>
      )}

      {/* ── Flow settings strip (editable while draft/paused) ─────── */}
      {!readOnly && (
        <div style={{
          background:'#f9fafb', borderBottom:'1px solid #e5e7eb',
          padding:'8px 16px', display:'flex', alignItems:'center', gap:16, flexShrink:0, flexWrap:'wrap',
        }}>
          <span style={{ fontSize:12, color:'#6b7280', fontWeight:600 }}>Flow settings:</span>

          <label style={{ fontSize:12, color:'#374151', display:'flex', alignItems:'center', gap:6 }}>
            Reentry:
            <select
              value={reentry}
              onChange={e => { setReentry(e.target.value); }}
              onBlur={handleSaveSettings}
              style={{ border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12 }}
            >
              <option value="once">Once (block if active run)</option>
              <option value="always">Allow concurrent runs)</option>
            </select>
          </label>

          <TriggerConfigEditor
            triggerType={flow.trigger_type}
            config={triggerConfig}
            onChange={cfg => { setTriggerConfig(cfg); }}
            onBlur={handleSaveSettings}
            tags={tags}
          />
        </div>
      )}

      {/* ── Tabs ─────────────────────────────────────────────────── */}
      <div style={{ background:'#fff', borderBottom:'1px solid #e5e7eb', padding:'0 16px', display:'flex', gap:0, flexShrink:0 }}>
        {['canvas','runs'].map(t => (
          <button key={t} onClick={() => setTab(t)} style={{
            padding:'8px 16px', border:'none', background:'none',
            borderBottom: tab === t ? '2px solid #08569f' : '2px solid transparent',
            color: tab === t ? '#08569f' : '#9ca3af',
            fontWeight: tab === t ? 600 : 400, cursor:'pointer', fontSize:13,
          }}>
            {t === 'canvas' ? 'Builder' : 'Runs'}
          </button>
        ))}
      </div>

      {/* ── Tab content ──────────────────────────────────────────── */}
      <div style={{ flex:1, overflow:'hidden' }}>
        {tab === 'canvas' && (
          <FlowCanvas
            key={flow.id + '_' + flow.version}
            flow={flow}
            onSave={handleSaveGraph}
            readOnly={readOnly}
            selectedNode={selectedNode}
            setSelectedNode={setSelectedNode}
            tags={tags}
            users={users}
          />
        )}
        {tab === 'runs' && <RunsList flowId={id} />}
      </div>

      {error && (
        <div style={{
          position:'fixed', bottom:20, right:20, background:'#fef2f2',
          border:'1px solid #fca5a5', borderRadius:8, padding:'10px 14px',
          color:'#dc2626', fontSize:13, maxWidth:320, zIndex:100,
        }}>
          {error}
        </div>
      )}

      {/* ── Test modal ───────────────────────────────────────────── */}
      {showTestModal && (
        <div
          className="modal-overlay"
          onClick={() => !testRunning && setShowTestModal(false)}
          style={{
            position:'fixed', inset:0, background:'rgba(0,0,0,0.45)',
            display:'flex', alignItems:'center', justifyContent:'center', zIndex:200,
          }}
        >
          <div
            className="modal"
            onClick={e => e.stopPropagation()}
            style={{
              background:'#fff', borderRadius:10, padding:28,
              maxWidth:640, width:'100%', boxShadow:'0 8px 32px rgba(0,0,0,0.18)',
              maxHeight:'80vh', overflowY:'auto',
            }}
          >
            <h3 style={{ margin:'0 0 6px', fontSize:17, fontWeight:700 }}>Test Flow</h3>
            <p style={{ margin:'0 0 18px', fontSize:13, color:'#6b7280' }}>
              Run this flow against a contact in dry-run mode — no real WhatsApp messages will be sent.
            </p>

            {!testResult ? (
              <>
                <label style={{ display:'block', fontSize:13, color:'#374151', marginBottom:6, fontWeight:600 }}>
                  Select contact
                </label>
                <select
                  value={testContactId}
                  onChange={e => setTestContactId(e.target.value)}
                  disabled={testRunning}
                  style={{
                    width:'100%', border:'1px solid #d1d5db', borderRadius:6,
                    padding:'7px 10px', fontSize:13, marginBottom:18,
                    background: testRunning ? '#f9fafb' : '#fff',
                  }}
                >
                  <option value="">— choose a contact —</option>
                  {contacts.map(c => (
                    <option key={c.id} value={c.id}>
                      {c.name || '(no name)'} — {c.wa_number}
                    </option>
                  ))}
                </select>

                <div style={{ display:'flex', gap:8, justifyContent:'flex-end' }}>
                  <button
                    onClick={() => setShowTestModal(false)}
                    disabled={testRunning}
                    style={btnStyle('#6b7280', testRunning)}
                  >
                    Cancel
                  </button>
                  <button
                    onClick={handleTest}
                    disabled={!testContactId || testRunning}
                    style={btnStyle('#0e8f8c', !testContactId || testRunning)}
                  >
                    {testRunning ? 'Running…' : <><Play size={13} strokeWidth={2} /> Run Test</>}
                  </button>
                </div>
              </>
            ) : (
              <>
                {testResult.error ? (
                  <div style={{
                    background:'#fef2f2', border:'1px solid #fca5a5',
                    borderRadius:6, padding:'10px 14px', color:'#dc2626',
                    fontSize:13, marginBottom:18,
                  }}>
                    Error: {testResult.error}
                  </div>
                ) : (
                  <>
                    <p style={{ fontSize:13, color:'#374151', marginBottom:12, fontWeight:600 }}>
                      Trace ({(testResult.trace ?? []).length} step{(testResult.trace ?? []).length !== 1 ? 's' : ''})
                    </p>
                    <div style={{ overflowX:'auto', marginBottom:18 }}>
                      <table style={{ width:'100%', borderCollapse:'collapse', fontSize:12 }}>
                        <thead>
                          <tr style={{ background:'#f9fafb', textAlign:'left' }}>
                            {['Node','Type','Outcome','Detail'].map(h => (
                              <th key={h} style={{ padding:'6px 10px', borderBottom:'1px solid #e5e7eb', fontWeight:600, color:'#374151' }}>
                                {h}
                              </th>
                            ))}
                          </tr>
                        </thead>
                        <tbody>
                          {(testResult.trace ?? []).map((entry, i) => (
                            <tr key={i} style={{ borderBottom:'1px solid #f3f4f6' }}>
                              <td style={{ padding:'6px 10px', color:'#374151', fontWeight:500 }}>
                                {entry.node_id ?? entry.node ?? `Step ${i + 1}`}
                              </td>
                              <td style={{ padding:'6px 10px', color:'#6b7280' }}>
                                {entry.type ?? '—'}
                              </td>
                              <td style={{ padding:'6px 10px' }}>
                                <span style={{
                                  color: OUTCOME_COLOR[entry.outcome] ?? '#374151',
                                  fontWeight:600,
                                }}>
                                  {entry.outcome ?? '—'}
                                </span>
                              </td>
                              <td style={{ padding:'6px 10px', color:'#6b7280', maxWidth:220, wordBreak:'break-word' }}>
                                {entry.detail ?? entry.message ?? '—'}
                              </td>
                            </tr>
                          ))}
                          {(testResult.trace ?? []).length === 0 && (
                            <tr>
                              <td colSpan={4} style={{ padding:'12px 10px', color:'#9ca3af', textAlign:'center' }}>
                                No trace entries returned.
                              </td>
                            </tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  </>
                )}
                <div style={{ display:'flex', justifyContent:'flex-end' }}>
                  <button
                    onClick={() => { setShowTestModal(false); setTestResult(null) }}
                    style={btnStyle('#08569f')}
                  >
                    Close
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function btnStyle(bg, disabled = false) {
  return {
    padding:'6px 14px', background: disabled ? '#e5e7eb' : bg,
    color: disabled ? '#9ca3af' : '#fff', border:'none', borderRadius:6,
    fontSize:12, fontWeight:600, cursor: disabled ? 'not-allowed' : 'pointer',
    display:'inline-flex', alignItems:'center', gap:'.4rem',
  }
}

// ── Trigger config editor (inline, type-specific) ──────────────────────
function TriggerConfigEditor({ triggerType, config, onChange, onBlur, tags }) {
  function set(k, v) { onChange({ ...config, [k]: v }) }

  if (triggerType === 'keyword_reply') {
    const kws = config.keywords ?? []
    return (
      <label style={{ fontSize:12, color:'#374151', display:'flex', alignItems:'center', gap:6 }}>
        Keywords:
        <input
          value={kws.join(', ')}
          onChange={e => set('keywords', e.target.value.split(',').map(s => s.trim()).filter(Boolean))}
          onBlur={onBlur}
          placeholder="stop, hello, hi"
          style={{ border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12, width:160 }}
        />
        <select
          value={config.match ?? 'contains'}
          onChange={e => { set('match', e.target.value); }}
          onBlur={onBlur}
          style={{ border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12 }}
        >
          <option value="exact">exact</option>
          <option value="contains">contains</option>
        </select>
      </label>
    )
  }

  if (triggerType === 'tag_added') {
    return (
      <label style={{ fontSize:12, color:'#374151', display:'flex', alignItems:'center', gap:6 }}>
        Specific tag (blank = any):
        <select
          value={config.tag_id ?? ''}
          onChange={e => { set('tag_id', e.target.value ? parseInt(e.target.value,10) : undefined); }}
          onBlur={onBlur}
          style={{ border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12 }}
        >
          <option value="">— any tag —</option>
          {tags.map(t => <option key={t.id} value={t.id}>#{t.name}</option>)}
        </select>
      </label>
    )
  }

  if (triggerType === 'date_reached') {
    return (
      <div style={{ display:'flex', alignItems:'center', gap:10, flexWrap:'wrap', fontSize:12, color:'#374151' }}>
        <label style={{ display:'flex', alignItems:'center', gap:6 }}>
          Date field key:
          <input
            value={config.field_key ?? ''}
            onChange={e => set('field_key', e.target.value)}
            onBlur={onBlur}
            placeholder="e.g. birthday"
            style={{ border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12, width:120 }}
          />
        </label>
        <label style={{ display:'flex', alignItems:'center', gap:6 }}>
          Days before:
          <input
            type="number" min="0"
            value={config.days_before ?? 0}
            onChange={e => set('days_before', parseInt(e.target.value, 10) || 0)}
            onBlur={onBlur}
            style={{ border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12, width:60 }}
          />
        </label>
        <label style={{ display:'flex', alignItems:'center', gap:6 }}>
          <input
            type="checkbox"
            checked={config.recurring ?? true}
            onChange={e => { set('recurring', e.target.checked); }}
            onBlur={onBlur}
          />
          Recurring yearly (birthday)
        </label>
      </div>
    )
  }

  if (TRAVEL_TRIGGER_KEYS.includes(triggerType)) {
    const num = (k, label, def, min = 0) => (
      <label style={{ display:'flex', alignItems:'center', gap:6 }}>
        {label}
        <input type="number" min={min} value={config[k] ?? def}
          onChange={e => set(k, parseInt(e.target.value, 10) || 0)} onBlur={onBlur}
          style={{ border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12, width:64 }} />
      </label>
    )
    const sel = { border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12 }
    return (
      <div style={{ display:'flex', alignItems:'center', gap:14, flexWrap:'wrap', fontSize:12, color:'#374151' }}>
        {triggerType === 'quote_stale' && <>
          {num('days', 'No answer after (days):', 3, 1)}
          <label style={{ display:'flex', alignItems:'center', gap:6 }}>Quote status:
            <select style={sel} value={config.audience ?? 'any'} onChange={e => set('audience', e.target.value)} onBlur={onBlur}>
              <option value="any">Any (sent or opened)</option><option value="unviewed">Never opened</option><option value="viewed">Opened, not accepted</option>
            </select></label></>}
        {triggerType === 'departure_soon' && num('days_before', 'Days before departure:', 7)}
        {triggerType === 'passport_expiring' && num('within_days', 'Departing within (days):', 120, 1)}
        <label style={{ display:'flex', alignItems:'center', gap:6 }}>Trips:
          <select style={sel} value={config.international ?? 'any'} onChange={e => set('international', e.target.value)} onBlur={onBlur}>
            <option value="any">Domestic + international</option><option value="yes">International only</option><option value="no">Domestic only</option>
          </select></label>
        <label style={{ display:'flex', alignItems:'center', gap:6 }}>Trip type:
          <select style={sel} value={config.trip_type ?? ''} onChange={e => set('trip_type', e.target.value)} onBlur={onBlur}>
            <option value="">Any</option>
            {['honeymoon','family','friends','solo','pilgrimage','adventure','corporate','group_departure','student'].map(t => <option key={t} value={t}>{t.replace('_',' ')}</option>)}
          </select></label>
      </div>
    )
  }

  if (triggerType === 'form_submitted') {
    return (
      <label style={{ fontSize:12, color:'#374151', display:'flex', alignItems:'center', gap:6 }}>
        Form ID (blank = any):
        <input
          type="number"
          value={config.form_id ?? ''}
          onChange={e => set('form_id', e.target.value ? parseInt(e.target.value,10) : undefined)}
          onBlur={onBlur}
          style={{ border:'1px solid #d1d5db', borderRadius:4, padding:'2px 6px', fontSize:12, width:70 }}
        />
      </label>
    )
  }

  return null
}
