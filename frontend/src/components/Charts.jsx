// ── Lightweight hand-rolled SVG charts (Phase I2) ────────────────────────────
// No chart library — small, themeable SVG components matching the app style.
// Each takes { series:[{label,value}], total, format }.

const PALETTE = ['#0a6cc4', '#16a34a', '#f59e0b', '#0ea5e9', '#ef4444', '#12a89e', '#14b8a6', '#ec4899', '#64748b']

const CURRENCY_SYMBOL = { INR: '₹', USD: '$', EUR: '€', GBP: '£', AED: 'AED ', AUD: 'A$', SGD: 'S$', CAD: 'C$', JPY: '¥' }
// Currencies shown without fraction digits (INR by app convention; JPY has no minor unit).
const WHOLE_UNIT = ['INR', 'JPY']
export const fmt = {
  inr: (v) => '₹' + (Number(v || 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 0 }),
  pct: (v) => `${Number(v || 0)}%`,
  num: (v) => Number(v || 0).toLocaleString('en-IN'),
  // Currency-aware display (Phase J4) — formatting only, no conversion.
  money: (v, code = 'INR') => {
    const c = String(code || 'INR').toUpperCase()
    const sym = CURRENCY_SYMBOL[c] ?? (c + ' ')
    const dec = WHOLE_UNIT.includes(c) ? 0 : 2
    return sym + (Number(v || 0) / 100).toLocaleString('en-IN', { minimumFractionDigits: dec, maximumFractionDigits: dec })
  },
}
export const CURRENCIES = Object.keys(CURRENCY_SYMBOL)
const fmtFor = (f) => fmt[f] || fmt.num

export function KpiCard({ total, format }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', minHeight: 90 }}>
      <span style={{ fontSize: 34, fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>{fmtFor(format)(total)}</span>
    </div>
  )
}

export function BarChart({ series, format }) {
  if (!series?.length) return <Empty />
  const max = Math.max(1, ...series.map(s => s.value))
  const F = fmtFor(format)
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8, paddingTop: 4 }}>
      {series.slice(0, 8).map((s, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5 }}>
          <span style={{ width: 96, color: '#475569', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={s.label}>{s.label}</span>
          <div style={{ flex: 1, background: '#f1f5f9', borderRadius: 6, height: 18, overflow: 'hidden' }}>
            <div style={{ width: `${(s.value / max) * 100}%`, height: '100%', background: PALETTE[i % PALETTE.length], borderRadius: 6, transition: 'width .3s' }} />
          </div>
          <span style={{ width: 80, textAlign: 'right', fontWeight: 700, color: '#111827' }}>{F(s.value)}</span>
        </div>
      ))}
    </div>
  )
}

export function FunnelChart({ series, format }) {
  if (!series?.length) return <Empty />
  const max = Math.max(1, ...series.map(s => s.value))
  const F = fmtFor(format)
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 5, paddingTop: 4 }}>
      {series.slice(0, 8).map((s, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ width: 96, fontSize: 12.5, color: '#475569', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={s.label}>{s.label}</span>
          <div style={{ flex: 1, display: 'flex', justifyContent: 'center' }}>
            <div style={{ width: `${Math.max(8, (s.value / max) * 100)}%`, height: 26, background: PALETTE[i % PALETTE.length], borderRadius: 5, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontSize: 12, fontWeight: 700 }}>{F(s.value)}</div>
          </div>
        </div>
      ))}
    </div>
  )
}

export function PieChart({ series, format }) {
  if (!series?.length) return <Empty />
  const F = fmtFor(format)
  const total = series.reduce((s, x) => s + x.value, 0) || 1
  let acc = 0
  const R = 52, C = 2 * Math.PI * R
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 16, paddingTop: 4 }}>
      <svg viewBox="0 0 130 130" width="118" height="118" style={{ flexShrink: 0 }}>
        <g transform="translate(65,65) rotate(-90)">
          {series.slice(0, 8).map((s, i) => {
            const frac = s.value / total
            const dash = `${frac * C} ${C}`
            const el = <circle key={i} r={R} fill="none" stroke={PALETTE[i % PALETTE.length]} strokeWidth="22" strokeDasharray={dash} strokeDashoffset={-acc * C} />
            acc += frac
            return el
          })}
        </g>
      </svg>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 0 }}>
        {series.slice(0, 8).map((s, i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
            <span style={{ width: 9, height: 9, borderRadius: 2, background: PALETTE[i % PALETTE.length], flexShrink: 0 }} />
            <span style={{ color: '#475569', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{s.label}</span>
            <span style={{ marginLeft: 'auto', fontWeight: 700, color: '#111827' }}>{F(s.value)}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

export function LineChart({ series, format }) {
  if (!series?.length) return <Empty />
  const F = fmtFor(format)
  // Time-series arrives chronological (oldest→newest); render left→right as-is.
  const pts = series
  const max = Math.max(1, ...pts.map(s => s.value))
  const W = 260, H = 90, pad = 6
  const step = pts.length > 1 ? (W - pad * 2) / (pts.length - 1) : 0
  const coords = pts.map((s, i) => [pad + i * step, H - pad - (s.value / max) * (H - pad * 2)])
  const line = coords.map((c, i) => (i ? 'L' : 'M') + c[0].toFixed(1) + ' ' + c[1].toFixed(1)).join(' ')
  // Closed path for the soft area fill under the line.
  const area = pts.length > 1
    ? `${line} L${coords[coords.length - 1][0].toFixed(1)} ${H - pad} L${coords[0][0].toFixed(1)} ${H - pad} Z`
    : ''
  const peak = pts.reduce((m, s, i) => (s.value > pts[m].value ? i : m), 0)
  return (
    <div style={{ paddingTop: 4 }}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} preserveAspectRatio="none">
        {area && <path d={area} fill="#0a6cc4" fillOpacity="0.08" stroke="none" />}
        <path d={line} fill="none" stroke="#0a6cc4" strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
        {coords.map((c, i) => <circle key={i} cx={c[0]} cy={c[1]} r={i === peak ? 3.5 : 2.5} fill={i === peak ? '#074a8c' : '#0a6cc4'} />)}
      </svg>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#94a3b8', marginTop: 4 }}>
        <span>{pts[0]?.label} · {F(pts[0]?.value)}</span>
        <span>{pts[pts.length - 1]?.label} · {F(pts[pts.length - 1]?.value)}</span>
      </div>
    </div>
  )
}

export function DataTable({ series, format }) {
  if (!series?.length) return <Empty />
  const F = fmtFor(format)
  return (
    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
      <tbody>
        {series.slice(0, 12).map((s, i) => (
          <tr key={i} style={{ borderBottom: '1px solid #f1f5f9' }}>
            <td style={{ padding: '.4rem .2rem', color: '#475569' }}>{s.label}</td>
            <td style={{ padding: '.4rem .2rem', textAlign: 'right', fontWeight: 700, color: '#111827' }}>{F(s.value)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function Empty() {
  return <div style={{ color: '#cbd5e1', fontSize: 13, textAlign: 'center', padding: '1.5rem 0' }}>No data</div>
}

export function ChartByType({ type, data, format }) {
  const props = { series: data?.series ?? [], total: data?.total ?? 0, format }
  switch (type) {
    case 'kpi':    return <KpiCard {...props} />
    case 'bar':    return <BarChart {...props} />
    case 'line':   return <LineChart {...props} />
    case 'pie':    return <PieChart {...props} />
    case 'funnel': return <FunnelChart {...props} />
    case 'table':  return <DataTable {...props} />
    default:       return <BarChart {...props} />
  }
}
