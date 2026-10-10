import { inr } from '../../api/travel'

// A progress bar towards a monthly target, with a tick showing where you "should" be today (a straight line through the month).
const STATUS = {
  achieved: { label: 'Target hit', cls: 'ok' },
  ahead:    { label: 'Ahead of pace', cls: 'ok' },
  on_track: { label: 'On track', cls: 'info' },
  behind:   { label: 'Behind pace', cls: 'bad' },
}
const FILL = { achieved: '#1f8f55', ahead: '#1f8f55', on_track: '#0a6cc4', behind: '#d6542d' }

export default function TargetBar({ label, p, money = true, daysLeft, compact = false }) {
  if (!p || p.status === 'none') return null
  const fmt = (v) => (money ? inr(v, true) : String(v))
  const st = STATUS[p.status]
  const w = Math.min(100, p.pct)
  return (
    <div className={'tbar' + (compact ? ' is-compact' : '')}>
      {!compact && (
        <div className="tbar-head">
          <span className="tbar-label">{label}</span>
          <span className="tbar-nums"><b>{fmt(p.actual)}</b> of {fmt(p.target)} · {p.pct}%</span>
        </div>
      )}
      <div className="tbar-track" role="progressbar" aria-valuenow={Math.min(100, p.pct)} aria-valuemin={0} aria-valuemax={100} aria-label={`${label || 'Progress'}: ${p.pct}% of target`}>
        <i style={{ width: `${w}%`, background: FILL[p.status] }} />
        {p.status !== 'achieved' && <u style={{ left: `${Math.min(100, p.expected_pct)}%` }} title={`Where you would be today on an even pace: ${p.expected_pct}%`} />}
        {compact && <span className="tbar-in">{p.pct}%</span>}
      </div>
      {!compact && (
        <div className="tbar-foot">
          <span className={'hd-tag ' + st.cls}>{st.label}</span>
          <span className="hd-muted">{p.to_go > 0 ? `${fmt(p.to_go)} to go` : 'Over target'}{daysLeft != null ? ` · ${daysLeft} day${daysLeft === 1 ? '' : 's'} left` : ''}</span>
        </div>
      )}
    </div>
  )
}
