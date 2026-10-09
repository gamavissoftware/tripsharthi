import { useState, useEffect, useCallback } from 'react'
import { travel, inr, toPaise } from '../api/travel'
import { toast } from '../components/Toast'
import RateImportPanel from '../components/RateImportPanel'

const STYPES = ['hotel', 'transport', 'activity', 'dmc', 'airline', 'visa', 'insurance', 'guide', 'cruise', 'other']
const RTYPES = ['hotel', 'transfer', 'sightseeing', 'activity', 'flight', 'visa', 'insurance', 'meal', 'other']
const UNITS = ['per_night', 'per_pax', 'per_vehicle', 'per_day', 'per_room', 'flat']

export default function SuppliersPage() {
  const [tab, setTab] = useState('suppliers')
  const [sup, setSup] = useState([]); const [rates, setRates] = useState([]); const [dest, setDest] = useState([])
  const [s, setS] = useState({ name: '', type: 'hotel', city: '', phone: '', gstin: '' })
  const [r, setR] = useState({ supplier_id: '', service_name: '', service_type: 'hotel', unit: 'per_night', cost_rs: '' })
  const [d, setD] = useState({ name: '', country: 'India', is_domestic: 1 })
  const load = useCallback(() => Promise.all([travel.list('suppliers'), travel.list('rates'), travel.list('destinations')]).then(([a, b, c]) => { setSup(a); setRates(b); setDest(c) }).catch(e => toast.error('Load failed', e.message)), [])
  useEffect(() => { load() }, [load])
  const names = Object.fromEntries(sup.map(x => [x.id, x.name]))
  const guard = (fn, msg) => async (e) => { e.preventDefault(); try { await fn(); toast.success(msg); load() } catch (er) { toast.error('Failed', er.message) } }
  const tabBtn = (k, label) => <button key={k} className={'btn btn-sm ' + (tab === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setTab(k)}>{label}</button>
  const th = { padding: '10px 14px', textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }; const td = { padding: '10px 14px', borderTop: '1px solid var(--border)' }
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Suppliers &amp; Rates</h1></div>
      <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>{tabBtn('suppliers', `Suppliers (${sup.length})`)}{tabBtn('rates', `Rate cards (${rates.length})`)}{tabBtn('import', 'Import rates (AI)')}{tabBtn('destinations', `Destinations (${dest.length})`)}</div>

      {tab === 'suppliers' && <>
        <form className="card card-body" onSubmit={guard(() => travel.create('suppliers', s).then(() => setS({ ...s, name: '' })), 'Supplier added')} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
          <input className="form-input" style={{ flex: '2 1 180px' }} placeholder="Supplier name *" value={s.name} onChange={e => setS({ ...s, name: e.target.value })} required />
          <select className="form-select" style={{ flex: '1 1 110px' }} value={s.type} onChange={e => setS({ ...s, type: e.target.value })}>{STYPES.map(t => <option key={t}>{t}</option>)}</select>
          <input className="form-input" style={{ flex: '1 1 110px' }} placeholder="City" value={s.city} onChange={e => setS({ ...s, city: e.target.value })} />
          <input className="form-input" style={{ flex: '1 1 130px' }} placeholder="Phone / WhatsApp" value={s.phone} onChange={e => setS({ ...s, phone: e.target.value })} />
          <input className="form-input" style={{ flex: '1 1 150px' }} placeholder="GSTIN" value={s.gstin} onChange={e => setS({ ...s, gstin: e.target.value })} />
          <button className="btn btn-primary">Add</button></form>
        <div className="card" style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}><thead><tr>{['Name', 'Type', 'City', 'Phone', 'GSTIN'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>{sup.map(x => <tr key={x.id}><td style={td}><b>{x.name}</b></td><td style={td}>{x.type}</td><td style={td}>{x.city}</td><td style={td}>{x.phone}</td><td style={td}>{x.gstin}</td></tr>)}</tbody></table></div></>}

      {tab === 'rates' && <>
        <form className="card card-body" onSubmit={guard(() => travel.create('rates', { ...r, supplier_id: Number(r.supplier_id), cost_amount: toPaise(r.cost_rs) }).then(() => setR({ ...r, service_name: '', cost_rs: '' })), 'Rate added')} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
          <select className="form-select" style={{ flex: '1 1 160px' }} value={r.supplier_id} onChange={e => setR({ ...r, supplier_id: e.target.value })} required><option value="">Supplier…</option>{sup.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
          <input className="form-input" style={{ flex: '2 1 180px' }} placeholder="Service (e.g. Deluxe pool villa)" value={r.service_name} onChange={e => setR({ ...r, service_name: e.target.value })} required />
          <select className="form-select" style={{ flex: '1 1 110px' }} value={r.service_type} onChange={e => setR({ ...r, service_type: e.target.value })}>{RTYPES.map(t => <option key={t}>{t}</option>)}</select>
          <select className="form-select" style={{ flex: '1 1 110px' }} value={r.unit} onChange={e => setR({ ...r, unit: e.target.value })}>{UNITS.map(t => <option key={t}>{t}</option>)}</select>
          <input className="form-input" style={{ flex: '1 1 110px' }} type="number" placeholder="Cost ₹" value={r.cost_rs} onChange={e => setR({ ...r, cost_rs: e.target.value })} required />
          <button className="btn btn-primary">Add</button></form>
        <div className="card" style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}><thead><tr>{['Supplier', 'Service', 'Type', 'Unit', 'Cost'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>{rates.map(x => <tr key={x.id}><td style={td}>{names[x.supplier_id]}</td><td style={td}><b>{x.service_name}</b></td><td style={td}>{x.service_type}</td><td style={td}>{x.unit.replace('_', ' ')}</td><td style={td}>{inr(x.cost_amount)}</td></tr>)}</tbody></table></div></>}

      {tab === 'import' && <RateImportPanel suppliers={sup} destinations={dest} onDone={() => { load(); setTab('rates') }} />}

      {tab === 'destinations' && <>
        <form className="card card-body" onSubmit={guard(() => travel.create('destinations', d).then(() => setD({ ...d, name: '' })), 'Destination added')} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
          <input className="form-input" style={{ flex: '2 1 180px' }} placeholder="Destination *" value={d.name} onChange={e => setD({ ...d, name: e.target.value })} required />
          <input className="form-input" style={{ flex: '1 1 130px' }} placeholder="Country" value={d.country} onChange={e => setD({ ...d, country: e.target.value })} />
          <select className="form-select" style={{ flex: '1 1 130px' }} value={d.is_domestic} onChange={e => setD({ ...d, is_domestic: Number(e.target.value) })}><option value={1}>Domestic</option><option value={0}>International</option></select>
          <button className="btn btn-primary">Add</button></form>
        <div className="card" style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}><thead><tr>{['Destination', 'Country', 'Type'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>{dest.map(x => <tr key={x.id}><td style={td}><b>{x.name}</b></td><td style={td}>{x.country}</td><td style={td}>{Number(x.is_domestic) ? 'Domestic' : 'International'}</td></tr>)}</tbody></table></div></>}
    </div>
  )
}
