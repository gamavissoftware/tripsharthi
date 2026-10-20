import { useState } from 'react'
import { Link } from 'react-router-dom'
import { GUIDES } from '../lib/guides'
import { GuideBody } from '../components/GuideButton'

/** /help - every guide in one place, searchable. */
export default function HelpPage() {
  const [q, setQ] = useState('')
  const [open, setOpen] = useState('itinerary')
  const t = q.trim().toLowerCase()
  const list = GUIDES.filter(g => !t || JSON.stringify(g).toLowerCase().includes(t))
  return (
    <div className="page page-form">
      <div className="page-header"><h1 className="page-title">User guides</h1><Link to="/support" className="btn btn-ghost">Ask support</Link></div>
      <input className="form-input" style={{ marginBottom: 14 }} placeholder="Search the guides, for example: rate, GST, WhatsApp" value={q} onChange={e => setQ(e.target.value)} />
      {list.length === 0 && <div className="card card-body">Nothing matches. Try another word, or ask support.</div>}
      {list.map(g => (
        <div key={g.id} className="card" style={{ marginBottom: 10 }}>
          <button className="gd-row" onClick={() => setOpen(open === g.id ? '' : g.id)} aria-expanded={open === g.id}>
            <span>{g.icon} <b>{g.title}</b></span><span>{open === g.id ? '−' : '+'}</span>
          </button>
          {(open === g.id || t) && <div style={{ padding: '0 18px 16px' }}><GuideBody g={g} /></div>}
        </div>
      ))}
    </div>
  )
}
