import { useState, useEffect, useCallback } from 'react'
import { billing as billingApi } from '../api/billing'
import { analytics as analyticsApi } from '../api/analytics'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'
import { Rocket, Zap, Crown, Check, AlertTriangle, Info, Users, User, Smartphone, CheckCircle2, XCircle, Clock, KeyRound, Sparkles, Briefcase } from 'lucide-react'

// CRM plan-usage bars (Phase K2) — fetches its own data; renders nothing if empty.
function CrmUsageBars() {
  const [u, setU] = useState(null)
  useEffect(() => { crm.planUsage().then(r => setU(r.data)).catch(() => {}) }, [])
  if (!u) return null
  const rows = [
    ['💼', 'Deals', 'deals', '#0a6cc4'],
    ['📊', 'Dashboards', 'dashboards', '#0891b2'],
    ['📦', 'Custom Objects', 'custom_objects', '#a16207'],
    ['🛤', 'Pipelines', 'pipelines', '#059669'],
  ]
  return (
    <>
      {rows.map(([icon, label, key, color]) => (
        <UsageBar key={key} icon={icon} label={label} current={u.usage?.[key]} limit={u.unlimited ? 999999 : (u.limits?.[key] ?? 0)} color={color} />
      ))}
    </>
  )
}

// ── Load Razorpay checkout.js once ───────────────────────────────────────
function loadRazorpayScript() {
  return new Promise(resolve => {
    if (window.Razorpay) { resolve(true); return }
    const s = document.createElement('script')
    s.src = 'https://checkout.razorpay.com/v1/checkout.js'
    s.onload  = () => resolve(true)
    s.onerror = () => resolve(false)
    document.head.appendChild(s)
  })
}

// ── Inject styles ─────────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-bill-css')) {
  const el = document.createElement('style')
  el.id = 'lp-bill-css'
  el.textContent = `
    @keyframes lp-bill-bar { from { width:0; } }
    @keyframes lp-bill-in  { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
    @keyframes lp-bill-spin { to { transform:rotate(360deg); } }
    @keyframes lp-bill-pulse { 0%,100%{opacity:1}50%{opacity:.4} }
    .lp-bill-plan-card {
      border:2px solid #e5e7eb;
      border-radius:18px;
      padding:1.75rem 1.5rem;
      flex:1 1 220px;
      min-width:200px;
      max-width:340px;
      display:flex;
      flex-direction:column;
      background:#fff;
      position:relative;
      transition:border-color .18s, box-shadow .18s, transform .18s;
      animation: lp-bill-in .25s ease both;
    }
    .lp-bill-plan-card:hover {
      box-shadow:0 8px 30px rgba(0,0,0,.1);
      transform:translateY(-3px);
    }
    .lp-bill-plan-card.popular {
      border-color:#0a6cc4;
      box-shadow:0 8px 32px rgba(10,108,196,.2);
    }
    .lp-bill-plan-card.current-plan {
      border-color:#22c55e;
      box-shadow:0 4px 20px rgba(34,197,94,.15);
    }
    .lp-bill-check-item {
      display:flex;
      align-items:flex-start;
      gap:.55rem;
      font-size:13.5px;
      color:#374151;
      margin-bottom:.55rem;
    }
    .lp-bill-check-icon {
      width:18px;height:18px;border-radius:50%;
      background:#dcfce7;
      display:flex;align-items:center;justify-content:center;
      font-size:10px;flex-shrink:0;margin-top:1px;
    }
    .lp-bill-usage-bar-bg {
      height:8px;border-radius:999px;background:#f3f4f6;overflow:hidden;
    }
    .lp-bill-usage-bar-fill {
      height:100%;border-radius:999px;
      animation:lp-bill-bar .7s ease both;
      transition:width .5s ease;
    }
    .lp-bill-toggle-track {
      width:44px;height:24px;background:#d1d5db;border-radius:999px;
      position:relative;cursor:pointer;transition:background .2s;
    }
    .lp-bill-toggle-track::after {
      content:'';position:absolute;left:4px;top:4px;
      width:16px;height:16px;border-radius:50%;background:#fff;
      box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .2s;
    }
    .lp-bill-toggle-track.on { background:#0a6cc4; }
    .lp-bill-toggle-track.on::after { transform:translateX(20px); }
    .lp-bill-modal-backdrop {
      position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);
      display:flex;align-items:center;justify-content:center;z-index:1000;padding:1rem;
    }
  `
  document.head.appendChild(el)
}

// ── Constants ─────────────────────────────────────────────────────────────
const PLAN_LIMITS = {
  free:    { contacts:500,    agents:1,   active_flows:2,  waba:1  },
  starter: { contacts:2000,   agents:2,   active_flows:5,  waba:1  },
  growth:  { contacts:10000,  agents:10,  active_flows:20, waba:3  },
  pro:     { contacts:999999, agents:999, active_flows:999,waba:999 },
}

const PLANS = [
  {
    key: 'starter',
    name: 'Starter',
    icon: <Rocket size={20} strokeWidth={1.8} color="#0891b2" />,
    monthly: 1499,
    annual: 1199,
    color: '#0891b2',
    colorBg: '#e0f2fe',
    features: [
      '1 WhatsApp Number',
      '2 Team agents',
      '5 Active flows',
      '2,000 contacts',
      'CSV import',
      'Basic analytics',
    ],
  },
  {
    key: 'growth',
    name: 'Growth',
    icon: <Zap size={20} strokeWidth={1.8} color="#0a6cc4" />,
    monthly: 3999,
    annual: 3199,
    color: '#0a6cc4',
    colorBg: '#ede9fe',
    popular: true,
    features: [
      '3 WhatsApp Numbers',
      '10 Team agents',
      '20 Active flows',
      '10,000 contacts',
      'Meta Lead Ads',
      'Advanced analytics',
      'Priority support',
    ],
  },
  {
    key: 'pro',
    name: 'Pro',
    icon: <Crown size={20} strokeWidth={1.8} color="#0e8f8c" />,
    monthly: 6999,
    annual: 5599,
    color: '#0e8f8c',
    colorBg: '#ede9fe',
    features: [
      'Unlimited WhatsApp Numbers',
      'Unlimited agents',
      'Unlimited flows',
      'Unlimited contacts',
      'All integrations',
      'White-label ready',
      'Dedicated support',
    ],
  },
]

function fmtINR(n) {
  return '₹' + Number(n).toLocaleString('en-IN')
}
function fmtDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' })
}
function isPlanLimitError(err) {
  if (!err) return false
  if (err?.errors?.error === 'plan_limit_exceeded') return true
  if (typeof err.message === 'string' && err.message.includes('plan_limit_exceeded')) return true
  return false
}

// ── Usage Bar ─────────────────────────────────────────────────────────────
function UsageBar({ icon, label, current, limit, color }) {
  const isUnlimited = limit >= 999999
  const pct = isUnlimited || !current ? 0 : Math.min(100, Math.round((current / limit) * 100))
  const barColor = pct > 90 ? '#dc2626' : pct >= 75 ? '#f59e0b' : color
  const displayLimit = isUnlimited ? '∞' : limit.toLocaleString('en-IN')
  const displayCurrent = current != null ? current.toLocaleString('en-IN') : '—'

  return (
    <div style={{ marginBottom: '1.1rem' }}>
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:6 }}>
        <div style={{ display:'flex', alignItems:'center', gap:'.45rem' }}>
          <span style={{ display:'inline-flex', alignItems:'center', color:'#6b7280' }}>{icon}</span>
          <span style={{ fontSize:13.5, fontWeight:600, color:'#374151' }}>{label}</span>
        </div>
        <div style={{ display:'flex', alignItems:'center', gap:'.5rem' }}>
          {!isUnlimited && current != null && (
            <span style={{ fontSize:11, fontWeight:700, color: barColor, background: barColor+'15', padding:'.1rem .45rem', borderRadius:999 }}>
              {pct}%
            </span>
          )}
          <span style={{ fontSize:12.5, color:'#6b7280', fontVariantNumeric:'tabular-nums' }}>
            {current != null ? `${displayCurrent} / ${displayLimit}` : `— / ${displayLimit}`}
          </span>
        </div>
      </div>
      <div className="lp-bill-usage-bar-bg">
        <div
          className="lp-bill-usage-bar-fill"
          style={{
            width: isUnlimited ? '100%' : (current != null ? `${pct}%` : '0%'),
            background: isUnlimited
              ? 'linear-gradient(90deg, #bbf7d0, #86efac)'
              : `linear-gradient(90deg, ${barColor}cc, ${barColor})`,
            opacity: isUnlimited ? 0.5 : 1,
          }}
        />
      </div>
    </div>
  )
}

// ── Plan Card ─────────────────────────────────────────────────────────────
function PlanCard({ plan, isAnnual, currentPlan, onSubscribe, subscribing, offer }) {
  const isCurrent = currentPlan === plan.key
  const price = isAnnual ? plan.annual : plan.monthly
  const saving = plan.monthly - plan.annual
  const isLoading = subscribing === plan.key
  // A promotion or coupon from the server: show what is actually charged (per month, plus the amount due today).
  const hasOffer = Boolean(offer && offer.discount_paise > 0)
  const dueToday = hasOffer ? Math.round(offer.charged_paise / 100) : 0
  const shownPrice = hasOffer ? Math.round(isAnnual ? dueToday / 12 : dueToday) : price

  return (
    <div
      className={`lp-bill-plan-card ${plan.popular ? 'popular' : ''} ${isCurrent ? 'current-plan' : ''}`}
      style={{ animationDelay: `${PLANS.indexOf(plan) * 80}ms` }}
    >
      {/* Popular badge */}
      {plan.popular && !isCurrent && (
        <div style={{
          position:'absolute', top:-14, left:'50%', transform:'translateX(-50%)',
          background:'linear-gradient(135deg,#0a6cc4,#12a89e)',
          color:'#fff', fontSize:11, fontWeight:800, letterSpacing:'.05em',
          padding:'.25rem .85rem', borderRadius:999, whiteSpace:'nowrap',
          boxShadow:'0 2px 8px rgba(10,108,196,.4)',
        }}>
          MOST POPULAR
        </div>
      )}
      {isCurrent && (
        <div style={{
          position:'absolute', top:-14, left:'50%', transform:'translateX(-50%)',
          background:'linear-gradient(135deg,#16a34a,#22c55e)',
          color:'#fff', fontSize:11, fontWeight:800, letterSpacing:'.05em',
          padding:'.25rem .85rem', borderRadius:999, whiteSpace:'nowrap',
          boxShadow:'0 2px 8px rgba(34,197,94,.4)',
        }}>
          CURRENT PLAN
        </div>
      )}

      {/* Plan icon + name */}
      <div style={{ display:'flex', alignItems:'center', gap:'.7rem', marginBottom:'1rem', marginTop: (plan.popular || isCurrent) ? '.5rem' : 0 }}>
        <div style={{
          width:42, height:42, borderRadius:12,
          background: plan.colorBg,
          display:'flex', alignItems:'center', justifyContent:'center', fontSize:20,
        }}>
          {plan.icon}
        </div>
        <div>
          <div style={{ fontSize:17, fontWeight:800, color:'#111827' }}>{plan.name}</div>
          {plan.popular && (
            <div style={{ fontSize:11, color: plan.color, fontWeight:600 }}>Best for growing teams</div>
          )}
        </div>
      </div>

      {/* Price */}
      <div style={{ marginBottom:'1.2rem' }}>
        <div style={{ display:'flex', alignItems:'flex-end', gap:'.25rem' }}>
          {hasOffer && <span style={{ fontSize:16, fontWeight:600, color:'#9ca3af', textDecoration:'line-through', marginBottom:3 }}>{fmtINR(price)}</span>}
          <span style={{ fontSize:32, fontWeight:900, color: plan.color, lineHeight:1 }}>
            {fmtINR(shownPrice)}
          </span>
          <span style={{ fontSize:13, color:'#9ca3af', marginBottom:4 }}>/mo</span>
        </div>
        {hasOffer && (
          <div style={{ marginTop:6 }}>
            <span style={{ background:'linear-gradient(135deg,#fef3c7,#fde68a)', color:'#92400e', fontSize:11, fontWeight:800, padding:'.15rem .55rem', borderRadius:999 }}>{offer.label}</span>
            <div style={{ fontSize:12, color:'#374151', marginTop:5 }}>You pay <b>{fmtINR(dueToday)}</b> {isAnnual ? 'today for the year' : 'today'}{offer.continuing ? ' (your code continues)' : ''}</div>
          </div>
        )}
        {isAnnual && (
          <div style={{ fontSize:11.5, color:'#16a34a', fontWeight:600, marginTop:3 }}>
            Save {fmtINR(saving * 12)}/year vs monthly
          </div>
        )}
        {!isAnnual && (
          <div style={{ fontSize:11.5, color:'#9ca3af', marginTop:3 }}>
            Save {fmtINR(saving)}/mo with annual billing
          </div>
        )}
      </div>

      {/* Features */}
      <div style={{ flex:1, marginBottom:'1.25rem' }}>
        {plan.features.map(f => (
          <div key={f} className="lp-bill-check-item">
            <div className="lp-bill-check-icon" style={{ background: plan.colorBg }}>
              <Check size={11} strokeWidth={3} color={plan.color} />
            </div>
            <span>{f}</span>
          </div>
        ))}
      </div>

      {/* CTA */}
      <button
        onClick={() => !isCurrent && onSubscribe(plan.key)}
        disabled={isLoading || isCurrent}
        style={{
          width:'100%', padding:'.7rem 1rem', borderRadius:10, border:'none',
          background: isCurrent
            ? '#f0fdf4'
            : plan.popular
            ? 'linear-gradient(135deg, #0a6cc4, #12a89e)'
            : `linear-gradient(135deg, ${plan.color}dd, ${plan.color})`,
          color: isCurrent ? '#16a34a' : '#fff',
          fontWeight:700, fontSize:14, cursor: isCurrent ? 'default' : 'pointer',
          boxShadow: isCurrent ? 'none' : `0 2px 10px ${plan.color}44`,
          display:'flex', alignItems:'center', justifyContent:'center', gap:'.4rem',
          transition:'opacity .15s',
        }}
      >
        {isLoading ? (
          <>
            <span style={{ width:14,height:14,border:'2px solid rgba(255,255,255,.4)',borderTopColor:'#fff',borderRadius:'50%',animation:'lp-bill-spin .7s linear infinite' }}/>
            Processing…
          </>
        ) : isCurrent ? <><Check size={15} strokeWidth={2.5} /> Active Plan</> : `Upgrade to ${plan.name}`}
      </button>
    </div>
  )
}

// ── Cancel Modal ──────────────────────────────────────────────────────────
function CancelModal({ onConfirm, onClose, cancelling }) {
  return (
    <div className="lp-bill-modal-backdrop" onClick={e => { if(e.target===e.currentTarget) onClose() }}>
      <div style={{
        background:'#fff', borderRadius:20, width:440, maxWidth:'94vw',
        overflow:'hidden', boxShadow:'0 25px 60px rgba(0,0,0,.18)',
        animation:'lp-bill-in .2s ease',
      }}>
        {/* Header */}
        <div style={{ background:'linear-gradient(135deg,#fef2f2,#fff1f2)', padding:'1.4rem 1.6rem', borderBottom:'1px solid #fca5a5' }}>
          <div style={{ marginBottom:8 }}><AlertTriangle size={30} strokeWidth={1.8} color="#dc2626" /></div>
          <div style={{ fontSize:18, fontWeight:800, color:'#991b1b' }}>Cancel Subscription</div>
          <div style={{ fontSize:13, color:'#dc2626', marginTop:4 }}>This action will affect your account</div>
        </div>
        <div style={{ padding:'1.4rem 1.6rem' }}>
          <p style={{ fontSize:14, color:'#374151', lineHeight:1.6, marginBottom:'1.25rem' }}>
            Are you sure you want to cancel? You will <strong>lose access to all paid features</strong> at the end of your current billing period. Your contacts and data will be retained.
          </p>
          <div style={{ background:'#fffbeb', borderRadius:10, padding:'.8rem 1rem', marginBottom:'1.4rem', fontSize:13, color:'#92400e', display:'flex', gap:'.6rem' }}>
            <Info size={14} strokeWidth={2} style={{ flexShrink:0, marginTop:2 }} />
            <span>You can re-subscribe at any time to regain full access.</span>
          </div>
          <div style={{ display:'flex', gap:'.75rem' }}>
            <button
              onClick={onConfirm}
              disabled={cancelling}
              style={{
                flex:1, padding:'.65rem 1rem', borderRadius:10, border:'none',
                background:'#dc2626', color:'#fff', fontWeight:700, fontSize:14, cursor:'pointer',
                display:'flex', alignItems:'center', justifyContent:'center', gap:'.4rem',
              }}
            >
              {cancelling ? (
                <><span style={{ width:14,height:14,border:'2px solid rgba(255,255,255,.4)',borderTopColor:'#fff',borderRadius:'50%',animation:'lp-bill-spin .7s linear infinite' }}/> Cancelling…</>
              ) : 'Yes, cancel plan'}
            </button>
            <button
              onClick={onClose}
              disabled={cancelling}
              style={{
                flex:1, padding:'.65rem 1rem', borderRadius:10, border:'1.5px solid #e5e7eb',
                background:'#fff', color:'#374151', fontWeight:600, fontSize:14, cursor:'pointer',
              }}
            >
              Keep subscription
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Current Plan Hero ─────────────────────────────────────────────────────
function CurrentPlanHero({ plan, status, renewsOn, onCancel }) {
  const cfg = {
    free:    { label:'Free Plan',    color:'#6b7280', bg:'linear-gradient(135deg,#f9fafb,#f3f4f6)', icon:<Sparkles size={26} strokeWidth={1.8} color="#9ca3af" />, accent:'#9ca3af' },
    starter: { label:'Starter Plan', color:'#0891b2', bg:'linear-gradient(135deg,#e0f2fe,#bae6fd)', icon:<Rocket size={26} strokeWidth={1.8} color="#0891b2" />, accent:'#0891b2' },
    growth:  { label:'Growth Plan',  color:'#0a6cc4', bg:'linear-gradient(135deg,#ede9fe,#ddd6fe)', icon:<Zap size={26} strokeWidth={1.8} color="#0a6cc4" />, accent:'#0a6cc4' },
    pro:     { label:'Pro Plan',     color:'#0e8f8c', bg:'linear-gradient(135deg,#faf5ff,#ede9fe)', icon:<Crown size={26} strokeWidth={1.8} color="#0e8f8c" />, accent:'#0e8f8c' },
  }[plan] ?? { label: plan, color:'#6b7280', bg:'#f3f4f6', icon:<Briefcase size={26} strokeWidth={1.8} color="#6b7280" />, accent:'#6b7280' }

  return (
    <div style={{
      borderRadius:18, padding:'1.6rem 1.75rem',
      background: cfg.bg,
      border:`1.5px solid ${cfg.accent}33`,
      display:'flex', alignItems:'center', justifyContent:'space-between', flexWrap:'wrap', gap:'1rem',
      marginBottom:'1.5rem',
      animation:'lp-bill-in .2s ease',
    }}>
      <div style={{ display:'flex', alignItems:'center', gap:'1rem' }}>
        <div style={{
          width:56, height:56, borderRadius:16,
          background:`${cfg.accent}18`, display:'flex', alignItems:'center', justifyContent:'center', fontSize:26,
        }}>
          {cfg.icon}
        </div>
        <div>
          <div style={{ fontSize:13, fontWeight:600, color: cfg.color, textTransform:'uppercase', letterSpacing:'.06em', marginBottom:3 }}>
            Your Current Plan
          </div>
          <div style={{ fontSize:22, fontWeight:900, color:'#111827', letterSpacing:'-.02em' }}>
            {cfg.label}
          </div>
          {renewsOn && (
            <div style={{ fontSize:12.5, color:'#6b7280', marginTop:3 }}>
              Renews on {renewsOn}
            </div>
          )}
        </div>
      </div>
      <div style={{ display:'flex', alignItems:'center', gap:'.75rem' }}>
        {/* Status badge */}
        <span style={{
          padding:'.3rem .85rem', borderRadius:999, fontSize:12, fontWeight:700,
          background: status === 'active' ? '#dcfce7' : status === 'past_due' ? '#fef9c3' : '#f3f4f6',
          color: status === 'active' ? '#15803d' : status === 'past_due' ? '#a16207' : '#374151',
        }}>
          <span style={{ display:'inline-block', width:8, height:8, borderRadius:'50%', background:'currentColor', marginRight:6 }} />
          {status === 'active' ? 'Active' : status === 'past_due' ? 'Past Due' : status}
        </span>
        {status === 'active' && plan !== 'free' && (
          <button
            onClick={onCancel}
            style={{
              padding:'.35rem .9rem', borderRadius:8, fontSize:12.5, fontWeight:600,
              border:'1.5px solid #fca5a5', background:'#fff', color:'#dc2626', cursor:'pointer',
              transition:'background .13s',
            }}
            onMouseEnter={e => e.target.style.background='#fef2f2'}
            onMouseLeave={e => e.target.style.background='#fff'}
          >
            Cancel plan
          </button>
        )}
      </div>
    </div>
  )
}

// ── Billing History ───────────────────────────────────────────────────────
const HISTORY_STATUS = {
  active:    { label:'Active',    bg:'#dcfce7', color:'#15803d' },
  cancelled: { label:'Cancelled', bg:'#f3f4f6', color:'#4b5563' },
  halted:    { label:'Halted',    bg:'#fef9c3', color:'#a16207' },
  downgraded:{ label:'Downgraded',bg:'#f3f4f6', color:'#4b5563' },
  authenticated: { label:'Pending', bg:'#e0f2fe', color:'#0369a1' },
}

function BillingHistory({ rows }) {
  if (!rows || rows.length === 0) {
    return (
      <div style={{ background:'#fff', border:'1px solid #e5e7eb', borderRadius:18, padding:'1.4rem 1.6rem', marginBottom:'1.5rem' }}>
        <div style={{ fontSize:15, fontWeight:700, color:'#111827', marginBottom:'.4rem' }}>Billing History</div>
        <div style={{ fontSize:13, color:'#9ca3af' }}>No payments yet. Your invoices will appear here once you subscribe.</div>
      </div>
    )
  }

  return (
    <div style={{ background:'#fff', border:'1px solid #e5e7eb', borderRadius:18, padding:'1.4rem 1.6rem', marginBottom:'1.5rem' }}>
      <div style={{ fontSize:15, fontWeight:700, color:'#111827', marginBottom:'.3rem' }}>Billing History</div>
      {rows.some(r => r.estimated) && (
        <div style={{ fontSize:12.5, color:'#9ca3af', marginBottom:'1rem' }}>
          Amounts marked <em>est.</em> predate per-charge records and show the current list price.
        </div>
      )}
      <div style={{ overflowX:'auto' }}>
        <table style={{ width:'100%', borderCollapse:'collapse', fontSize:13.5 }}>
          <thead>
            <tr style={{ textAlign:'left', color:'#6b7280', fontSize:11.5, textTransform:'uppercase', letterSpacing:'.05em' }}>
              <th style={{ padding:'.5rem .6rem', fontWeight:700 }}>Date</th>
              <th style={{ padding:'.5rem .6rem', fontWeight:700 }}>Plan</th>
              <th style={{ padding:'.5rem .6rem', fontWeight:700 }}>Cycle</th>
              <th style={{ padding:'.5rem .6rem', fontWeight:700, textAlign:'right' }}>Amount</th>
              <th style={{ padding:'.5rem .6rem', fontWeight:700 }}>Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => {
              const st = HISTORY_STATUS[r.status] ?? { label: r.status, bg:'#f3f4f6', color:'#4b5563' }
              return (
                <tr key={i} style={{ borderTop:'1px solid #f3f4f6' }}>
                  <td style={{ padding:'.65rem .6rem', color:'#374151', whiteSpace:'nowrap' }}>{fmtDate(r.date) || '—'}</td>
                  <td style={{ padding:'.65rem .6rem', fontWeight:600, color:'#111827', textTransform:'capitalize' }}>{r.plan}</td>
                  <td style={{ padding:'.65rem .6rem', color:'#6b7280', textTransform:'capitalize' }}>{r.cycle}</td>
                  <td style={{ padding:'.65rem .6rem', color:'#111827', fontWeight:700, textAlign:'right', fontVariantNumeric:'tabular-nums', whiteSpace:'nowrap' }}>
                    {fmtINR(r.amount_inr)}{r.cycle === 'annual' ? '/yr' : '/mo'}
                    {r.estimated && <span style={{ marginLeft:5, fontSize:10.5, fontWeight:600, color:'#9ca3af', fontStyle:'italic' }}>est.</span>}
                  </td>
                  <td style={{ padding:'.65rem .6rem' }}>
                    <span style={{ padding:'.15rem .55rem', borderRadius:999, fontSize:11.5, fontWeight:700, background: st.bg, color: st.color }}>
                      {st.label}
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Billing Panel (SaaS) ──────────────────────────────────────────────────
function BillingPanel() {
  const [billingData, setBillingData]     = useState(null)
  const [loading, setLoading]             = useState(true)
  const [error, setError]                 = useState('')
  const [subscribing, setSubscribing]     = useState('')
  const [cancelling, setCancelling]       = useState(false)
  const [showCancel, setShowCancel]       = useState(false)
  const [actionError, setActionError]     = useState('')
  const [isAnnual, setIsAnnual]           = useState(false)
  // Offers: promotions apply automatically; a typed coupon is only used if the server's quote says it applied.
  const [couponInput, setCouponInput]     = useState('')
  const [appliedCode, setAppliedCode]     = useState('')
  const [offers, setOffers]               = useState(null)
  useEffect(() => {
    let alive = true
    billingApi.quote(isAnnual ? 'annual' : 'monthly', appliedCode).then(r => { if (alive) setOffers(r.data ?? null) }).catch(() => { if (alive) setOffers(null) })
    return () => { alive = false }
  }, [isAnnual, appliedCode])
  const [analyticsData, setAnalyticsData] = useState(null)
  const [analyticsLoading, setAnalyticsLoading] = useState(true)
  const [history, setHistory]             = useState([])

  const loadBilling = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const [status, hist] = await Promise.all([
        billingApi.billingStatus(),
        billingApi.billingHistory().catch(() => ({ data: [] })),
      ])
      setBillingData(status)
      setHistory(hist?.data ?? [])
    } catch (err) {
      setError(err.message ?? 'Failed to load billing.')
    } finally { setLoading(false) }
  }, [])

  useEffect(() => {
    loadBilling()
    analyticsApi.summary()
      .then(res => setAnalyticsData(res?.data ?? res ?? null))
      .catch(() => {})
      .finally(() => setAnalyticsLoading(false))
  }, [loadBilling])

  async function handleSubscribe(plan) {
    setSubscribing(plan); setActionError('')
    try {
      // 1. Load Razorpay SDK
      const loaded = await loadRazorpayScript()
      if (!loaded) throw new Error('Razorpay SDK failed to load. Check your internet connection.')

      // 2. Create order on backend
      const billingType = isAnnual ? 'annual' : 'monthly'
      const { data: order } = await billingApi.createOrder(plan, billingType, offers?.code?.applied ? appliedCode : '')
      if (order.notice) toast.info('Offer applied', order.notice)

      // Mock mode: just reload billing status without payment
      if (order.mock) {
        await billingApi.verifyPayment({
          razorpay_order_id:   order.order_id,
          razorpay_payment_id: 'pay_mock_' + Date.now(),
          razorpay_signature:  'mock_sig',
          plan,
          billing: billingType,
        })
        toast.success('Plan activated!', `Welcome to TripSarthi ${plan.charAt(0).toUpperCase() + plan.slice(1)}`)
        await loadBilling()
        setSubscribing('')
        return
      }

      // 3. Open Razorpay checkout modal
      const planLabel = plan.charAt(0).toUpperCase() + plan.slice(1)
      const rzp = new window.Razorpay({
        key:         order.key_id,
        order_id:    order.order_id,
        amount:      order.amount,
        currency:    order.currency,
        name:        'TripSarthi by Gamavis',
        description: order.description,
        image:       '/logo192.png',
        prefill: {},
        theme: { color: '#0a6cc4' },
        modal: {
          ondismiss: () => {
            setSubscribing('')
            toast.info('Payment cancelled', 'You can upgrade anytime from the Billing page.')
          },
        },
        handler: async (response) => {
          // 4. Verify payment on backend
          try {
            await billingApi.verifyPayment({
              razorpay_order_id:   response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature:  response.razorpay_signature,
              plan,
              billing: billingType,
            })
            toast.success('Plan activated!', `Welcome to TripSarthi ${planLabel}!`)
            await loadBilling()
          } catch (verifyErr) {
            toast.error('Payment verification failed', verifyErr.message ?? 'Please contact support.')
          } finally {
            setSubscribing('')
          }
        },
      })

      rzp.on('payment.failed', (response) => {
        const reason = response?.error?.description ?? 'Payment failed.'
        toast.error('Payment failed', reason)
        setSubscribing('')
      })

      rzp.open()
    } catch (err) {
      setActionError(err.message ?? 'Failed to start checkout.')
      setSubscribing('')
    }
  }

  async function handleCancel() {
    setCancelling(true)
    try {
      await billingApi.cancelBilling()
      setShowCancel(false)
      await loadBilling()
    } catch (err) {
      setActionError(err.message ?? 'Failed to cancel.')
    } finally { setCancelling(false) }
  }

  // /billing/status returns the subscription row under `data` (null when the
  // tenant has never subscribed). Read from there — not `.subscription`.
  // A `created` row is an abandoned/unpaid checkout: treat the tenant as free
  // until a verified payment flips the row to `active`.
  const sub         = billingData?.data ?? null
  const isLiveSub   = sub && sub.status !== 'created'
  const currentPlan = isLiveSub ? (sub.plan || 'free') : 'free'
  const subStatus   = isLiveSub ? (sub.status || 'unknown') : 'free'
  const renewsOn    = isLiveSub && sub.current_period_end ? fmtDate(sub.current_period_end) : null
  const limits      = PLAN_LIMITS[currentPlan] || PLAN_LIMITS.free

  const contacts    = analyticsLoading ? null : (analyticsData?.contacts_total ?? null)
  const activeFlows = analyticsLoading ? null : (analyticsData?.active_flows ?? null)
  const agents      = analyticsLoading ? null : (analyticsData?.agents_total ?? null)
  const waba        = analyticsLoading ? null : (analyticsData?.waba_total ?? null)

  if (loading) {
    return (
      <div className="page">
        <div style={{ height:28,width:180,borderRadius:8,background:'#e5e7eb',marginBottom:24 }}/>
        <div style={{ height:100,borderRadius:18,background:'#f3f4f6',marginBottom:20 }}/>
        <div style={{ height:180,borderRadius:18,background:'#f3f4f6',marginBottom:20 }}/>
        <div style={{ display:'flex',gap:'1rem' }}>
          {[1,2,3].map(i=><div key={i} style={{ flex:'1 1 200px',height:320,borderRadius:18,background:'#f3f4f6' }}/>)}
        </div>
      </div>
    )
  }

  return (
    <div className="page">
      {/* Page header */}
      <div style={{ display:'flex', alignItems:'flex-start', justifyContent:'space-between', marginBottom:'1.5rem', flexWrap:'wrap', gap:'.75rem' }}>
        <div>
          <h1 style={{ margin:0, fontSize:'1.5rem', fontWeight:800, color:'#111827', letterSpacing:'-.02em' }}>
            Billing & Plans
          </h1>
          <p style={{ margin:'4px 0 0', fontSize:13.5, color:'#6b7280' }}>
            Manage your subscription and monitor usage
          </p>
        </div>
      </div>

      {/* Error banner */}
      {(error || actionError) && (
        <div style={{ background:'#fef2f2', border:'1px solid #fca5a5', borderRadius:10, padding:'.75rem 1rem', marginBottom:'1rem', fontSize:13.5, color:'#dc2626', display:'flex', gap:'.5rem' }}>
          <AlertTriangle size={15} strokeWidth={2} style={{ flexShrink:0, marginTop:1 }} /> {error || actionError}
        </div>
      )}

      {/* Current plan hero */}
      <CurrentPlanHero
        plan={currentPlan}
        status={subStatus}
        renewsOn={renewsOn}
        onCancel={() => setShowCancel(true)}
      />

      {/* Usage section */}
      <div style={{
        background:'#fff', border:'1px solid #e5e7eb', borderRadius:18,
        padding:'1.4rem 1.6rem', marginBottom:'1.5rem',
      }}>
        <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:'1.2rem' }}>
          <div>
            <div style={{ fontSize:15, fontWeight:700, color:'#111827' }}>Usage Overview</div>
            <div style={{ fontSize:12.5, color:'#9ca3af', marginTop:2 }}>
              Limits based on your {currentPlan.charAt(0).toUpperCase()+currentPlan.slice(1)} plan
            </div>
          </div>
          {currentPlan !== 'pro' && (
            <a href="#pricing" style={{ fontSize:13, color:'#0a6cc4', fontWeight:600, textDecoration:'none' }}
              onClick={e => { e.preventDefault(); document.getElementById('lp-bill-pricing')?.scrollIntoView({behavior:'smooth'}) }}>
              Upgrade for more →
            </a>
          )}
        </div>
        <UsageBar icon={<Users size={15} strokeWidth={2} />} label="Contacts"    current={contacts}    limit={limits.contacts}    color="#0a6cc4" />
        <UsageBar icon={<Zap size={15} strokeWidth={2} />} label="Active Flows" current={activeFlows} limit={limits.active_flows} color="#f59e0b" />
        <UsageBar icon={<User size={15} strokeWidth={2} />} label="Agents"       current={agents}      limit={limits.agents}       color="#0891b2" />
        <UsageBar icon={<Smartphone size={15} strokeWidth={2} />} label="WABA Numbers" current={waba}        limit={limits.waba}         color="#059669" />
        <CrmUsageBars />
      </div>

      {/* Pricing section */}
      <div id="lp-bill-pricing">
        {/* Billing toggle */}
        <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:'1.4rem', flexWrap:'wrap', gap:'.75rem' }}>
          <div>
            <div style={{ fontSize:16, fontWeight:800, color:'#111827' }}>
              {currentPlan === 'free' ? 'Choose a Plan' : 'Switch Plan'}
            </div>
            <div style={{ fontSize:13, color:'#6b7280', marginTop:3 }}>
              All plans include WhatsApp Cloud API + flow automation
            </div>
          </div>
          {/* Monthly / Annual toggle */}
          <div style={{ display:'flex', alignItems:'center', gap:'.75rem', background:'#f9fafb', borderRadius:12, padding:'.5rem .9rem', border:'1px solid #e5e7eb' }}>
            <span style={{ fontSize:13, fontWeight:600, color: isAnnual ? '#9ca3af' : '#111827' }}>Monthly</span>
            <div
              className={`lp-bill-toggle-track ${isAnnual ? 'on' : ''}`}
              onClick={() => setIsAnnual(a => !a)}
            />
            <span style={{ fontSize:13, fontWeight:600, color: isAnnual ? '#111827' : '#9ca3af' }}>
              Annual
            </span>
            {isAnnual && (
              <span style={{
                background:'linear-gradient(135deg,#dcfce7,#bbf7d0)',
                color:'#15803d', fontSize:11, fontWeight:800,
                padding:'.15rem .55rem', borderRadius:999,
              }}>
                SAVE 20%
              </span>
            )}
          </div>
        </div>

        {/* Coupon code */}
        <form onSubmit={e => { e.preventDefault(); setAppliedCode(couponInput.trim()) }} style={{ display:'flex', alignItems:'center', gap:'.6rem', flexWrap:'wrap', marginBottom:'1.2rem' }}>
          <input
            value={couponInput} onChange={e => setCouponInput(e.target.value.toUpperCase())} placeholder="Have a coupon code?" maxLength={30} aria-label="Coupon code"
            style={{ padding:'.5rem .8rem', borderRadius:10, border:'1.5px solid #e5e7eb', fontSize:13.5, width:210, textTransform:'uppercase', letterSpacing:'.04em' }}
          />
          <button type="submit" disabled={!couponInput.trim()} style={{ padding:'.5rem 1rem', borderRadius:10, border:'1.5px solid #0a6cc4', background:'#fff', color:'#0a6cc4', fontWeight:700, fontSize:13, cursor:'pointer' }}>Apply</button>
          {offers?.code?.applied && (
            <span style={{ fontSize:13, color:'#16a34a', fontWeight:600 }}>
              ✓ Code {offers.code.code} applied
              <button type="button" onClick={() => { setAppliedCode(''); setCouponInput('') }} style={{ marginLeft:8, background:'none', border:'none', color:'#6b7280', cursor:'pointer', fontSize:12, textDecoration:'underline' }}>Remove</button>
            </span>
          )}
          {appliedCode && offers?.code && !offers.code.applied && (
            <span style={{ fontSize:13, color:'#dc2626' }}>{offers.code.message || 'This code cannot be used.'}</span>
          )}
        </form>

        {/* Plan cards */}
        <div style={{ display:'flex', gap:'1.25rem', flexWrap:'wrap', alignItems:'flex-start' }}>
          {PLANS.map((plan, i) => (
            <PlanCard
              key={plan.key}
              plan={plan}
              isAnnual={isAnnual}
              currentPlan={currentPlan}
              onSubscribe={handleSubscribe}
              subscribing={subscribing}
              offer={offers?.plans?.[plan.key]}
            />
          ))}
        </div>

        {/* Fine print */}
        <div style={{ marginTop:'1.25rem', marginBottom:'1.5rem', fontSize:12, color:'#9ca3af', textAlign:'center' }}>
          All plans billed in INR via Razorpay. Cancel anytime. WhatsApp message costs are billed by Meta directly to your WABA.
        </div>
      </div>

      {/* Billing history */}
      <BillingHistory rows={history} />

      {/* Cancel modal */}
      {showCancel && (
        <CancelModal
          onConfirm={handleCancel}
          onClose={() => setShowCancel(false)}
          cancelling={cancelling}
        />
      )}
    </div>
  )
}

// ── License Panel (Self-hosted) ───────────────────────────────────────────
function LicensePanel() {
  const [licenseData, setLicenseData]           = useState(null)
  const [loading, setLoading]                   = useState(true)
  const [error, setError]                       = useState('')
  const [licenseKey, setLicenseKey]             = useState('')
  const [activating, setActivating]             = useState(false)
  const [successMsg, setSuccessMsg]             = useState('')
  const [analyticsData, setAnalyticsData]       = useState(null)
  const [analyticsLoading, setAnalyticsLoading] = useState(true)

  useEffect(() => {
    billingApi.licenseStatus().then(setLicenseData).catch(e => setError(e.message ?? 'Failed.')).finally(() => setLoading(false))
    analyticsApi.summary()
      .then(res => setAnalyticsData(res?.data ?? res ?? null))
      .catch(() => {})
      .finally(() => setAnalyticsLoading(false))
  }, [])

  async function handleActivate(e) {
    e.preventDefault()
    if (!licenseKey.trim()) return
    setActivating(true); setError(''); setSuccessMsg('')
    try {
      await billingApi.activateLicense(licenseKey.trim())
      setSuccessMsg('License activated successfully!')
      setLicenseKey('')
      const d = await billingApi.licenseStatus()
      setLicenseData(d)
    } catch (err) {
      setError(err.message ?? 'Failed to activate license.')
    } finally { setActivating(false) }
  }

  const limits      = PLAN_LIMITS['pro']    // self-hosted is always pro-tier
  const contacts    = analyticsLoading ? null : (analyticsData?.contacts_total ?? null)
  const activeFlows = analyticsLoading ? null : (analyticsData?.active_flows ?? null)
  const agents      = analyticsLoading ? null : (analyticsData?.agents_total ?? null)
  const waba        = analyticsLoading ? null : (analyticsData?.waba_total ?? null)

  return (
    <div className="page">
      <div style={{ marginBottom:'1.5rem' }}>
        <h1 style={{ margin:0, fontSize:'1.5rem', fontWeight:800, color:'#111827', letterSpacing:'-.02em' }}>
          License
        </h1>
        <p style={{ margin:'4px 0 0', fontSize:13.5, color:'#6b7280' }}>
          Self-hosted installation — manage your license key
        </p>
      </div>

      {/* Status hero */}
      {!loading && licenseData && (
        <div style={{
          borderRadius:18,
          padding:'1.5rem 1.75rem',
          background: licenseData.is_active
            ? 'linear-gradient(135deg,#f0fdf4,#dcfce7)'
            : 'linear-gradient(135deg,#fef2f2,#ffe4e6)',
          border:`1.5px solid ${licenseData.is_active ? '#86efac' : '#fca5a5'}`,
          display:'flex', alignItems:'center', gap:'1.2rem',
          marginBottom:'1.5rem',
          animation:'lp-bill-in .2s ease',
        }}>
          <div style={{ display:'flex', alignItems:'center' }}>
            {licenseData.is_active
              ? <CheckCircle2 size={40} strokeWidth={1.4} color="#16a34a" />
              : <XCircle size={40} strokeWidth={1.4} color="#dc2626" />}
          </div>
          <div>
            <div style={{ fontSize:18, fontWeight:800, color: licenseData.is_active ? '#15803d' : '#dc2626' }}>
              {licenseData.is_active ? 'License Active' : 'License Inactive'}
            </div>
            <div style={{ fontSize:13, color: licenseData.is_active ? '#166534' : '#991b1b', marginTop:3 }}>
              {licenseData.reason || (licenseData.is_active ? 'Your installation is licensed and fully operational.' : 'Activate a valid license key below.')}
            </div>
            {!licenseData.is_active && licenseData.grace_days_left > 0 && (
              <div style={{
                display:'inline-flex', alignItems:'center', gap:'.4rem',
                background:'#fffbeb', border:'1px solid #fde68a',
                borderRadius:8, padding:'.3rem .7rem', marginTop:8,
                fontSize:13, fontWeight:600, color:'#92400e',
              }}>
                <Clock size={13} strokeWidth={2} /> Grace period: {licenseData.grace_days_left} day{licenseData.grace_days_left !== 1 ? 's' : ''} remaining
              </div>
            )}
          </div>
        </div>
      )}

      {/* Usage */}
      <div style={{ background:'#fff', border:'1px solid #e5e7eb', borderRadius:18, padding:'1.4rem 1.6rem', marginBottom:'1.5rem' }}>
        <div style={{ fontSize:15, fontWeight:700, color:'#111827', marginBottom:'1.2rem' }}>Usage Overview</div>
        <UsageBar icon={<Users size={15} strokeWidth={2} />} label="Contacts"    current={contacts}    limit={limits.contacts}    color="#0a6cc4" />
        <UsageBar icon={<Zap size={15} strokeWidth={2} />} label="Active Flows" current={activeFlows} limit={limits.active_flows} color="#f59e0b" />
        <UsageBar icon={<User size={15} strokeWidth={2} />} label="Agents"       current={agents}      limit={limits.agents}       color="#0891b2" />
        <UsageBar icon={<Smartphone size={15} strokeWidth={2} />} label="WABA Numbers" current={waba}        limit={limits.waba}         color="#059669" />
        <CrmUsageBars />
      </div>

      {/* Activate form */}
      <div style={{ background:'#fff', border:'1px solid #e5e7eb', borderRadius:18, padding:'1.6rem', animation:'lp-bill-in .25s ease' }}>
        <div style={{ fontSize:16, fontWeight:800, color:'#111827', marginBottom:6 }}>Activate License Key</div>
        <div style={{ fontSize:13.5, color:'#6b7280', marginBottom:'1.25rem' }}>
          Enter your license key from Gamavis Software Solutions
        </div>

        {successMsg && (
          <div style={{ background:'#f0fdf4', border:'1px solid #86efac', borderRadius:10, padding:'.65rem 1rem', marginBottom:'1rem', fontSize:13.5, color:'#15803d', fontWeight:600 }}>
            {successMsg}
          </div>
        )}
        {error && (
          <div style={{ background:'#fef2f2', border:'1px solid #fca5a5', borderRadius:10, padding:'.65rem 1rem', marginBottom:'1rem', fontSize:13.5, color:'#dc2626' }}>
            {error}
          </div>
        )}

        <form onSubmit={handleActivate} style={{ display:'flex', gap:'.75rem', flexWrap:'wrap' }}>
          <input
            type="text"
            value={licenseKey}
            onChange={e => setLicenseKey(e.target.value)}
            placeholder="XXXX-XXXX-XXXX-XXXX"
            disabled={activating}
            style={{
              flex:'1 1 260px', padding:'.7rem 1rem', borderRadius:10,
              border:'1.5px solid #e5e7eb', background:'#f9fafb',
              fontSize:14, color:'#111827', fontFamily:'monospace', letterSpacing:'.08em',
              outline:'none', transition:'border-color .15s',
            }}
            onFocus={e => e.target.style.borderColor='#0a6cc4'}
            onBlur={e => e.target.style.borderColor='#e5e7eb'}
          />
          <button
            type="submit"
            disabled={activating || !licenseKey.trim()}
            style={{
              padding:'.7rem 1.4rem', borderRadius:10, border:'none',
              background: activating ? '#8ec5f0' : 'linear-gradient(135deg,#0a6cc4,#12a89e)',
              color:'#fff', fontWeight:700, fontSize:14, cursor: activating ? 'not-allowed' : 'pointer',
              display:'flex', alignItems:'center', gap:'.45rem',
              boxShadow:'0 2px 8px rgba(10,108,196,.35)', whiteSpace:'nowrap',
            }}
          >
            {activating ? (
              <><span style={{ width:14,height:14,border:'2px solid rgba(255,255,255,.4)',borderTopColor:'#fff',borderRadius:'50%',animation:'lp-bill-spin .7s linear infinite' }}/> Activating…</>
            ) : <><KeyRound size={15} strokeWidth={2} /> Activate License</>}
          </button>
        </form>

        <div style={{ marginTop:'1rem', fontSize:12.5, color:'#9ca3af' }}>
          Need a license key? Contact <a href="mailto:support@gamavis.com" style={{ color:'#0a6cc4' }}>support@gamavis.com</a>
        </div>
      </div>
    </div>
  )
}

// ── Root ──────────────────────────────────────────────────────────────────
export default function BillingPage() {
  const [mode, setMode]         = useState(null)
  const [checking, setChecking] = useState(true)

  useEffect(() => {
    billingApi.billingStatus()
      .then(() => setMode('saas'))
      .catch(err => {
        const is403 = err.status === 403 || err.statusCode === 403 || String(err.message).includes('403')
        setMode(is403 ? 'self_hosted' : 'saas')
      })
      .finally(() => setChecking(false))
  }, [])

  if (checking) {
    return (
      <div className="page">
        <div style={{ height:28,width:180,borderRadius:8,background:'#e5e7eb',marginBottom:24 }}/>
        <div style={{ height:120,borderRadius:18,background:'#f3f4f6',marginBottom:20 }}/>
        <div style={{ height:180,borderRadius:18,background:'#f3f4f6' }}/>
      </div>
    )
  }

  return mode === 'self_hosted' ? <LicensePanel /> : <BillingPanel />
}
