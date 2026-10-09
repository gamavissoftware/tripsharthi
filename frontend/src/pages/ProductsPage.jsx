import { useState, useEffect } from 'react'
import { Plus, Hourglass } from 'lucide-react'
import { products as api } from '../api/products'
import { useToast } from '../components/Toast'

const blank = () => ({ retailer_id: '', name: '', description: '', price: '', image_url: '', availability: 'in_stock' })

export default function ProductsPage() {
  const { toast } = useToast()
  const [rows, setRows] = useState([])
  const [catalogId, setCatalogId] = useState('')
  const [catalogInput, setCatalogInput] = useState('')
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(null)

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const [p, c] = await Promise.all([api.list(), api.getCatalog().catch(() => ({ data: {} }))])
      setRows(p.data ?? [])
      setCatalogId(c.data?.catalog_id ?? '')
      setCatalogInput(c.data?.catalog_id ?? '')
    } catch (err) {
      toast.error('Failed to load products', err?.message)
    }
    setLoading(false)
  }

  async function saveCatalog() {
    if (!catalogInput.trim()) { toast.error('Enter your Meta catalog ID'); return }
    try { await api.saveCatalog({ catalog_id: catalogInput.trim() }); setCatalogId(catalogInput.trim()); toast.success('Catalog connected') }
    catch (err) { toast.error('Failed to save catalog', err?.message) }
  }

  async function save() {
    const p = editing
    if (!p.retailer_id.trim() || !p.name.trim()) { toast.error('SKU and name are required'); return }
    const payload = { ...p, price: p.price === '' ? undefined : Number(p.price) }
    try {
      if (p.id) await api.update(p.id, payload)
      else      await api.create(payload)
      setEditing(null); toast.success('Product saved'); load()
    } catch (err) { toast.error('Failed to save', err?.message) }
  }

  async function remove(id) {
    try { await api.remove(id); setRows(rs => rs.filter(r => r.id !== id)); toast.success('Product removed') }
    catch (err) { toast.error('Failed to remove', err?.message) }
  }

  return (
    <div className="page" style={{ maxWidth: 980 }}>
      <div className="page-header">
        <div>
          <h1 className="page-title">Products</h1>
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 4 }}>
            Mirror your Meta Commerce catalog so you can send product cards on WhatsApp.
          </p>
        </div>
        <button className="btn btn-primary" onClick={() => setEditing(blank())}><Plus size={15} strokeWidth={2} /> New product</button>
      </div>

      {/* Catalog config */}
      <div className="card" style={{ padding: '1rem 1.25rem', marginBottom: '1.25rem', display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <span style={{ fontWeight: 600, fontSize: 14 }}>Meta catalog ID</span>
        <input className="form-input" style={{ flex: '1 1 240px', maxWidth: 360 }} value={catalogInput}
          onChange={e => setCatalogInput(e.target.value)} placeholder="e.g. 1234567890" />
        <button className="btn btn-ghost" onClick={saveCatalog}>Save</button>
        {catalogId
          ? <span style={{ fontSize: 12, color: '#15803d' }}>✓ connected</span>
          : <span style={{ fontSize: 12, color: '#b45309' }}>not connected — product sends will be blocked</span>}
      </div>

      <div className="card" style={{ overflow: 'hidden' }}>
        {loading ? (
          <div className="empty-state"><span className="empty-state-icon"><Hourglass size={40} strokeWidth={1.4} /></span><span className="empty-state-text">Loading…</span></div>
        ) : rows.length === 0 ? (
          <div className="empty-state" style={{ padding: '40px 24px', textAlign: 'center', color: 'var(--text-3)' }}>
            No products yet. Add products with the same SKU (retailer_id) as your Meta catalog.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}><table className="data-table">
            <thead><tr><th>SKU</th><th>Name</th><th>Price</th><th>Availability</th><th></th></tr></thead>
            <tbody>
              {rows.map(p => (
                <tr key={p.id}>
                  <td className="cell-mono">{p.retailer_id}</td>
                  <td style={{ fontWeight: 600 }}>{p.name}</td>
                  <td>{p.price_paise > 0 ? `₹${(p.price_paise / 100).toLocaleString('en-IN')}` : '—'}</td>
                  <td>{p.availability === 'in_stock' ? 'In stock' : 'Out of stock'}</td>
                  <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <button className="btn btn-ghost btn-sm" onClick={() => setEditing({ ...p, price: p.price_paise ? p.price_paise / 100 : '' })}>Edit</button>
                    <button className="btn btn-ghost btn-sm" style={{ color: '#dc2626' }} onClick={() => remove(p.id)}>Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table></div>
        )}
      </div>

      {editing && (
        <div onClick={e => { if (e.target === e.currentTarget) setEditing(null) }}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
          <div style={{ background: '#fff', borderRadius: 14, maxWidth: 440, width: '100%', padding: '1.5rem' }}>
            <h2 style={{ margin: '0 0 1rem', fontSize: 18, fontWeight: 800 }}>{editing.id ? 'Edit' : 'New'} product</h2>
            <label style={lbl}>SKU (retailer_id) — must match Meta catalog
              <input className="form-input" value={editing.retailer_id} onChange={e => setEditing(s => ({ ...s, retailer_id: e.target.value }))} disabled={!!editing.id} />
            </label>
            <label style={lbl}>Name
              <input className="form-input" value={editing.name} onChange={e => setEditing(s => ({ ...s, name: e.target.value }))} />
            </label>
            <label style={lbl}>Price (₹)
              <input className="form-input" type="number" min="0" step="0.01" value={editing.price} onChange={e => setEditing(s => ({ ...s, price: e.target.value }))} />
            </label>
            <label style={lbl}>Image URL
              <input className="form-input" value={editing.image_url ?? ''} onChange={e => setEditing(s => ({ ...s, image_url: e.target.value }))} />
            </label>
            <label style={lbl}>Availability
              <select className="form-select" value={editing.availability} onChange={e => setEditing(s => ({ ...s, availability: e.target.value }))}>
                <option value="in_stock">In stock</option>
                <option value="out_of_stock">Out of stock</option>
              </select>
            </label>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: '1.5rem' }}>
              <button className="btn btn-ghost" onClick={() => setEditing(null)}>Cancel</button>
              <button className="btn btn-primary" onClick={save}>Save</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const lbl = { display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', margin: '10px 0 6px' }
