import { useState, useEffect, useRef } from 'react'
import { Pencil, Trash2, Tag, Search, X } from 'lucide-react'
import { tags as tagsApi } from '../api/tags'
import { toast } from '../components/Toast'

// ── Palette ───────────────────────────────────────────────────────────────
const PALETTE = [
  { hex: '#0a6cc4', name: 'Indigo'  },
  { hex: '#ec4899', name: 'Pink'    },
  { hex: '#f59e0b', name: 'Amber'   },
  { hex: '#10b981', name: 'Emerald' },
  { hex: '#3b82f6', name: 'Blue'    },
  { hex: '#ef4444', name: 'Red'     },
  { hex: '#12a89e', name: 'Violet'  },
  { hex: '#06b6d4', name: 'Cyan'    },
  { hex: '#14b8a6', name: 'Teal'    },
  { hex: '#f97316', name: 'Orange'  },
  { hex: '#84cc16', name: 'Lime'    },
  { hex: '#64748b', name: 'Slate'   },
]

// Darken a hex color for text contrast
function darken(hex) {
  const n = parseInt(hex.replace('#',''), 16)
  const r = Math.max(0, (n >> 16) - 40)
  const g = Math.max(0, ((n >> 8) & 0xff) - 40)
  const b = Math.max(0, (n & 0xff) - 40)
  return `#${[r,g,b].map(x=>x.toString(16).padStart(2,'0')).join('')}`
}

// ── Tag chip with contact count ───────────────────────────────────────────
function TagCard({ tag, onEdit, onDelete, confirmId, setConfirmId }) {
  const bg      = tag.color ?? '#0a6cc4'
  const lightBg = bg + '18'
  const [hover, setHover] = useState(false)

  return (
    <div
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        background: hover ? bg + '12' : '#fff',
        border: `1.5px solid ${bg}40`,
        borderRadius: 14,
        padding: '1rem 1.25rem',
        display: 'flex',
        flexDirection: 'column',
        gap: '.65rem',
        transition: 'all .18s ease',
        boxShadow: hover
          ? `0 4px 20px ${bg}28, 0 1px 4px rgba(0,0,0,.06)`
          : '0 1px 4px rgba(0,0,0,.05)',
        cursor: 'default',
        position: 'relative',
      }}
    >
      {/* Tag chip */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <span style={{
          display: 'inline-flex', alignItems: 'center', gap: '.4rem',
          background: bg, color: '#fff',
          borderRadius: 999, padding: '.3rem .85rem',
          fontSize: '.8125rem', fontWeight: 700,
          boxShadow: `0 2px 8px ${bg}50`,
          letterSpacing: '.01em',
        }}>
          {tag.name}
        </span>

        {/* Color dot + name */}
        <span style={{
          fontSize: '.7rem', color: bg,
          fontWeight: 600, opacity: .85,
        }}>
          {PALETTE.find(p => p.hex.toLowerCase() === (tag.color||'').toLowerCase())?.name ?? 'Custom'}
        </span>
      </div>

      {/* Contact count */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '.4rem' }}>
        <div style={{
          width: 6, height: 6, borderRadius: '50%', background: bg, flexShrink: 0,
        }} />
        <span style={{ fontSize: '.78rem', color: 'var(--text-3)', fontWeight: 500 }}>
          {(tag.contact_count ?? 0).toLocaleString()} contact{(tag.contact_count ?? 0) !== 1 ? 's' : ''}
        </span>
      </div>

      {/* Actions */}
      {confirmId === tag.id ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: .5, fontSize: '.78rem' }}>
          <span style={{ color: '#dc2626', fontWeight: 600, fontSize: '.75rem' }}>Delete "{tag.name}"?</span>
          <button
            onClick={() => onDelete(tag.id)}
            style={{ marginLeft: 'auto', background: '#dc2626', color: '#fff', border: 'none',
                     borderRadius: 6, padding: '3px 10px', fontSize: '.75rem', cursor: 'pointer', fontWeight: 600 }}>
            Yes
          </button>
          <button
            onClick={() => setConfirmId(null)}
            style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 6,
                     padding: '3px 10px', fontSize: '.75rem', cursor: 'pointer', color: 'var(--text-2)' }}>
            No
          </button>
        </div>
      ) : (
        <div style={{ display: 'flex', gap: 6 }}>
          <button
            onClick={() => onEdit(tag)}
            style={{
              flex: 1, background: bg + '14', border: `1px solid ${bg}30`,
              color: bg, borderRadius: 7, padding: '5px 0', fontSize: '.78rem',
              fontWeight: 600, cursor: 'pointer', transition: 'all .14s',
              display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 4,
            }}
            onMouseEnter={e => e.currentTarget.style.background = bg + '28'}
            onMouseLeave={e => e.currentTarget.style.background = bg + '14'}
          >
            <Pencil size={13} strokeWidth={2} /> Edit
          </button>
          <button
            onClick={() => setConfirmId(tag.id)}
            style={{
              flex: 1, background: '#fff1f0', border: '1px solid #fca5a5',
              color: '#dc2626', borderRadius: 7, padding: '5px 0', fontSize: '.78rem',
              fontWeight: 600, cursor: 'pointer', transition: 'all .14s',
              display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 4,
            }}
            onMouseEnter={e => { e.currentTarget.style.background='#fee2e2'; e.currentTarget.style.borderColor='#ef4444' }}
            onMouseLeave={e => { e.currentTarget.style.background='#fff1f0'; e.currentTarget.style.borderColor='#fca5a5' }}
          >
            <Trash2 size={13} strokeWidth={2} /> Delete
          </button>
        </div>
      )}
    </div>
  )
}

// ── Create/Edit Modal ─────────────────────────────────────────────────────
function TagModal({ editTag, onClose, onSave }) {
  const [name, setName]   = useState(editTag?.name ?? '')
  const [color, setColor] = useState(editTag?.color ?? PALETTE[0].hex)
  const [customHex, setCustomHex] = useState('')
  const [saving, setSaving] = useState(false)
  const [err, setErr]     = useState(null)
  const inputRef = useRef(null)

  useEffect(() => { inputRef.current?.focus() }, [])

  async function handleSave(e) {
    e.preventDefault()
    if (!name.trim()) { setErr('Tag name is required.'); return }
    setSaving(true); setErr(null)
    try {
      if (editTag) {
        await tagsApi.update(editTag.id, { name: name.trim(), color })
        toast.success('Tag updated', name.trim())
      } else {
        await tagsApi.create({ name: name.trim(), color })
        toast.success('Tag created!', name.trim())
      }
      onSave()
    } catch (error) {
      setErr(error.message ?? 'Failed to save tag.')
      toast.error('Failed to save tag', error.message)
    } finally {
      setSaving(false)
    }
  }

  const resolvedColor = customHex.match(/^#[0-9a-fA-F]{6}$/) ? customHex : color

  return (
    <div
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)',
        zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
        backdropFilter: 'blur(2px)',
      }}
    >
      <div style={{
        background: '#fff', borderRadius: 18, width: '100%', maxWidth: 440,
        boxShadow: '0 24px 64px rgba(0,0,0,.22)', overflow: 'hidden',
      }}>
        {/* Header */}
        <div style={{
          padding: '1.25rem 1.5rem',
          borderBottom: '1px solid var(--border)',
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        }}>
          <div>
            <h3 style={{ fontWeight: 800, fontSize: '1rem', color: 'var(--text)', margin: 0 }}>
              {editTag ? 'Edit Tag' : 'Create New Tag'}
            </h3>
            <p style={{ margin: '2px 0 0', fontSize: '.78rem', color: 'var(--text-3)' }}>
              {editTag ? 'Update the tag name or colour' : 'Tags help you segment and organise contacts'}
            </p>
          </div>
          <button
            onClick={onClose}
            style={{ background: 'none', border: 'none', display: 'flex', alignItems: 'center',
                     cursor: 'pointer', color: 'var(--text-3)', lineHeight: 1 }}>
            <X size={18} strokeWidth={2} />
          </button>
        </div>

        {/* Body */}
        <form onSubmit={handleSave} style={{ padding: '1.5rem' }}>
          {/* Name */}
          <div className="form-group" style={{ marginBottom: '1.25rem' }}>
            <label className="form-label" style={{ fontWeight: 700 }}>Tag name *</label>
            <input
              ref={inputRef}
              className="form-input"
              value={name}
              onChange={e => { setName(e.target.value); if (err) setErr(null) }}
              placeholder="e.g. Hot Lead, VIP, Priority…"
              required
              style={{ fontSize: '1rem' }}
            />
            {err && <div className="field-error">{err}</div>}
          </div>

          {/* Colour picker */}
          <div className="form-group" style={{ marginBottom: '1.5rem' }}>
            <label className="form-label" style={{ fontWeight: 700 }}>Colour</label>

            {/* Palette swatches */}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: '.5rem' }}>
              {PALETTE.map(p => (
                <button
                  key={p.hex}
                  type="button"
                  title={p.name}
                  onClick={() => { setColor(p.hex); setCustomHex('') }}
                  style={{
                    width: 32, height: 32, borderRadius: 8,
                    background: p.hex, border: 'none', cursor: 'pointer',
                    outline: resolvedColor === p.hex ? `3px solid ${p.hex}` : '3px solid transparent',
                    outlineOffset: 2,
                    transform: resolvedColor === p.hex ? 'scale(1.15)' : 'scale(1)',
                    transition: 'all .14s',
                    boxShadow: `0 2px 8px ${p.hex}50`,
                  }}
                />
              ))}
            </div>

            {/* Custom hex input */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: '.85rem' }}>
              <input
                type="color"
                value={customHex || color}
                onChange={e => { setCustomHex(e.target.value); setColor(e.target.value) }}
                style={{ width: 36, height: 36, borderRadius: 8, border: '1px solid var(--border)',
                         padding: 2, cursor: 'pointer', background: 'none' }}
              />
              <input
                className="form-input"
                value={customHex}
                onChange={e => {
                  const v = e.target.value
                  setCustomHex(v)
                  if (v.match(/^#[0-9a-fA-F]{6}$/)) setColor(v)
                }}
                placeholder="#0a6cc4"
                style={{ flex: 1, fontFamily: 'monospace', fontSize: '.85rem' }}
              />
            </div>
          </div>

          {/* Live preview */}
          <div style={{
            background: resolvedColor + '10',
            border: `1px solid ${resolvedColor}30`,
            borderRadius: 10, padding: '.85rem 1rem',
            marginBottom: '1.25rem',
            display: 'flex', alignItems: 'center', gap: 10,
          }}>
            <span style={{ fontSize: '.78rem', color: 'var(--text-3)', fontWeight: 600 }}>Preview:</span>
            <span style={{
              background: resolvedColor, color: '#fff', borderRadius: 999,
              padding: '.25rem .85rem', fontSize: '.8125rem', fontWeight: 700,
              boxShadow: `0 2px 8px ${resolvedColor}50`,
            }}>
              {name || 'Tag name'}
            </span>
          </div>

          {/* Buttons */}
          <div style={{ display: 'flex', gap: 10 }}>
            <button
              type="button" onClick={onClose}
              className="btn btn-ghost"
              style={{ flex: 1 }}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving || !name.trim()}
              className="btn btn-primary"
              style={{ flex: 2, justifyContent: 'center',
                       background: resolvedColor, boxShadow: `0 2px 12px ${resolvedColor}50` }}
            >
              {saving ? '…' : (editTag ? 'Save changes' : '+ Create tag')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────
export default function TagsPage() {
  const [tagList, setTagList]   = useState([])
  const [loading, setLoading]   = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editTag, setEditTag]   = useState(null)
  const [confirmId, setConfirmId] = useState(null)
  const [search, setSearch]     = useState('')

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const res = await tagsApi.list()
      setTagList(res.data ?? [])
    } catch (err) {
      toast.error('Failed to load tags', err.message)
    } finally {
      setLoading(false)
    }
  }

  async function handleDelete(id) {
    try {
      await tagsApi.remove(id)
      const tag = tagList.find(t => t.id === id)
      toast.success('Tag deleted', tag?.name)
      setConfirmId(null)
      setTagList(prev => prev.filter(t => t.id !== id))
    } catch (err) {
      toast.error('Failed to delete tag', err.message)
    }
  }

  function openCreate() { setEditTag(null); setShowModal(true) }
  function openEdit(tag) { setEditTag(tag); setShowModal(true) }
  function closeModal() { setShowModal(false); setEditTag(null) }
  function afterSave() { closeModal(); load() }

  const filtered = tagList.filter(t =>
    t.name.toLowerCase().includes(search.toLowerCase())
  )

  const totalTagged = tagList.reduce((sum, t) => sum + (t.contact_count ?? 0), 0)

  return (
    <div className="page">
      {/* ── Header ─────────────────────────────────────────────────── */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '.75rem', marginBottom: '1.4rem' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>Tags</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>
            {loading ? 'Organise and segment your contacts.' : `${tagList.length} tag${tagList.length !== 1 ? 's' : ''} · ${totalTagged.toLocaleString()} tagged contacts`}
          </p>
        </div>
        <button onClick={openCreate} style={{ padding: '.6rem 1.2rem', borderRadius: 10, border: 'none', background: 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 2px 10px var(--primary-ring,rgba(10,108,196,.35))' }}>
          + New tag
        </button>
      </div>

      {/* ── Search ─────────────────────────────────────────────────── */}
      {tagList.length > 0 && (
        <div style={{ position: 'relative', maxWidth: 340, marginBottom: '1.25rem' }}>
          <span style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', display: 'flex', alignItems: 'center', opacity: .5 }}><Search size={15} strokeWidth={2} /></span>
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search tags…"
            style={{ width: '100%', padding: '.55rem .8rem .55rem 2.2rem', borderRadius: 10, border: '1.5px solid #e5e7eb', background: '#f9fafb', fontSize: 14, color: '#111827', outline: 'none', boxSizing: 'border-box' }}
            onFocus={e => e.target.style.borderColor = 'var(--primary,#0a6cc4)'}
            onBlur={e => e.target.style.borderColor = '#e5e7eb'}
          />
        </div>
      )}

      {/* ── Loading skeleton ───────────────────────────────────────── */}
      {loading && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '1rem' }}>
          {[1,2,3,4].map(i => (
            <div key={i} style={{
              background: '#f3f4f6', borderRadius: 14, padding: '1rem', height: 120,
              animation: 'lp-pulse 1.2s ease-in-out infinite',
            }} />
          ))}
        </div>
      )}

      {/* ── Empty state ────────────────────────────────────────────── */}
      {!loading && tagList.length === 0 && (
        <div style={{
          textAlign: 'center', padding: '4rem 2rem',
          background: '#fff', borderRadius: 16, border: '2px dashed var(--border)',
        }}>
          <div style={{ marginBottom: '.75rem', color: 'var(--text-3)' }}><Tag size={40} strokeWidth={1.4} /></div>
          <h3 style={{ fontWeight: 800, fontSize: '1.1rem', color: 'var(--text)', marginBottom: '.4rem' }}>
            No tags yet
          </h3>
          <p style={{ color: 'var(--text-3)', fontSize: '.875rem', marginBottom: '1.5rem', maxWidth: 320, margin: '0 auto .875rem' }}>
            Tags help you segment contacts — use them to target campaigns, trigger flows, and organise your leads.
          </p>
          <button className="btn btn-primary" onClick={openCreate}>
            + Create your first tag
          </button>
        </div>
      )}

      {/* ── No search results ──────────────────────────────────────── */}
      {!loading && tagList.length > 0 && filtered.length === 0 && (
        <div style={{ textAlign: 'center', padding: '3rem', color: 'var(--text-3)' }}>
          <div style={{ marginBottom: '.5rem' }}><Search size={32} strokeWidth={1.4} /></div>
          <p>No tags match "<strong>{search}</strong>"</p>
          <button onClick={() => setSearch('')} className="btn btn-ghost btn-sm" style={{ marginTop: '.5rem' }}>
            Clear search
          </button>
        </div>
      )}

      {/* ── Tags grid ──────────────────────────────────────────────── */}
      {!loading && filtered.length > 0 && (
        <div style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))',
          gap: '1rem',
        }}>
          {filtered.map(tag => (
            <TagCard
              key={tag.id}
              tag={tag}
              onEdit={openEdit}
              onDelete={handleDelete}
              confirmId={confirmId}
              setConfirmId={setConfirmId}
            />
          ))}

          {/* "New tag" quick-add card */}
          <button
            onClick={openCreate}
            style={{
              border: '2px dashed var(--border)', borderRadius: 14,
              background: 'transparent', padding: '1rem',
              cursor: 'pointer', display: 'flex', flexDirection: 'column',
              alignItems: 'center', justifyContent: 'center', gap: '.5rem',
              minHeight: 120, color: 'var(--text-3)', fontSize: '.875rem',
              transition: 'all .18s',
            }}
            onMouseEnter={e => { e.currentTarget.style.borderColor = 'var(--primary,#0a6cc4)'; e.currentTarget.style.color = 'var(--primary,#0a6cc4)'; e.currentTarget.style.background = 'var(--primary-light,#e8f3fc)' }}
            onMouseLeave={e => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.color = 'var(--text-3)'; e.currentTarget.style.background = 'transparent' }}
          >
            <span style={{ fontSize: '1.75rem' }}>+</span>
            <span style={{ fontWeight: 600 }}>New Tag</span>
          </button>
        </div>
      )}

      {/* ── CSS for pulse animation ─────────────────────────────────── */}
      <style>{`@keyframes lp-pulse { 0%,100%{opacity:1} 50%{opacity:.45} }`}</style>

      {/* ── Modal ──────────────────────────────────────────────────── */}
      {showModal && (
        <TagModal
          editTag={editTag}
          onClose={closeModal}
          onSave={afterSave}
        />
      )}
    </div>
  )
}
