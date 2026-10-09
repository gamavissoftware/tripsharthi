import { useState, useEffect, useCallback } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'
import { ChartByType } from '../components/Charts'

// ── Configurable dashboards + report widgets (Phase I1/I2) ───────────────────

const METRIC_FORMAT = { sum_value: 'inr', avg_value: 'inr', win_rate: 'pct', count: 'num' }
const TYPES = ['kpi', 'bar', 'line', 'pie', 'funnel', 'table']
const PRESETS = [['all', 'All time'], ['ytd', 'Year to date'], ['this_month', 'This month'], ['last_30', 'Last 30 days'], ['last_7', 'Last 7 days']]
const labelize = (s) => String(s).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

function Widget({ widget, globalPreset, refreshTick, onEdit, onDelete }) {
  const [data, setData] = useState(null)
  useEffect(() => {
    const spec = { ...widget.config }
    if (globalPreset !== 'widget') { spec.preset = globalPreset; spec.from = null; spec.to = null }
    crm.runReport(spec).then(r => setData(r.data)).catch(() => setData({ series: [], total: 0 }))
  }, [widget, globalPreset, refreshTick])

  const format = METRIC_FORMAT[widget.config?.metric] || 'num'
  return (
    <div className="lp-rep-widget lp-span-all-mobile" style={{ gridColumn: `span ${widget.width || 6}`, background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1rem 1.1rem', display: 'flex', flexDirection: 'column' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
        <span style={{ fontSize: 13, fontWeight: 800, color: '#111827' }}>{widget.title}</span>
        <span className="lp-rep-actions" style={{ display: 'flex', gap: 6 }}>
          <button onClick={() => exportWidgetCsv(widget, globalPreset)} title="Download CSV" style={iconBtn}>⤓</button>
          <button onClick={() => onEdit(widget)} title="Edit" style={iconBtn}>✎</button>
          <button onClick={() => onDelete(widget)} title="Remove" style={iconBtn}>✕</button>
        </span>
      </div>
      <div style={{ flex: 1 }}>{data ? <ChartByType type={widget.type} data={data} format={format} /> : <div style={{ color: '#cbd5e1', fontSize: 13, padding: '1.5rem 0', textAlign: 'center' }}>Loading…</div>}</div>
    </div>
  )
}
const iconBtn = { background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', fontSize: 13, padding: 2 }

async function exportWidgetCsv(widget, globalPreset) {
  const spec = { ...widget.config }
  if (globalPreset !== 'widget') { spec.preset = globalPreset; spec.from = null; spec.to = null }
  try {
    const blob = await crm.exportReport(spec)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${(widget.title || 'report').replace(/\s+/g, '-').toLowerCase()}.csv`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e) { toast.error('Export failed', e?.message) }
}

function WidgetBuilder({ dashboardId, widget, options, onClose, onSaved }) {
  const entities = Object.keys(options)
  const init = widget?.config || { entity: 'deal', metric: 'sum_value', dimension: 'stage', preset: 'all' }
  const [f, setF] = useState({
    type: widget?.type || 'bar', title: widget?.title || '', width: widget?.width || 6,
    entity: init.entity, metric: init.metric, dimension: init.dimension,
  })
  const [saving, setSaving] = useState(false)
  const ent = options[f.entity] || { metrics: [], dimensions: [] }
  const set = (k, v) => setF(p => {
    const n = { ...p, [k]: v }
    if (k === 'entity') { n.metric = options[v].metrics[0]; n.dimension = options[v].dimensions[0] }
    return n
  })

  async function save(e) {
    e.preventDefault()
    setSaving(true)
    try {
      const payload = { type: f.type, title: f.title.trim() || labelize(f.metric), width: Number(f.width), config: { entity: f.entity, metric: f.metric, dimension: f.dimension, preset: 'all' } }
      if (widget) await crm.updateWidget(widget.id, payload)
      else await crm.addWidget(dashboardId, payload)
      onSaved()
    } catch (e) { toast.error('Save failed', e?.message) }
    setSaving(false)
  }

  const sel = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .6rem', fontSize: '.88rem', boxSizing: 'border-box' }
  const lbl = { display: 'block', fontSize: '.78rem', fontWeight: 700, color: '#374151', margin: '.7rem 0 .25rem' }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 440, padding: '1.5rem' }}>
        <h3 style={{ margin: '0 0 .5rem', fontWeight: 800, fontSize: '1.05rem' }}>{widget ? 'Edit' : 'Add'} widget</h3>
        <label style={lbl}>Title</label>
        <input style={sel} value={f.title} onChange={e => set('title', e.target.value)} placeholder="Auto from metric if blank" />
        <div style={{ display: 'flex', gap: 10 }}>
          <div style={{ flex: 1 }}><label style={lbl}>Entity</label>
            <select style={sel} value={f.entity} onChange={e => set('entity', e.target.value)}>{entities.map(x => <option key={x} value={x}>{labelize(x)}</option>)}</select></div>
          <div style={{ flex: 1 }}><label style={lbl}>Chart</label>
            <select style={sel} value={f.type} onChange={e => set('type', e.target.value)}>{TYPES.map(x => <option key={x} value={x}>{labelize(x)}</option>)}</select></div>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <div style={{ flex: 1 }}><label style={lbl}>Metric</label>
            <select style={sel} value={f.metric} onChange={e => set('metric', e.target.value)}>{ent.metrics.map(x => <option key={x} value={x}>{labelize(x)}</option>)}</select></div>
          <div style={{ flex: 1 }}><label style={lbl}>Group by</label>
            <select style={sel} value={f.dimension} onChange={e => set('dimension', e.target.value)}>{ent.dimensions.map(x => <option key={x} value={x}>{x === 'none' ? 'Total (KPI)' : labelize(x)}</option>)}</select></div>
        </div>
        <label style={lbl}>Width</label>
        <select style={sel} value={f.width} onChange={e => set('width', e.target.value)}>
          <option value={3}>Quarter</option><option value={4}>Third</option><option value={6}>Half</option><option value={12}>Full</option>
        </select>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: '1.25rem' }}>
          <button type="button" onClick={onClose} style={btn(false)}>Cancel</button>
          <button type="submit" disabled={saving} style={btn(true)}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </form>
    </div>
  )
}
const btn = (p) => ({ padding: '.55rem 1.1rem', borderRadius: 9, fontWeight: 700, fontSize: 13.5, cursor: 'pointer', border: '1.5px solid ' + (p ? 'transparent' : '#e5e7eb'), background: p ? 'var(--primary,#0a6cc4)' : '#fff', color: p ? '#fff' : '#374151' })

export default function ReportsPage() {
  const [boards, setBoards] = useState([])
  const [activeId, setActiveId] = useState(null)
  const [options, setOptions] = useState({})
  const [preset, setPreset] = useState('all')
  const [autoRefresh, setAutoRefresh] = useState(false)
  const [tick, setTick] = useState(0)
  const [builder, setBuilder] = useState(undefined) // undefined=closed, null=new, obj=edit

  const load = useCallback(async () => {
    try {
      const r = await crm.dashboards()
      setBoards(r.data ?? [])
      setActiveId(id => id ?? (r.data?.[0]?.id ?? null))
    } catch (e) { toast.error('Failed to load dashboards', e?.message) }
  }, [])

  useEffect(() => { load(); crm.reportOptions().then(r => setOptions(r.data ?? {})).catch(() => {}) }, [load])

  useEffect(() => {
    if (!autoRefresh) return
    const id = setInterval(() => setTick(t => t + 1), 30000)
    return () => clearInterval(id)
  }, [autoRefresh])

  const active = boards.find(b => b.id === activeId) || boards[0]

  async function addDashboard() {
    const name = window.prompt('New dashboard name?')
    if (!name?.trim()) return
    try { const r = await crm.createDashboard(name.trim()); await load(); setActiveId(r.data.id) }
    catch (e) { toast.error('Could not create dashboard', e?.message) }
  }
  async function removeWidget(w) {
    if (!confirm('Remove this widget?')) return
    try { await crm.deleteWidget(w.id); load() } catch (e) { toast.error('Delete failed', e?.message) }
  }

  return (
    <div className="page" style={{ maxWidth: 1100 }}>
      <style>{`
        .lp-rep-actions{opacity:0;transition:opacity .15s} .lp-rep-widget:hover .lp-rep-actions{opacity:1}
        @media print {
          .sidebar, .lp-rep-noprint { display:none !important; }
          .app-main { padding:0 !important; }
          .lp-rep-widget { break-inside:avoid; box-shadow:none !important; }
        }
      `}</style>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.1rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>📈 Reports</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Build dashboards from your CRM data.</p>
        </div>
        <div className="lp-rep-noprint" style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <select value={preset} onChange={e => setPreset(e.target.value)} style={{ border: '1px solid #e5e7eb', borderRadius: 9, padding: '.5rem .7rem', fontSize: 13, fontWeight: 600 }}>
            <option value="widget">Per-widget range</option>
            {PRESETS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#475569', cursor: 'pointer' }}>
            <input type="checkbox" checked={autoRefresh} onChange={e => setAutoRefresh(e.target.checked)} /> Auto-refresh
          </label>
          <button onClick={() => window.print()} style={btn(false)}>🖨 PDF</button>
          <button onClick={() => setBuilder(null)} style={btn(true)}>+ Widget</button>
        </div>
      </div>

      {/* Dashboard tabs */}
      <div className="lp-rep-noprint" style={{ display: 'flex', gap: 6, marginBottom: '1.1rem', flexWrap: 'wrap', alignItems: 'center' }}>
        {boards.map(b => (
          <button key={b.id} onClick={() => setActiveId(b.id)}
            style={{ padding: '.4rem .9rem', borderRadius: 999, border: '1.5px solid ' + (active?.id === b.id ? 'var(--primary,#0a6cc4)' : '#e5e7eb'), background: active?.id === b.id ? 'var(--primary,#0a6cc4)' : '#fff', color: active?.id === b.id ? '#fff' : '#475569', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>{b.name}</button>
        ))}
        <button onClick={addDashboard} style={{ ...iconBtn, fontSize: 18, color: 'var(--primary,#0a6cc4)', fontWeight: 700 }} title="New dashboard">＋</button>
      </div>

      {active && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(12, 1fr)', gap: 14 }}>
          {(active.widgets ?? []).map(w => (
            <Widget key={w.id} widget={w} globalPreset={preset} refreshTick={tick} onEdit={() => setBuilder(w)} onDelete={removeWidget} />
          ))}
          {(active.widgets ?? []).length === 0 && (
            <div style={{ gridColumn: 'span 12', textAlign: 'center', padding: '3rem', color: '#9ca3af', background: '#fff', border: '1px dashed #e5e7eb', borderRadius: 14 }}>
              No widgets yet — click <strong>+ Widget</strong> to add one.
            </div>
          )}
        </div>
      )}

      {builder !== undefined && (
        <WidgetBuilder dashboardId={active?.id} widget={builder} options={options}
          onClose={() => setBuilder(undefined)} onSaved={() => { setBuilder(undefined); load() }} />
      )}
    </div>
  )
}
