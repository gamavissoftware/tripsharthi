/**
 * Lightweight toast/growl notification system.
 * Uses a module-level event emitter — no React context needed.
 * Any component just imports { toast } and calls toast.success(...).
 */
import { useState, useEffect, useRef, useCallback } from 'react'
import { CheckCircle2, XCircle, AlertTriangle, Info, X } from 'lucide-react'

// ── Module-level event bus ────────────────────────────────────────────────
const listeners = new Set()

let _idCounter = 0
function nextId() { return ++_idCounter }

// Inject CSS keyframes once
if (typeof document !== 'undefined' && !document.getElementById('lp-toast-css')) {
  const el = document.createElement('style')
  el.id = 'lp-toast-css'
  el.textContent = `
    @keyframes lp-in  { from{transform:translateX(110%);opacity:0} to{transform:translateX(0);opacity:1} }
    @keyframes lp-out { from{transform:translateX(0);opacity:1}     to{transform:translateX(110%);opacity:0} }
    @keyframes lp-bar { from{width:100%} to{width:0} }
  `
  document.head.appendChild(el)
}

// Public toast API — import this anywhere, no hooks needed
export const toast = {
  success: (title, description) => dispatch('success', title, description),
  error:   (title, description) => dispatch('error',   title, description),
  warning: (title, description) => dispatch('warning', title, description),
  info:    (title, description) => dispatch('info',    title, description),
}

function dispatch(type, title, description) {
  listeners.forEach(fn => fn({ id: nextId(), type, title, description }))
}

// ── Config ────────────────────────────────────────────────────────────────
const DURATION = 4000
const COLORS = {
  success: '#16a34a',
  error:   '#dc2626',
  warning: '#d97706',
  info:    '#2563eb',
}
const ICONS = {
  success: CheckCircle2,
  error:   XCircle,
  warning: AlertTriangle,
  info:    Info,
}

// ── ToastItem ─────────────────────────────────────────────────────────────
function ToastItem({ item, onDismiss }) {
  const [leaving, setLeaving] = useState(false)
  const timerRef = useRef(null)

  const dismiss = useCallback(() => {
    setLeaving(true)
    setTimeout(() => onDismiss(item.id), 300)
  }, [item.id, onDismiss])

  useEffect(() => {
    timerRef.current = setTimeout(dismiss, DURATION)
    return () => clearTimeout(timerRef.current)
  }, [dismiss])

  const color = COLORS[item.type] || COLORS.info
  const Icon  = ICONS[item.type]  || ICONS.info

  return (
    <div
      onMouseEnter={() => clearTimeout(timerRef.current)}
      onMouseLeave={() => { timerRef.current = setTimeout(dismiss, 1500) }}
      style={{
        display: 'flex',
        flexDirection: 'column',
        background: '#fff',
        borderLeft: `4px solid ${color}`,
        borderRadius: 10,
        boxShadow: '0 8px 24px rgba(0,0,0,.14), 0 2px 6px rgba(0,0,0,.08)',
        minWidth: 300,
        maxWidth: 380,
        overflow: 'hidden',
        animation: leaving ? 'lp-out .3s ease forwards' : 'lp-in .3s ease forwards',
        opacity: 1,
      }}
    >
      {/* Main row */}
      <div style={{ display:'flex', alignItems:'flex-start', padding:'12px 12px 10px', gap:10 }}>
        <span style={{ lineHeight:1, flexShrink:0, marginTop:2, color }}><Icon size={16} strokeWidth={2} /></span>
        <div style={{ flex:1, minWidth:0 }}>
          <div style={{ fontWeight:700, fontSize:14, color:'#111827', lineHeight:1.3 }}>
            {item.title}
          </div>
          {item.description && (
            <div style={{ marginTop:3, fontSize:12.5, color:'#6b7280', lineHeight:1.4 }}>
              {item.description}
            </div>
          )}
        </div>
        <button
          onClick={dismiss}
          style={{ background:'none', border:'none', cursor:'pointer', padding:'0 0 0 4px',
                   color:'#9ca3af', lineHeight:1, flexShrink:0,
                   display:'inline-flex', alignItems:'center' }}
          aria-label="Dismiss"
        ><X size={14} /></button>
      </div>
      {/* Progress bar */}
      <div style={{ height:3, background:'#f3f4f6' }}>
        <div style={{
          height:'100%', background:color,
          animation:`lp-bar ${DURATION}ms linear forwards`,
        }} />
      </div>
    </div>
  )
}

// ── ToastProvider (mount once, ideally at app root) ────────────────────────
export function ToastProvider({ children }) {
  const [items, setItems] = useState([])

  useEffect(() => {
    function handleToast(item) {
      setItems(prev => [item, ...prev].slice(0, 5))
    }
    listeners.add(handleToast)
    return () => listeners.delete(handleToast)
  }, [])

  const remove = useCallback((id) => {
    setItems(prev => prev.filter(t => t.id !== id))
  }, [])

  return (
    <>
      {children}
      <div style={{
        position: 'fixed',
        top: 20,
        right: 20,
        zIndex: 99999,
        display: 'flex',
        flexDirection: 'column',
        gap: 10,
        pointerEvents: 'none',
        maxWidth: 400,
      }}>
        {items.map(item => (
          <div key={item.id} style={{ pointerEvents: 'auto' }}>
            <ToastItem item={item} onDismiss={remove} />
          </div>
        ))}
      </div>
    </>
  )
}

// useToast hook (for components that prefer hook style)
export function useToast() {
  return { toast }
}

export default ToastProvider
