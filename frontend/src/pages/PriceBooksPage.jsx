import { useState, useEffect, useCallback } from 'react'
import { crm } from '../api/crm'
import { products as productsApi } from '../api/products'
import { toast } from '../components/Toast'
import { fmt } from '../components/Charts'

// ── Price books (Phase J3) ───────────────────────────────────────────────────
// Per-tenant named price lists; each overrides product prices for deals that
// select the book.

export default function PriceBooksPage() {
  const [books, setBooks] = useState([])
  const [catalog, setCatalog] = useState([])
  const [activeId, setActiveId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [draft, setDraft] = useState({}) // productId -> price (rupees) being edited
  const [prefs, setPrefs] = useState(null)
  useEffect(() => { crm.preferences().then(r => setPrefs(r.data)).catch(() => {}) }, [])
  async function setDefaultCurrency(code) {
    try { const r = await crm.updatePreferences({ default_currency: code }); setPrefs(p => ({ ...p, default_currency: r.data.default_currency })); toast.success('Default currency updated') }
    catch (e) { toast.error('Could not update', e?.message) }
  }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const r = await crm.priceBooks()
      setBooks(r.data ?? [])
      setActiveId(id => id ?? (r.data?.[0]?.id ?? null))
    } catch (e) { toast.error('Failed to load price books', e?.message) }
    setLoading(false)
  }, [])
  useEffect(() => { load(); productsApi.list().then(r => setCatalog(r.data ?? [])).catch(() => {}) }, [load])

  const active = books.find(b => b.id === activeId)
  const entryFor = (pid) => active?.entries?.find(e => Number(e.product_id) === Number(pid))

  async function addBook() {
    const name = window.prompt('Price book name?')
    if (!name?.trim()) return
    const currency = window.prompt('Currency code (e.g. INR, USD)?', 'INR') || 'INR'
    try { const r = await crm.createPriceBook({ name: name.trim(), currency: currency.trim().toUpperCase() }); await load(); setActiveId(r.data.id) }
    catch (e) { toast.error('Could not create', e?.message) }
  }
  async function removeBook(b) {
    if (!confirm(`Delete price book "${b.name}"?`)) return
    try { await crm.deletePriceBook(b.id); setActiveId(null); load() } catch (e) { toast.error('Delete failed', e?.message) }
  }
  async function saveEntry(pid) {
    const rupees = draft[pid]
    if (rupees === undefined || rupees === '') return
    try {
      await crm.setPriceBookEntry(active.id, { product_id: pid, price: Math.round(parseFloat(rupees) * 100) })
      setDraft(d => { const n = { ...d }; delete n[pid]; return n })
      load()
    } catch (e) { toast.error('Could not save price', e?.message) }
  }
  async function clearEntry(entryId) {
    try { await crm.deletePriceBookEntry(entryId); load() } catch (e) { toast.error('Could not clear', e?.message) }
  }

  return (
    <div className="page" style={{ maxWidth: 760 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>💲 Price Books</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Named price lists; a deal can pick one to override product prices.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          {prefs && (
            <label style={{ fontSize: 12.5, color: '#6b7280', display: 'flex', alignItems: 'center', gap: 6 }}>
              Default currency
              <select value={prefs.default_currency} onChange={e => setDefaultCurrency(e.target.value)} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.4rem .5rem', fontSize: 13 }}>
                {(prefs.currencies ?? []).map(c => <option key={c} value={c}>{c}</option>)}
              </select>
            </label>
          )}
          <button onClick={addBook} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer' }}>+ New book</button>
        </div>
      </div>

      {loading ? <div style={{ padding: '2rem', color: '#9ca3af' }}>Loading…</div> : books.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 14, border: '1px solid #e5e7eb', color: '#6b7280' }}>No price books yet — deals use base product prices.</div>
      ) : (
        <>
          <div style={{ display: 'flex', gap: 6, marginBottom: '1rem', flexWrap: 'wrap', alignItems: 'center' }}>
            {books.map(b => (
              <button key={b.id} onClick={() => setActiveId(b.id)}
                style={{ padding: '.4rem .9rem', borderRadius: 999, border: '1.5px solid ' + (active?.id === b.id ? 'var(--primary,#0a6cc4)' : '#e5e7eb'), background: active?.id === b.id ? 'var(--primary,#0a6cc4)' : '#fff', color: active?.id === b.id ? '#fff' : '#475569', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>{b.name} · {b.currency}</button>
            ))}
          </div>

          {active && (
            <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, overflow: 'hidden' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '.7rem 1rem', borderBottom: '1px solid #f0f1f3' }}>
                <span style={{ fontWeight: 800, fontSize: 13 }}>{active.name} prices</span>
                <button onClick={() => removeBook(active)} style={{ background: '#fff', border: '1px solid #fecaca', color: '#b91c1c', borderRadius: 8, padding: '.3rem .7rem', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>Delete book</button>
              </div>
              {catalog.length === 0 ? (
                <div style={{ padding: '2rem', textAlign: 'center', color: '#9ca3af' }}>No products in the catalog yet.</div>
              ) : (
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                  <thead><tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em' }}>
                    <th style={{ padding: '.6rem 1rem' }}>Product</th><th style={{ padding: '.6rem 1rem' }}>Base</th><th style={{ padding: '.6rem 1rem' }}>Book price</th><th></th>
                  </tr></thead>
                  <tbody>
                    {catalog.map(p => {
                      const e = entryFor(p.id)
                      return (
                        <tr key={p.id} style={{ borderTop: '1px solid #f6f7f9' }}>
                          <td style={{ padding: '.55rem 1rem', fontWeight: 600 }}>{p.name}</td>
                          <td style={{ padding: '.55rem 1rem', color: '#94a3b8' }}>{fmt.inr(p.price_paise)}</td>
                          <td style={{ padding: '.55rem 1rem' }}>
                            <input type="number" placeholder={e ? '' : 'base'} value={draft[p.id] ?? (e ? (Number(e.price_paise) / 100) : '')}
                              onChange={ev => setDraft(d => ({ ...d, [p.id]: ev.target.value }))}
                              onBlur={() => saveEntry(p.id)} onKeyDown={ev => ev.key === 'Enter' && saveEntry(p.id)}
                              style={{ width: 110, border: '1px solid #e5e7eb', borderRadius: 7, padding: '.35rem .5rem', fontSize: 12.5 }} />
                          </td>
                          <td style={{ padding: '.55rem 1rem', textAlign: 'right' }}>
                            {e && <button onClick={() => clearEntry(e.id)} title="Use base price" style={{ background: 'none', border: 'none', color: '#94a3b8', cursor: 'pointer' }}>✕</button>}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}
