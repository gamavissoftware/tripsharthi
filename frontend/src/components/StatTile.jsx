import { Link } from 'react-router-dom'

/**
 * The one compact "number card" used across the customer pages (same look as the dashboard KPIs): icon tile, small caption, big number,
 * optional note underneath. Put them in a <StatRow> so they sit in a single row on a wide screen instead of wrapping one by one.
 *  - `accent`  colours the number (for example red for something overdue)
 *  - `to`      makes the whole tile a link, `onClick` a button (with `active` to show it is selected)
 */
export function StatRow({ children, style }) {
  return <div className="st-row" style={style}>{children}</div>
}

export function StatTile({ icon: Icon, tone = '#0a6cc4', label, value, sub, accent, to, onClick, active, title }) {
  const body = (
    <>
      {Icon && <span className="st-ico"><Icon size={16} /></span>}
      <span className="st-l">{label}</span>
      <span className="st-v" style={accent ? { color: accent } : undefined}>{value}</span>
      {sub ? <span className="st-n">{sub}</span> : null}
    </>
  )
  const props = { className: 'st' + (active ? ' on' : '') + (to || onClick ? ' click' : ''), style: { '--tone': tone }, title }
  if (to) return <Link to={to} {...props}>{body}</Link>
  if (onClick) return <button type="button" onClick={onClick} {...props}>{body}</button>
  return <div {...props}>{body}</div>
}
