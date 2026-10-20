import { useState, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { useLocation, Link } from 'react-router-dom'
import { BookOpen, X } from 'lucide-react'
import { guideFor } from '../lib/guides'

/** Body of one guide. Shared by the drawer and the Help page. */
export function GuideBody({ g }) {
  return (
    <div className="gd-body">
      <p className="gd-intro">{g.intro}</p>
      <h4>How to do it</h4>
      <ol>{g.steps.map((s, i) => <li key={i}>{s}</li>)}</ol>
      {g.tips?.length > 0 && <><h4>Good to know</h4><ul>{g.tips.map((s, i) => <li key={i}>{s}</li>)}</ul></>}
      {g.mistakes?.length > 0 && <><h4 className="gd-warn">Common mistakes</h4><ul>{g.mistakes.map((s, i) => <li key={i}>{s}</li>)}</ul></>}
    </div>
  )
}

/** "Guide" button in the top bar: opens the how-to for whatever page you are on. */
export default function GuideButton() {
  const { pathname } = useLocation()
  const [open, setOpen] = useState(false)
  const g = guideFor(pathname)
  useEffect(() => {
    if (!open) return undefined
    const k = (e) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('keydown', k)
    return () => document.removeEventListener('keydown', k)
  }, [open])
  return (
    <>
      <button className="gd-btn" onClick={() => setOpen(true)} title="How to use this page"><BookOpen size={16} /><span>Guide</span></button>
      {open && createPortal(
        <div className="gd-overlay" role="presentation" onClick={() => setOpen(false)}>
          <aside className="gd-drawer" role="dialog" aria-modal="true" aria-label="User guide" onClick={(e) => e.stopPropagation()}>
            <header><div><small>User guide</small><h3>{g ? `${g.icon} ${g.title}` : 'Help'}</h3></div><button className="gd-x" aria-label="Close" onClick={() => setOpen(false)}><X size={18} /></button></header>
            {g ? <GuideBody g={g} /> : <div className="gd-body"><p className="gd-intro">There is no step-by-step guide for this page yet. Browse all guides or ask us.</p></div>}
            <footer><Link to="/help" onClick={() => setOpen(false)}>All guides</Link><Link to="/support" onClick={() => setOpen(false)}>Ask support</Link></footer>
          </aside>
        </div>, document.body)}
    </>
  )
}
