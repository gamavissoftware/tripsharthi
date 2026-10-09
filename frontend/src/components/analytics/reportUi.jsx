import { useMemo, useState } from 'react'
import { Check, CheckCheck, Clock, AlertTriangle, CornerUpLeft } from 'lucide-react'

// ── Formatting ───────────────────────────────────────────────────────────────
export const num = (n) => Number(n ?? 0).toLocaleString('en-IN')

export const pct = (n) => `${Math.round(Number(n ?? 0))}%`

/**
 * Paise → ₹ (app-wide money convention).
 *
 * Small sums keep their paise: WhatsApp utility messages cost about 11 paise
 * each, so rounding to whole rupees prints "₹0" next to a real charge and the
 * cost panel reads as if utility traffic were free.
 */
export const inr = (paise) => {
  const rupees = Number(paise ?? 0) / 100
  const dp = rupees > 0 && rupees < 100 ? 2 : 0
  return '₹' + rupees.toLocaleString('en-IN', { minimumFractionDigits: dp, maximumFractionDigits: dp })
}

export function dateTime(v) {
  if (!v) return null
  // Timestamps arrive as UTC 'Y-m-d H:i:s'; without the marker Safari parses
  // them as local and every delivery time shifts by the offset.
  const d = new Date(String(v).replace(' ', 'T') + 'Z')
  if (Number.isNaN(d.getTime())) return String(v)
  return d.toLocaleString(undefined, {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  })
}

export function dayLabel(iso) {
  const d = new Date(iso + 'T00:00:00')
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
}

/** Today, in the browser's own timezone — not UTC, which can be a day off. */
export function today() {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}

export function daysAgo(n) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}

// ── Delivery status ──────────────────────────────────────────────────────────
// The tick vocabulary operators already read on their own phone: one tick left
// us, two ticks reached the handset, two blue ticks were opened.
export const STATUS_META = {
  queued:    { label: 'Queued',    color: '#8e8e99', bg: '#f4f4f5', Icon: Clock,
               hint: 'Waiting in the send queue' },
  sent:      { label: 'Sent',      color: '#8e8e99', bg: '#f4f4f5', Icon: Check,
               hint: 'Left TripSarthi — WhatsApp has not confirmed it reached the phone' },
  delivered: { label: 'Delivered', color: '#4c4c57', bg: '#e8f3fc', Icon: CheckCheck,
               hint: 'Reached the phone, not opened yet' },
  read:      { label: 'Read',      color: '#1d4ed8', bg: '#eff6ff', Icon: CheckCheck,
               hint: 'Opened by the recipient' },
  failed:    { label: 'Failed',    color: '#b91c1c', bg: '#fef2f2', Icon: AlertTriangle,
               hint: 'WhatsApp refused to deliver it' },
}

export function StatusPill({ status, title }) {
  const meta = STATUS_META[status] ?? STATUS_META.queued
  const { Icon } = meta
  return (
    <span
      title={title ?? meta.hint}
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 5,
        padding: '2px 9px', borderRadius: 'var(--r-pill)',
        background: meta.bg, color: meta.color,
        fontSize: '.72rem', fontWeight: 650, whiteSpace: 'nowrap',
      }}
    >
      <Icon size={13} strokeWidth={2.4} />{meta.label}
    </span>
  )
}

export function RepliedPill({ at }) {
  if (!at) return <span style={{ color: 'var(--text-3)' }}>—</span>
  return (
    <span
      title={`Replied ${dateTime(at)}`}
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 5,
        padding: '2px 9px', borderRadius: 'var(--r-pill)',
        background: '#ecfdf5', color: '#047857', fontSize: '.72rem', fontWeight: 650,
      }}
    >
      <CornerUpLeft size={12} strokeWidth={2.4} />Replied
    </span>
  )
}

// ── Metric tile ──────────────────────────────────────────────────────────────
export function MetricTile({ label, value, sub, tone = 'default', title }) {
  const tones = {
    default: 'var(--text)',
    good:    'var(--success)',
    warn:    'var(--warning)',
    bad:     'var(--danger)',
    brand:   'var(--primary)',
  }
  return (
    <div
      title={title}
      style={{
        flex: '1 1 150px', minWidth: 130, padding: '.95rem 1.1rem',
        background: 'var(--surface)', border: '1px solid var(--border)',
        borderRadius: 'var(--r-md)', boxShadow: 'var(--shadow-sm)',
      }}
    >
      <div style={{
        fontSize: '.69rem', fontWeight: 600, letterSpacing: '.08em',
        textTransform: 'uppercase', color: 'var(--text-3)', marginBottom: 6,
      }}>{label}</div>
      <div style={{
        fontSize: '1.65rem', fontWeight: 750, lineHeight: 1.1,
        color: tones[tone], letterSpacing: '-.02em', fontVariantNumeric: 'tabular-nums',
      }}>{value}</div>
      {sub != null && (
        <div style={{ fontSize: '.75rem', color: 'var(--text-3)', marginTop: 3 }}>{sub}</div>
      )}
    </div>
  )
}

// ── Date range ───────────────────────────────────────────────────────────────
export const RANGE_PRESETS = [
  { key: '7d',   label: 'Last 7 days',  from: () => daysAgo(6) },
  { key: '30d',  label: 'Last 30 days', from: () => daysAgo(29) },
  { key: '90d',  label: 'Last 90 days', from: () => daysAgo(89) },
  { key: 'all',  label: 'All time',     from: () => null },
]

export function RangePicker({ value, onChange }) {
  const [custom, setCustom] = useState(false)

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '.5rem', flexWrap: 'wrap' }}>
      <div style={{
        display: 'inline-flex', background: 'var(--surface-2)',
        border: '1px solid var(--border)', borderRadius: 'var(--r-sm)', padding: 2,
      }}>
        {RANGE_PRESETS.map(p => {
          const active = !custom && value.preset === p.key
          return (
            <button
              key={p.key}
              onClick={() => { setCustom(false); onChange({ preset: p.key, from: p.from(), to: p.from() ? today() : null }) }}
              style={{
                border: 'none', cursor: 'pointer', padding: '.3rem .7rem',
                borderRadius: 6, fontSize: '.8rem', fontWeight: 600,
                background: active ? 'var(--surface)' : 'transparent',
                color: active ? 'var(--text)' : 'var(--text-3)',
                boxShadow: active ? 'var(--shadow-sm)' : 'none',
              }}
            >{p.label}</button>
          )
        })}
        <button
          onClick={() => setCustom(c => !c)}
          style={{
            border: 'none', cursor: 'pointer', padding: '.3rem .7rem',
            borderRadius: 6, fontSize: '.8rem', fontWeight: 600,
            background: custom ? 'var(--surface)' : 'transparent',
            color: custom ? 'var(--text)' : 'var(--text-3)',
            boxShadow: custom ? 'var(--shadow-sm)' : 'none',
          }}
        >Custom</button>
      </div>

      {custom && (
        <>
          <input
            type="date" className="form-input" style={{ width: 'auto', padding: '.34rem .6rem' }}
            value={value.from ?? ''} max={value.to ?? today()}
            onChange={e => onChange({ preset: 'custom', from: e.target.value || null, to: value.to ?? today() })}
          />
          <span style={{ color: 'var(--text-3)', fontSize: '.8rem' }}>to</span>
          <input
            type="date" className="form-input" style={{ width: 'auto', padding: '.34rem .6rem' }}
            value={value.to ?? ''} max={today()}
            onChange={e => onChange({ preset: 'custom', from: value.from ?? null, to: e.target.value || null })}
          />
        </>
      )}
    </div>
  )
}

// ── Funnel ───────────────────────────────────────────────────────────────────
const FUNNEL_STEPS = [
  { key: 'sent',      label: 'Sent',      color: '#0a6cc4', hint: 'Handed to WhatsApp' },
  { key: 'delivered', label: 'Delivered', color: '#3b82f6', hint: 'Reached the phone' },
  { key: 'read',      label: 'Read',      color: '#0ea5e9', hint: 'Opened by the recipient' },
  { key: 'replied',   label: 'Replied',   color: '#059669', hint: 'Wrote back within the window' },
]

export function FunnelBars({ funnel, loading }) {
  const base = Number(funnel?.sent ?? 0)

  return (
    <div>
      {FUNNEL_STEPS.map(step => {
        const value = Number(funnel?.[step.key] ?? 0)
        const share = base > 0 ? Math.round((value / base) * 100) : 0
        return (
          <div key={step.key} style={{ display: 'flex', alignItems: 'center', gap: '.75rem', marginBottom: '.55rem' }}>
            <div title={step.hint} style={{
              width: 82, fontSize: '.78rem', fontWeight: 600, color: 'var(--text-2)', flexShrink: 0,
            }}>{step.label}</div>
            <div style={{
              flex: 1, height: 26, borderRadius: 6, background: 'var(--surface-2)',
              overflow: 'hidden', border: '1px solid var(--border)',
            }}>
              <div style={{
                width: loading ? 0 : `${share}%`, height: '100%', background: step.color,
                transition: 'width .5s cubic-bezier(.4,0,.2,1)', minWidth: share > 0 ? 6 : 0,
              }} />
            </div>
            <div style={{
              width: 140, textAlign: 'right', fontSize: '.78rem', color: 'var(--text-2)',
              flexShrink: 0, fontVariantNumeric: 'tabular-nums',
            }}>
              <strong style={{ color: 'var(--text)' }}>{num(value)}</strong>
              <span style={{ color: 'var(--text-3)' }}> · {share}% of sent</span>
            </div>
          </div>
        )
      })}
      {Number(funnel?.failed ?? 0) > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: '.75rem', marginTop: '.85rem' }}>
          <div style={{ width: 82, fontSize: '.78rem', fontWeight: 600, color: 'var(--danger)', flexShrink: 0 }}>
            Failed
          </div>
          <div style={{ flex: 1, fontSize: '.78rem', color: 'var(--text-2)' }}>
            <strong style={{ color: 'var(--danger)' }}>{num(funnel.failed)}</strong> never left —
            WhatsApp refused them. See the failure reasons below.
          </div>
        </div>
      )}
    </div>
  )
}

// ── Trend chart ──────────────────────────────────────────────────────────────
const TREND_SERIES = [
  { key: 'sent',      label: 'Sent',      color: '#0a6cc4' },
  { key: 'delivered', label: 'Delivered', color: '#3b82f6' },
  { key: 'read',      label: 'Read',      color: '#0ea5e9' },
  { key: 'replied',   label: 'Replied',   color: '#059669' },
  { key: 'failed',    label: 'Failed',    color: '#dc2626' },
]

/**
 * Daily delivery trend. Hand-rolled SVG to match the rest of the app (no chart
 * library anywhere in this codebase) and to keep the bundle honest.
 */
export function TrendChart({ series, height = 190 }) {
  const [hidden, setHidden] = useState(() => new Set(['failed']))
  const [hover, setHover]   = useState(null)

  const visible = TREND_SERIES.filter(s => !hidden.has(s.key))
  const points  = series ?? []

  const max = useMemo(() => Math.max(
    1,
    ...points.flatMap(p => visible.map(s => Number(p[s.key] ?? 0)))
  ), [points, visible])

  if (points.length === 0) {
    return <div className="empty-state"><div className="empty-state-text">No messages in this period</div></div>
  }

  const W = 1000
  const H = height
  const padX = 8
  const padY = 12
  const x = (i) => points.length === 1 ? W / 2 : padX + (i * (W - padX * 2)) / (points.length - 1)
  const y = (v) => H - padY - (Number(v) / max) * (H - padY * 2)

  const toggle = (key) => setHidden(prev => {
    const next = new Set(prev)
    next.has(key) ? next.delete(key) : next.add(key)
    // Never let the operator blank the chart entirely.
    return next.size === TREND_SERIES.length ? prev : next
  })

  const hovered = hover != null ? points[hover] : null

  return (
    <div>
      <div style={{ position: 'relative' }}>
        <svg viewBox={`0 0 ${W} ${H}`} style={{ width: '100%', height, display: 'block' }} preserveAspectRatio="none">
          {[0.25, 0.5, 0.75, 1].map(f => (
            <line key={f} x1={0} x2={W} y1={y(max * f)} y2={y(max * f)} stroke="var(--border)" strokeWidth="1" />
          ))}

          {visible.map(s => (
            <polyline
              key={s.key}
              fill="none" stroke={s.color} strokeWidth="2.5"
              strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke"
              points={points.map((p, i) => `${x(i)},${y(p[s.key] ?? 0)}`).join(' ')}
            />
          ))}

          {hover != null && (
            <line x1={x(hover)} x2={x(hover)} y1={padY} y2={H - padY} stroke="var(--border-strong)" strokeWidth="1" />
          )}

          {/* Invisible hit areas: one column per day. */}
          {points.map((p, i) => (
            <rect
              key={p.date} x={x(i) - (W / points.length) / 2} y={0}
              width={W / points.length} height={H} fill="transparent"
              onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}
            />
          ))}
        </svg>

        {hovered && (
          <div style={{
            position: 'absolute', top: 4,
            left: `${(hover / Math.max(1, points.length - 1)) * 100}%`,
            transform: hover > points.length / 2 ? 'translateX(-105%)' : 'translateX(5%)',
            background: 'var(--surface)', border: '1px solid var(--border)',
            borderRadius: 'var(--r-sm)', boxShadow: 'var(--shadow-md)',
            padding: '.5rem .7rem', fontSize: '.75rem', pointerEvents: 'none', minWidth: 130,
          }}>
            <div style={{ fontWeight: 700, marginBottom: 4 }}>{dayLabel(hovered.date)}</div>
            {visible.map(s => (
              <div key={s.key} style={{ display: 'flex', justifyContent: 'space-between', gap: '.75rem' }}>
                <span style={{ color: s.color, fontWeight: 600 }}>{s.label}</span>
                <span style={{ fontVariantNumeric: 'tabular-nums' }}>{num(hovered[s.key])}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      <div style={{
        display: 'flex', justifyContent: 'space-between', fontSize: '.7rem',
        color: 'var(--text-3)', marginTop: 4,
      }}>
        <span>{dayLabel(points[0].date)}</span>
        <span>{dayLabel(points[points.length - 1].date)}</span>
      </div>

      <div style={{ display: 'flex', gap: '.85rem', flexWrap: 'wrap', marginTop: '.75rem' }}>
        {TREND_SERIES.map(s => {
          const off = hidden.has(s.key)
          return (
            <button
              key={s.key} onClick={() => toggle(s.key)}
              title={off ? `Show ${s.label}` : `Hide ${s.label}`}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 6, border: 'none',
                background: 'none', cursor: 'pointer', padding: 0,
                fontSize: '.78rem', fontWeight: 600,
                color: off ? 'var(--text-3)' : 'var(--text-2)', opacity: off ? 0.55 : 1,
              }}
            >
              <span style={{
                width: 10, height: 10, borderRadius: 3,
                background: off ? 'transparent' : s.color,
                border: `2px solid ${s.color}`,
              }} />
              {s.label}
            </button>
          )
        })}
      </div>
    </div>
  )
}

// ── Misc ─────────────────────────────────────────────────────────────────────
export function Section({ title, subtitle, right, children, style }) {
  return (
    <div className="card" style={{ padding: '1.35rem', ...style }}>
      <div style={{
        display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between',
        gap: '.75rem', marginBottom: subtitle ? '.35rem' : '1rem', flexWrap: 'wrap',
      }}>
        <div style={{ fontWeight: 700, fontSize: '1rem' }}>{title}</div>
        {right}
      </div>
      {subtitle && (
        <div style={{ fontSize: '.78rem', color: 'var(--text-3)', marginBottom: '1rem' }}>{subtitle}</div>
      )}
      {children}
    </div>
  )
}

/**
 * A labelled horizontal bar list.
 *
 * The accessors are deliberately not named `valueOf`: a prop by that name
 * shadows Object.prototype.valueOf on the props object, and React calls it
 * while coercing props internally — which blanks the entire page with a
 * "Cannot convert undefined or null to object" thrown from inside react-dom,
 * pointing nowhere near the real cause.
 */
export function Bars({ rows = [], colorFor, getValue = (r) => r.count, getLabel = (r) => r.label, suffix }) {
  const max = Math.max(1, ...rows.map((r, i) => getValue(r, i)))
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '.6rem' }}>
      {rows.map((r, i) => (
        <div key={i}>
          <div style={{
            display: 'flex', justifyContent: 'space-between', gap: '.75rem',
            fontSize: '.8rem', marginBottom: 4,
          }}>
            <span style={{ color: 'var(--text-2)', fontWeight: 500, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {getLabel(r)}
            </span>
            <span style={{ color: 'var(--text)', fontWeight: 650, whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>
              {num(getValue(r))}{suffix?.(r)}
            </span>
          </div>
          <div style={{ height: 8, borderRadius: 4, background: 'var(--surface-2)', overflow: 'hidden' }}>
            <div style={{
              width: `${(getValue(r) / max) * 100}%`, height: '100%',
              background: colorFor?.(r, i) ?? 'var(--primary)', borderRadius: 4,
            }} />
          </div>
        </div>
      ))}
    </div>
  )
}
