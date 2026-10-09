export default function Pill({ map, value }) {
  const s = map[value] ?? { label: value, bg: '#f1f5f9', color: '#475569' }
  return <span style={{ background: s.bg, color: s.color, padding: '2px 9px', borderRadius: 999, fontSize: 12, fontWeight: 700, whiteSpace: 'nowrap' }}>{s.label}</span>
}
