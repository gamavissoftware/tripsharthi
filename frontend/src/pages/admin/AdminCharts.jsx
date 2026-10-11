/** Small dependency-free SVG charts for the admin dashboard. Colours come from the brand palette. */

const NAVY = '#0a1f44', BLUE = '#0a6cc4', TEAL = '#12a89e', ORANGE = '#f98f26'

function niceMax(v) {
  if (v <= 0) return 1
  const p = Math.pow(10, Math.floor(Math.log10(v))); const n = v / p
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p
}

/** Vertical bars; `second` (optional) is drawn as a lighter bar beside each main bar (for example GST next to revenue). */
export function BarChart({ data, format = (v) => String(v), height = 190, color = BLUE, secondColor = ORANGE, secondLabel = '', mainLabel = '' }) {
  const n = data.length, W = 640, H = height, padL = 44, padB = 24, padT = 10, innerW = W - padL - 8, innerH = H - padB - padT
  const hasSecond = data.some(d => d.second != null)
  const max = niceMax(Math.max(1, ...data.map(d => Math.max(d.value, d.second || 0))))
  const slot = innerW / n, bw = hasSecond ? slot * 0.34 : slot * 0.56
  const y = (v) => padT + innerH - (v / max) * innerH
  const ticks = [0, 0.5, 1].map(t => t * max)
  return (
    <svg viewBox={`0 0 ${W} ${H}`} role="img" style={{ width: '100%', height: 'auto', display: 'block' }}>
      {ticks.map((t, i) => <g key={i}><line x1={padL} x2={W - 8} y1={y(t)} y2={y(t)} stroke="#e6eef8" /><text x={padL - 6} y={y(t) + 4} textAnchor="end" fontSize="10" fill="#8393ad">{format(t, true)}</text></g>)}
      {data.map((d, i) => {
        const cx = padL + slot * i + slot / 2, x1 = hasSecond ? cx - bw - 1 : cx - bw / 2
        return (
          <g key={d.label + i}>
            <rect x={x1} y={y(d.value)} width={bw} height={Math.max(0, padT + innerH - y(d.value))} rx="3" fill={color}><title>{`${d.title ?? d.label}: ${format(d.value)}${mainLabel ? ' ' + mainLabel : ''}`}</title></rect>
            {hasSecond && <rect x={cx + 1} y={y(d.second || 0)} width={bw} height={Math.max(0, padT + innerH - y(d.second || 0))} rx="3" fill={secondColor}><title>{`${d.title ?? d.label}: ${format(d.second || 0)} ${secondLabel}`}</title></rect>}
            <text x={cx} y={H - 7} textAnchor="middle" fontSize="10" fill="#8393ad">{d.label}</text>
          </g>
        )
      })}
    </svg>
  )
}

/** Donut with a centre label; `data` = [{label, value, color}]. */
export function Donut({ data, center, sub, size = 150 }) {
  const total = data.reduce((a, d) => a + d.value, 0), r = 52, c = 2 * Math.PI * r
  let off = 0
  return (
    <svg viewBox="0 0 150 150" width={size} height={size} role="img" style={{ display: 'block' }}>
      <circle cx="75" cy="75" r={r} fill="none" stroke="#eef3f9" strokeWidth="18" />
      {total > 0 && data.filter(d => d.value > 0).map(d => {
        const len = (d.value / total) * c, el = <circle key={d.label} cx="75" cy="75" r={r} fill="none" stroke={d.color} strokeWidth="18" strokeDasharray={`${len} ${c - len}`} strokeDashoffset={-off} transform="rotate(-90 75 75)"><title>{`${d.label}: ${d.value}`}</title></circle>
        off += len; return el
      })}
      <text x="75" y="76" textAnchor="middle" fontSize="24" fontWeight="800" fill={NAVY}>{center}</text>
      <text x="75" y="94" textAnchor="middle" fontSize="10" fill="#8393ad">{sub}</text>
    </svg>
  )
}

/** Horizontal funnel: each step as a bar sized against the first, with the step-to-step conversion. */
export function Funnel({ steps }) {
  const top = Math.max(1, steps[0]?.n || 1)
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      {steps.map((s, i) => {
        return (
          <div key={s.key}>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 3 }}><span style={{ fontWeight: 600, color: '#26395c' }}>{s.label}</span><span><b>{s.n}</b>{i > 0 ? <span style={{ color: '#8393ad' }}> · {Math.round((s.n / top) * 100)}% of signups</span> : null}</span></div>
            <div className="ad-bar"><i style={{ width: `${(s.n / top) * 100}%`, background: [BLUE, '#3b9bea', TEAL, '#1a9d57'][i] || BLUE }} /></div>
          </div>
        )
      })}
    </div>
  )
}
