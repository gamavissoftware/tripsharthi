import { useState, useEffect, useCallback, useRef } from 'react'
import { Pencil, Trash2, Target, Users, X, Megaphone, Zap, BarChart3, AlertTriangle, CheckCircle2, Check } from 'lucide-react'
import { segments as segmentsApi } from '../api/segments'
import { api } from '../api/client'
import { toast } from '../components/Toast'

// ── Constants ─────────────────────────────────────────────────────────────

const STATUS_OPTIONS = [
  { value: 'new',        label: 'New'        },
  { value: 'contacted',  label: 'Contacted'  },
  { value: 'qualified',  label: 'Qualified'  },
  { value: 'won',        label: 'Won'        },
  { value: 'lost',       label: 'Lost'       },
]

const SOURCE_OPTIONS = [
  { value: 'manual',        label: 'Manual'        },
  { value: 'csv_import',    label: 'CSV Import'    },
  { value: 'web_form',      label: 'Web Form'      },
  { value: 'meta_lead_ads', label: 'Meta Lead Ads' },
]

const FIELD_OPTIONS = [
  { value: 'status', label: 'Status'  },
  { value: 'source', label: 'Source'  },
  { value: 'tag_id', label: 'Tag'     },
  { value: 'opt_in', label: 'Opt-in'  },
]

const OPERATOR_OPTIONS = [
  { value: 'equals',     label: 'equals'     },
  { value: 'not_equals', label: 'not equals' },
]

const OPT_IN_OPTIONS = [
  { value: 'true',  label: 'Yes' },
  { value: 'false', label: 'No'  },
]

// Color map for filter pill types
const FIELD_COLORS = {
  status: { bg: '#eff6ff', color: '#1d4ed8', border: '#bfdbfe' },
  source: { bg: '#f0fdf4', color: '#15803d', border: '#bbf7d0' },
  tag_id: { bg: '#fefce8', color: '#a16207', border: '#fde68a' },
  opt_in: { bg: '#f0fdfa', color: '#0f766e', border: '#99f6e4' },
}

// ── Helpers ───────────────────────────────────────────────────────────────

function emptyCondition() {
  return { field: 'status', operator: 'equals', value: 'new' }
}

function emptyForm() {
  return { name: '', description: '', conditions: [emptyCondition()] }
}

function defaultValueForField(field, tags) {
  if (field === 'status') return 'new'
  if (field === 'source') return 'manual'
  if (field === 'tag_id') return tags.length > 0 ? tags[0].id : ''
  if (field === 'opt_in') return 'true'
  return ''
}

function filterLabel(condition, tags) {
  const field = condition.field
  const val   = condition.value
  const op    = condition.operator === 'not_equals' ? '≠' : '='
  if (field === 'status') return `Status ${op} ${val.charAt(0).toUpperCase() + val.slice(1)}`
  if (field === 'source') return `Source ${op} ${val.replace(/_/g, ' ')}`
  if (field === 'tag_id') {
    const tag = tags.find(t => String(t.id) === String(val))
    return `Tag ${op} ${tag?.name ?? val}`
  }
  if (field === 'opt_in') return `Opt-in ${op} ${val === 'true' ? 'Yes' : 'No'}`
  return `${field} ${op} ${val}`
}

function formatDate(dateStr) {
  if (!dateStr) return '—'
  try {
    return new Date(dateStr).toLocaleDateString('en-IN', {
      day: '2-digit', month: 'short', year: 'numeric',
    })
  } catch {
    return dateStr
  }
}

// ── ValuePicker ───────────────────────────────────────────────────────────

function ValuePicker({ condition, tags, onChange }) {
  const { field, value } = condition
  const selectStyle = {
    border: '1.5px solid #e5e7eb',
    borderRadius: 8,
    padding: '7px 10px',
    fontSize: '.875rem',
    background: '#fff',
    color: '#111827',
    outline: 'none',
    cursor: 'pointer',
    minWidth: 130,
    flex: 1,
  }

  if (field === 'status') {
    return (
      <select style={selectStyle} value={value} onChange={e => onChange(e.target.value)}>
        {STATUS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    )
  }
  if (field === 'source') {
    return (
      <select style={selectStyle} value={value} onChange={e => onChange(e.target.value)}>
        {SOURCE_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    )
  }
  if (field === 'tag_id') {
    return (
      <select style={selectStyle} value={value} onChange={e => onChange(Number(e.target.value))}>
        {tags.length === 0
          ? <option value="">No tags available</option>
          : tags.map(t => <option key={t.id} value={t.id}>{t.name}</option>)
        }
      </select>
    )
  }
  if (field === 'opt_in') {
    return (
      <select style={selectStyle} value={value} onChange={e => onChange(e.target.value)}>
        {OPT_IN_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    )
  }
  return null
}

// ── FilterPill ────────────────────────────────────────────────────────────

function FilterPill({ condition, tags }) {
  const colors = FIELD_COLORS[condition.field] || { bg: '#f3f4f6', color: '#374151', border: '#d1d5db' }
  const label  = filterLabel(condition, tags)
  return (
    <span style={{
      display: 'inline-block',
      background: colors.bg,
      color: colors.color,
      border: `1px solid ${colors.border}`,
      borderRadius: 999,
      padding: '3px 10px',
      fontSize: '.75rem',
      fontWeight: 600,
      letterSpacing: '.01em',
      whiteSpace: 'nowrap',
    }}>
      {label}
    </span>
  )
}

// ── 3-dot Menu ────────────────────────────────────────────────────────────

function CardMenu({ onEdit, onDelete }) {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)

  useEffect(() => {
    if (!open) return
    function handler(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button
        onClick={() => setOpen(v => !v)}
        style={{
          background: open ? '#f3f4f6' : 'transparent',
          border: '1px solid transparent',
          borderRadius: 8,
          padding: '4px 8px',
          cursor: 'pointer',
          fontSize: '1.1rem',
          color: '#6b7280',
          lineHeight: 1,
          transition: 'all .14s',
        }}
        onMouseEnter={e => { e.currentTarget.style.background = '#f3f4f6'; e.currentTarget.style.borderColor = '#e5e7eb' }}
        onMouseLeave={e => { if (!open) { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.borderColor = 'transparent' } }}
        title="More options"
        aria-label="More options"
      >
        ⋯
      </button>
      {open && (
        <div style={{
          position: 'absolute',
          top: '110%',
          right: 0,
          background: '#fff',
          border: '1px solid #e5e7eb',
          borderRadius: 10,
          boxShadow: '0 8px 24px rgba(0,0,0,.12)',
          minWidth: 140,
          zIndex: 100,
          overflow: 'hidden',
        }}>
          <button
            onClick={() => { setOpen(false); onEdit() }}
            style={{
              display: 'flex', alignItems: 'center', gap: 8,
              width: '100%', padding: '9px 14px',
              background: 'none', border: 'none', cursor: 'pointer',
              fontSize: '.8125rem', fontWeight: 600, color: '#374151',
              textAlign: 'left', transition: 'background .1s',
            }}
            onMouseEnter={e => e.currentTarget.style.background = '#f9fafb'}
            onMouseLeave={e => e.currentTarget.style.background = 'none'}
          >
            <Pencil size={14} strokeWidth={2} /> Edit
          </button>
          <div style={{ height: 1, background: '#f3f4f6', margin: '0 10px' }} />
          <button
            onClick={() => { setOpen(false); onDelete() }}
            style={{
              display: 'flex', alignItems: 'center', gap: 8,
              width: '100%', padding: '9px 14px',
              background: 'none', border: 'none', cursor: 'pointer',
              fontSize: '.8125rem', fontWeight: 600, color: '#dc2626',
              textAlign: 'left', transition: 'background .1s',
            }}
            onMouseEnter={e => e.currentTarget.style.background = '#fff5f5'}
            onMouseLeave={e => e.currentTarget.style.background = 'none'}
          >
            <Trash2 size={14} strokeWidth={2} /> Delete
          </button>
        </div>
      )}
    </div>
  )
}

// ── Segment Card ──────────────────────────────────────────────────────────

function SegmentCard({ segment, tags, onEdit, confirmId, setConfirmId }) {
  const [hover, setHover] = useState(false)
  // The API's field is `filters`; `conditions` is the local form-state name.
  const conditions = segment.filters ?? segment.conditions ?? []

  return (
    <div
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        background: '#fff',
        borderRadius: 14,
        border: '1.5px solid #e5e7eb',
        padding: '1.25rem',
        display: 'flex',
        flexDirection: 'column',
        gap: '.85rem',
        boxShadow: hover
          ? '0 6px 24px rgba(8,86,159,.1), 0 2px 8px rgba(0,0,0,.06)'
          : '0 1px 4px rgba(0,0,0,.05)',
        transition: 'all .18s ease',
        position: 'relative',
      }}
    >
      {/* Header row */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 8 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
          <span style={{ display: 'flex', alignItems: 'center', flexShrink: 0, color: 'var(--primary,#0a6cc4)' }}><Target size={16} strokeWidth={2} /></span>
          <span style={{
            fontWeight: 700, fontSize: '.9375rem', color: '#111827',
            overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
          }}>
            {segment.name}
          </span>
        </div>
        <CardMenu
          onEdit={() => onEdit(segment)}
          onDelete={() => setConfirmId(segment.id)}
        />
      </div>

      {/* Description */}
      {segment.description && (
        <p style={{ margin: 0, fontSize: '.8rem', color: '#6b7280', lineHeight: 1.5 }}>
          {segment.description}
        </p>
      )}

      {/* Filter pills */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
        {conditions.length > 0
          ? conditions.map((c, i) => <FilterPill key={i} condition={c} tags={tags} />)
          : <span style={{ fontSize: '.78rem', color: '#9ca3af', fontStyle: 'italic' }}>No filters — matches all contacts</span>
        }
      </div>

      {/* Delete confirmation inline */}
      {confirmId === segment.id ? (
        <DeleteConfirm
          name={segment.name}
          segmentId={segment.id}
          onCancel={() => setConfirmId(null)}
          afterDelete={() => setConfirmId(null)}
        />
      ) : (
        <>
          {/* Stats row */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <span style={{
              display: 'inline-flex', alignItems: 'center', gap: 5,
              background: '#eff6ff', color: '#1d4ed8',
              border: '1px solid #bfdbfe',
              borderRadius: 999, padding: '4px 12px',
              fontSize: '.8rem', fontWeight: 700,
            }}>
              <Users size={13} strokeWidth={2} /> {(segment.count ?? 0).toLocaleString()} contact{(segment.count ?? 0) !== 1 ? 's' : ''}
            </span>
            <span style={{ fontSize: '.75rem', color: '#9ca3af' }}>
              Created: {formatDate(segment.created_at)}
            </span>
          </div>

          {/* Action buttons */}
          <div style={{ display: 'flex', gap: 8 }}>
            <button
              onClick={() => onEdit(segment)}
              style={{
                flex: 1,
                background: '#f9fafb',
                border: '1.5px solid #e5e7eb',
                borderRadius: 8,
                padding: '7px 0',
                fontSize: '.8rem',
                fontWeight: 600,
                color: '#374151',
                cursor: 'pointer',
                transition: 'all .14s',
                display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 5,
              }}
              onMouseEnter={e => { e.currentTarget.style.background = '#f3f4f6'; e.currentTarget.style.borderColor = '#d1d5db' }}
              onMouseLeave={e => { e.currentTarget.style.background = '#f9fafb'; e.currentTarget.style.borderColor = '#e5e7eb' }}
            >
              <Pencil size={13} strokeWidth={2} /> Edit
            </button>
            <button
              onClick={() => { window.location.hash = '#/campaigns' }}
              style={{
                flex: 1.5,
                background: '#e8f3fc',
                border: '1.5px solid #bcdcf6',
                borderRadius: 8,
                padding: '7px 0',
                fontSize: '.8rem',
                fontWeight: 600,
                color: '#08569f',
                cursor: 'pointer',
                transition: 'all .14s',
                display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 5,
              }}
              onMouseEnter={e => { e.currentTarget.style.background = '#e0e7ff'; e.currentTarget.style.borderColor = '#8ec5f0' }}
              onMouseLeave={e => { e.currentTarget.style.background = '#e8f3fc'; e.currentTarget.style.borderColor = '#bcdcf6' }}
            >
              <Megaphone size={13} strokeWidth={2} /> Use in Campaign
            </button>
          </div>
        </>
      )}
    </div>
  )
}

// ── Inline delete confirmation (inside card) ──────────────────────────────

function DeleteConfirm({ name, segmentId, onCancel, afterDelete }) {
  const [deleting, setDeleting] = useState(false)

  async function doDelete() {
    setDeleting(true)
    try {
      await segmentsApi.del(segmentId)
      toast.success('Segment deleted', name)
      afterDelete()
      // trigger parent reload via custom event
      window.dispatchEvent(new CustomEvent('segments:reload'))
    } catch (err) {
      toast.error('Failed to delete segment', err.message)
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div style={{
      background: '#fff5f5',
      border: '1px solid #fecaca',
      borderRadius: 10,
      padding: '.75rem 1rem',
    }}>
      <p style={{ margin: '0 0 .6rem', fontSize: '.8125rem', fontWeight: 600, color: '#dc2626' }}>
        Delete "{name}"?
      </p>
      <p style={{ margin: '0 0 .75rem', fontSize: '.75rem', color: '#b91c1c' }}>
        This cannot be undone.
      </p>
      <div style={{ display: 'flex', gap: 8 }}>
        <button
          onClick={doDelete}
          disabled={deleting}
          style={{
            flex: 1,
            background: '#dc2626', color: '#fff',
            border: 'none', borderRadius: 7,
            padding: '6px 0', fontSize: '.78rem', fontWeight: 700,
            cursor: deleting ? 'not-allowed' : 'pointer', opacity: deleting ? .7 : 1,
          }}
        >
          {deleting ? 'Deleting…' : 'Yes, delete'}
        </button>
        <button
          onClick={onCancel}
          disabled={deleting}
          style={{
            flex: 1,
            background: '#fff', color: '#374151',
            border: '1px solid #d1d5db', borderRadius: 7,
            padding: '6px 0', fontSize: '.78rem', fontWeight: 600,
            cursor: 'pointer',
          }}
        >
          Cancel
        </button>
      </div>
    </div>
  )
}

// ── Loading Skeleton ───────────────────────────────────────────────────────

function SkeletonCard() {
  return (
    <div style={{
      background: '#fff', borderRadius: 14, border: '1.5px solid #e5e7eb',
      padding: '1.25rem', display: 'flex', flexDirection: 'column', gap: '.85rem',
    }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div style={{ height: 16, width: '55%', background: '#f3f4f6', borderRadius: 8, animation: 'lp-seg-pulse 1.2s ease-in-out infinite' }} />
        <div style={{ height: 16, width: 24, background: '#f3f4f6', borderRadius: 6, animation: 'lp-seg-pulse 1.2s ease-in-out infinite' }} />
      </div>
      <div style={{ display: 'flex', gap: 8 }}>
        <div style={{ height: 22, width: 90, background: '#f3f4f6', borderRadius: 999, animation: 'lp-seg-pulse 1.2s ease-in-out infinite' }} />
        <div style={{ height: 22, width: 70, background: '#f3f4f6', borderRadius: 999, animation: 'lp-seg-pulse 1.2s ease-in-out infinite' }} />
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between' }}>
        <div style={{ height: 26, width: 110, background: '#f3f4f6', borderRadius: 999, animation: 'lp-seg-pulse 1.2s ease-in-out infinite' }} />
        <div style={{ height: 14, width: 80, background: '#f3f4f6', borderRadius: 6, animation: 'lp-seg-pulse 1.2s ease-in-out infinite' }} />
      </div>
      <div style={{ display: 'flex', gap: 8 }}>
        <div style={{ flex: 1, height: 34, background: '#f3f4f6', borderRadius: 8, animation: 'lp-seg-pulse 1.2s ease-in-out infinite' }} />
        <div style={{ flex: 1.5, height: 34, background: '#f3f4f6', borderRadius: 8, animation: 'lp-seg-pulse 1.2s ease-in-out infinite' }} />
      </div>
    </div>
  )
}

// ── Create / Edit Modal ───────────────────────────────────────────────────

function SegmentModal({ editingSegment, tags, onClose, onSaved }) {
  const [form, setFormState]     = useState(() =>
    editingSegment
      ? {
          name:        editingSegment.name        || '',
          description: editingSegment.description || '',
          conditions:  (editingSegment.filters ?? editingSegment.conditions)?.length > 0
            ? (editingSegment.filters ?? editingSegment.conditions)
            : [emptyCondition()],
        }
      : emptyForm()
  )
  const [formErrors, setFormErrors] = useState({})
  const [saving, setSaving]         = useState(false)
  const [previewCount, setPreviewCount] = useState(
    editingSegment ? (editingSegment.count ?? null) : null
  )
  const [previewLoading, setPreviewLoading] = useState(false)
  const debounceRef = useRef(null)
  const nameRef     = useRef(null)

  useEffect(() => { nameRef.current?.focus() }, [])

  // Debounced live count for edit mode
  useEffect(() => {
    if (!editingSegment) return
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(async () => {
      if (!editingSegment.id) return
      setPreviewLoading(true)
      try {
        const res = await api.get(`/segments/${editingSegment.id}/count`)
        setPreviewCount(res.count ?? res.data?.count ?? 0)
      } catch {
        // silently ignore
      } finally {
        setPreviewLoading(false)
      }
    }, 800)
    return () => clearTimeout(debounceRef.current)
  }, [form.conditions, editingSegment])

  function setField(key, value) {
    setFormState(f => ({ ...f, [key]: value }))
    if (formErrors[key]) setFormErrors(e => ({ ...e, [key]: null }))
  }

  function addCondition() {
    if (form.conditions.length >= 5) return
    setFormState(f => ({ ...f, conditions: [...f.conditions, emptyCondition()] }))
  }

  function removeCondition(index) {
    setFormState(f => ({ ...f, conditions: f.conditions.filter((_, i) => i !== index) }))
  }

  function updateCondition(index, key, value) {
    setFormState(f => {
      const updated = f.conditions.map((c, i) => {
        if (i !== index) return c
        if (key === 'field') return { ...c, field: value, value: defaultValueForField(value, tags) }
        return { ...c, [key]: value }
      })
      return { ...f, conditions: updated }
    })
  }

  function validate() {
    const errors = {}
    if (!form.name.trim()) errors.name = 'Segment name is required'
    return errors
  }

  async function handleSave() {
    const errors = validate()
    if (Object.keys(errors).length > 0) {
      setFormErrors(errors)
      toast.error('Please fix the errors before saving')
      return
    }

    setSaving(true)
    setFormErrors({})
    try {
      const payload = {
        name:        form.name.trim(),
        description: form.description.trim(),
        filters:     form.conditions,
      }

      let saved
      if (editingSegment) {
        saved = await segmentsApi.update(editingSegment.id, payload)
        toast.success('Segment updated!', form.name.trim())
      } else {
        saved = await segmentsApi.create(payload)
        toast.success('Segment created!', form.name.trim())
      }

      // Fetch count for edit mode after save
      const savedId = saved?.id ?? saved?.data?.id ?? editingSegment?.id
      if (savedId) {
        try {
          const countRes = await api.get(`/segments/${savedId}/count`)
          setPreviewCount(countRes.count ?? countRes.data?.count ?? 0)
        } catch { /* ignore */ }
      }

      onSaved()
    } catch (err) {
      toast.error('Failed to save segment', err.message)
      setFormErrors({ general: err.message || 'Failed to save segment' })
    } finally {
      setSaving(false)
    }
  }

  const selectStyle = {
    border: '1.5px solid #e5e7eb',
    borderRadius: 8,
    padding: '7px 10px',
    fontSize: '.875rem',
    background: '#fff',
    color: '#111827',
    outline: 'none',
    cursor: 'pointer',
  }

  return (
    <div
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
      style={{
        position: 'fixed', inset: 0,
        background: 'rgba(0,0,0,.45)',
        backdropFilter: 'blur(3px)',
        zIndex: 10000,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        padding: 20,
        overflowY: 'auto',
      }}
    >
      <div style={{
        background: '#fff',
        borderRadius: 18,
        width: '100%',
        maxWidth: 580,
        boxShadow: '0 24px 64px rgba(0,0,0,.22)',
        overflow: 'hidden',
        marginBlock: 'auto',
      }}>
        {/* Modal header */}
        <div style={{
          padding: '1.25rem 1.5rem',
          borderBottom: '1px solid #f3f4f6',
          display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between',
          gap: 12,
        }}>
          <div>
            <h3 style={{ fontWeight: 800, fontSize: '1.0625rem', color: '#111827', margin: 0 }}>
              {editingSegment ? 'Edit Segment' : 'Create Segment'}
            </h3>
            <p style={{ margin: '3px 0 0', fontSize: '.8rem', color: '#6b7280' }}>
              Build a filter to match specific contacts
            </p>
          </div>
          <button
            onClick={onClose}
            style={{
              background: '#f3f4f6', border: 'none', borderRadius: 8,
              width: 32, height: 32, fontSize: '1rem',
              cursor: 'pointer', color: '#6b7280',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              flexShrink: 0,
              transition: 'all .14s',
            }}
            onMouseEnter={e => { e.currentTarget.style.background = '#e5e7eb' }}
            onMouseLeave={e => { e.currentTarget.style.background = '#f3f4f6' }}
            aria-label="Close"
          >
            <X size={16} strokeWidth={2} />
          </button>
        </div>

        {/* Modal body */}
        <div style={{ padding: '1.5rem', display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>

          {/* General error */}
          {formErrors.general && (
            <div style={{
              background: '#fff5f5', border: '1px solid #fecaca',
              borderRadius: 10, padding: '.75rem 1rem',
              fontSize: '.8125rem', color: '#dc2626', fontWeight: 600,
            }}>
              {formErrors.general}
            </div>
          )}

          {/* Name */}
          <div>
            <label style={{ display: 'block', fontWeight: 700, fontSize: '.8125rem', color: '#374151', marginBottom: 6 }}>
              Segment name *
            </label>
            <input
              ref={nameRef}
              value={form.name}
              onChange={e => setField('name', e.target.value)}
              placeholder="e.g. Hot Leads from Meta, New Web Signups…"
              style={{
                width: '100%', boxSizing: 'border-box',
                border: `1.5px solid ${formErrors.name ? '#f87171' : '#e5e7eb'}`,
                borderRadius: 10, padding: '10px 14px',
                fontSize: '.9375rem', fontWeight: 600,
                outline: 'none', transition: 'border-color .15s',
                background: formErrors.name ? '#fff5f5' : '#fff',
                color: '#111827',
              }}
              onFocus={e => { if (!formErrors.name) e.target.style.borderColor = 'var(--primary,#0a6cc4)' }}
              onBlur={e => { if (!formErrors.name) e.target.style.borderColor = '#e5e7eb' }}
            />
            {formErrors.name && (
              <p style={{ margin: '4px 0 0', fontSize: '.75rem', color: '#dc2626', fontWeight: 600 }}>
                {formErrors.name}
              </p>
            )}
          </div>

          {/* Description */}
          <div>
            <label style={{ display: 'block', fontWeight: 700, fontSize: '.8125rem', color: '#374151', marginBottom: 6 }}>
              Description <span style={{ color: '#9ca3af', fontWeight: 400 }}>(optional)</span>
            </label>
            <textarea
              value={form.description}
              onChange={e => setField('description', e.target.value)}
              placeholder="Brief description of this segment…"
              rows={2}
              style={{
                width: '100%', boxSizing: 'border-box',
                border: '1.5px solid #e5e7eb', borderRadius: 10,
                padding: '10px 14px', fontSize: '.875rem', color: '#374151',
                resize: 'vertical', outline: 'none', transition: 'border-color .15s',
                fontFamily: 'inherit',
              }}
              onFocus={e => { e.target.style.borderColor = 'var(--primary,#0a6cc4)' }}
              onBlur={e => { e.target.style.borderColor = '#e5e7eb' }}
            />
          </div>

          {/* Filter builder */}
          <div>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
              <div>
                <p style={{ margin: 0, fontWeight: 700, fontSize: '.875rem', color: '#374151' }}>
                  Filter conditions
                </p>
                <p style={{ margin: '2px 0 0', fontSize: '.75rem', color: '#6b7280' }}>
                  ALL conditions must match (AND logic)
                </p>
              </div>
              <span style={{
                background: '#f3f4f6', color: '#6b7280',
                borderRadius: 999, padding: '2px 10px',
                fontSize: '.72rem', fontWeight: 700,
              }}>
                {form.conditions.length}/5
              </span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              {form.conditions.map((condition, index) => (
                <div
                  key={index}
                  style={{
                    display: 'flex', alignItems: 'center', gap: 8,
                    background: '#fafafa', border: '1.5px solid #e5e7eb',
                    borderRadius: 10, padding: '8px 10px',
                    flexWrap: 'wrap',
                  }}
                >
                  {index > 0 && (
                    <span style={{
                      fontSize: '.68rem', fontWeight: 700, color: 'var(--primary,#0a6cc4)',
                      background: 'var(--primary-light,#e8f3fc)', borderRadius: 4, padding: '2px 6px',
                      flexShrink: 0,
                    }}>
                      AND
                    </span>
                  )}

                  <select
                    style={{ ...selectStyle, minWidth: 100, flex: 1 }}
                    value={condition.field}
                    onChange={e => updateCondition(index, 'field', e.target.value)}
                  >
                    {FIELD_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                  </select>

                  <select
                    style={{ ...selectStyle, minWidth: 110, flex: 1 }}
                    value={condition.operator}
                    onChange={e => updateCondition(index, 'operator', e.target.value)}
                  >
                    {OPERATOR_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                  </select>

                  <div style={{ flex: 1.2, minWidth: 120 }}>
                    <ValuePicker
                      condition={condition}
                      tags={tags}
                      onChange={val => updateCondition(index, 'value', val)}
                    />
                  </div>

                  <button
                    onClick={() => removeCondition(index)}
                    title="Remove filter"
                    style={{
                      background: 'none', border: '1px solid #fecaca',
                      borderRadius: 7, width: 28, height: 28,
                      cursor: 'pointer', color: '#dc2626',
                      fontSize: '.875rem', fontWeight: 700,
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                      flexShrink: 0, transition: 'all .14s',
                    }}
                    onMouseEnter={e => { e.currentTarget.style.background = '#fee2e2' }}
                    onMouseLeave={e => { e.currentTarget.style.background = 'none' }}
                  >
                    <X size={14} strokeWidth={2} />
                  </button>
                </div>
              ))}
            </div>

            <button
              onClick={addCondition}
              disabled={form.conditions.length >= 5}
              style={{
                marginTop: 10,
                display: 'flex', alignItems: 'center', gap: 6,
                background: 'none',
                border: `2px dashed ${form.conditions.length >= 5 ? '#d1d5db' : '#8ec5f0'}`,
                borderRadius: 10, padding: '8px 14px',
                cursor: form.conditions.length >= 5 ? 'not-allowed' : 'pointer',
                color: form.conditions.length >= 5 ? '#9ca3af' : '#08569f',
                fontSize: '.8125rem', fontWeight: 700,
                width: '100%', justifyContent: 'center',
                transition: 'all .14s',
                opacity: form.conditions.length >= 5 ? .6 : 1,
              }}
              onMouseEnter={e => { if (form.conditions.length < 5) e.currentTarget.style.background = '#e8f3fc' }}
              onMouseLeave={e => { e.currentTarget.style.background = 'none' }}
            >
              + Add filter {form.conditions.length >= 5 && '(max 5)'}
            </button>
          </div>

          {/* Live preview */}
          <div style={{
            background: previewCount === null
              ? '#f9fafb'
              : previewCount === 0
              ? '#fffbeb'
              : '#f0fdf4',
            border: `1.5px solid ${
              previewCount === null ? '#e5e7eb' : previewCount === 0 ? '#fde68a' : '#bbf7d0'
            }`,
            borderRadius: 12, padding: '.9rem 1rem',
            display: 'flex', alignItems: 'center', gap: 10,
          }}>
            <span style={{ display: 'flex', alignItems: 'center', flexShrink: 0, color: previewCount === null ? '#6b7280' : previewCount === 0 ? '#b45309' : '#16a34a' }}>
              {previewCount === null ? <Users size={18} strokeWidth={2} /> : previewCount === 0 ? <AlertTriangle size={18} strokeWidth={2} /> : <CheckCircle2 size={18} strokeWidth={2} />}
            </span>
            <div>
              <p style={{ margin: 0, fontWeight: 700, fontSize: '.875rem', color: '#111827' }}>
                {previewLoading
                  ? 'Calculating…'
                  : previewCount === null
                  ? editingSegment
                    ? 'Save to see exact count'
                    : 'Save to see contact count'
                  : previewCount === 0
                  ? 'No contacts match these filters'
                  : `${previewCount.toLocaleString()} contact${previewCount !== 1 ? 's' : ''} match`
                }
              </p>
              {previewCount !== null && previewCount > 0 && (
                <p style={{ margin: '2px 0 0', fontSize: '.75rem', color: '#6b7280' }}>
                  Estimated reach for this segment
                </p>
              )}
              {previewCount !== null && previewCount === 0 && (
                <p style={{ margin: '2px 0 0', fontSize: '.75rem', color: '#92400e' }}>
                  Try adjusting the filter conditions
                </p>
              )}
              {previewCount === null && !editingSegment && (
                <p style={{ margin: '2px 0 0', fontSize: '.75rem', color: '#6b7280' }}>
                  Create the segment to see how many contacts match
                </p>
              )}
            </div>
          </div>

          {/* Save button */}
          <button
            onClick={handleSave}
            disabled={saving}
            style={{
              width: '100%',
              background: saving ? '#8ec5f0' : 'linear-gradient(135deg, var(--primary,#0a6cc4) 0%, var(--primary-dark,#08569f) 100%)',
              color: '#fff',
              border: 'none',
              borderRadius: 10,
              padding: '12px 0',
              fontSize: '.9375rem',
              fontWeight: 700,
              cursor: saving ? 'not-allowed' : 'pointer',
              boxShadow: saving ? 'none' : '0 4px 14px rgba(10,108,196,.4)',
              transition: 'all .15s',
              letterSpacing: '.01em',
              display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6,
            }}
          >
            {saving ? 'Saving…' : <><Check size={15} strokeWidth={2} /> Save Segment</>}
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────

export default function SegmentsPage() {
  const [segments, setSegments] = useState([])
  const [tags, setTags]         = useState([])
  const [loading, setLoading]   = useState(true)

  const [modalOpen, setModalOpen]       = useState(false)
  const [editingSegment, setEditingSegment] = useState(null)
  const [confirmId, setConfirmId]       = useState(null)

  const loadData = useCallback(async () => {
    setLoading(true)
    try {
      const [segsRes, tagsRes] = await Promise.all([
        segmentsApi.list(),
        api.get('/tags'),
      ])
      setSegments(segsRes.data ?? segsRes ?? [])
      setTags(tagsRes.data ?? tagsRes ?? [])
    } catch (err) {
      toast.error('Failed to load segments', err.message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  // Listen for reload events from inline DeleteConfirm
  useEffect(() => {
    const handler = () => loadData()
    window.addEventListener('segments:reload', handler)
    return () => window.removeEventListener('segments:reload', handler)
  }, [loadData])

  function openCreate() {
    setEditingSegment(null)
    setModalOpen(true)
  }

  function openEdit(segment) {
    setEditingSegment(segment)
    setModalOpen(true)
  }

  function closeModal() {
    setModalOpen(false)
    setEditingSegment(null)
  }

  async function afterSaved() {
    closeModal()
    await loadData()
  }

  return (
    <div className="page">
      <style>{`
        @keyframes lp-seg-pulse { 0%,100%{opacity:1} 50%{opacity:.45} }
      `}</style>

      {/* ── Header ──────────────────────────────────────────────────── */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '.75rem', marginBottom: '1.5rem' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>Segments</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>
            Smart contact groups for targeted campaigns
            {!loading && segments.length > 0 && ` · ${segments.length} segment${segments.length !== 1 ? 's' : ''}`}
          </p>
        </div>
        <button onClick={openCreate} style={{ padding: '.6rem 1.2rem', borderRadius: 10, border: 'none', background: 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 2px 10px var(--primary-ring,rgba(10,108,196,.35))' }}>
          + New segment
        </button>
      </div>

      {/* ── Loading skeleton ─────────────────────────────────────────── */}
      {loading && (
        <div style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))',
          gap: '1rem',
        }}>
          {[1, 2, 3].map(i => <SkeletonCard key={i} />)}
        </div>
      )}

      {/* ── Empty state ──────────────────────────────────────────────── */}
      {!loading && segments.length === 0 && (
        <div style={{
          textAlign: 'center',
          padding: '4rem 2rem',
          background: '#fff',
          borderRadius: 16,
          border: '2px dashed #e5e7eb',
          maxWidth: 560,
          margin: '2rem auto',
        }}>
          <div style={{ marginBottom: '.75rem', lineHeight: 1, color: '#9ca3af' }}><Target size={40} strokeWidth={1.4} /></div>
          <h3 style={{ fontWeight: 800, fontSize: '1.25rem', color: '#111827', marginBottom: '.5rem' }}>
            No segments yet
          </h3>
          <p style={{ color: '#6b7280', fontSize: '.875rem', lineHeight: 1.6, maxWidth: 380, margin: '0 auto 1.5rem' }}>
            Segments let you group contacts by status, source, or tags — then target them with campaigns and flows.
          </p>

          <ul style={{
            listStyle: 'none', margin: '0 auto 1.75rem', padding: 0,
            maxWidth: 300, textAlign: 'left',
            display: 'flex', flexDirection: 'column', gap: 8,
          }}>
            {[
              [<Megaphone size={16} strokeWidth={2} />, 'Use segments as campaign audiences'],
              [<Zap size={16} strokeWidth={2} />, 'Trigger flows for specific contact groups'],
              [<BarChart3 size={16} strokeWidth={2} />, 'Track performance per audience'],
            ].map(([icon, text]) => (
              <li key={text} style={{
                display: 'flex', alignItems: 'flex-start', gap: 10,
                fontSize: '.875rem', color: '#374151',
              }}>
                <span style={{ flexShrink: 0, display: 'flex', marginTop: 1, color: 'var(--primary,#0a6cc4)' }}>{icon}</span>
                <span>{text}</span>
              </li>
            ))}
          </ul>

          <button className="btn btn-primary" onClick={openCreate} style={{ fontSize: '.9375rem' }}>
            + Create your first segment
          </button>
        </div>
      )}

      {/* ── Segments grid ───────────────────────────────────────────── */}
      {!loading && segments.length > 0 && (
        <div style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))',
          gap: '1rem',
        }}>
          {segments.map(seg => (
            <SegmentCard
              key={seg.id}
              segment={seg}
              tags={tags}
              onEdit={openEdit}
              confirmId={confirmId}
              setConfirmId={setConfirmId}
            />
          ))}

          {/* Quick-create card */}
          <button
            onClick={openCreate}
            style={{
              border: '2px dashed #d1d5db', borderRadius: 14,
              background: 'transparent', padding: '1.25rem',
              cursor: 'pointer', display: 'flex', flexDirection: 'column',
              alignItems: 'center', justifyContent: 'center', gap: '.5rem',
              minHeight: 160, color: '#9ca3af', fontSize: '.875rem',
              transition: 'all .18s',
            }}
            onMouseEnter={e => {
              e.currentTarget.style.borderColor = 'var(--primary,#0a6cc4)'
              e.currentTarget.style.color = 'var(--primary,#0a6cc4)'
              e.currentTarget.style.background = 'var(--primary-light,#e8f3fc)'
            }}
            onMouseLeave={e => {
              e.currentTarget.style.borderColor = '#d1d5db'
              e.currentTarget.style.color = '#9ca3af'
              e.currentTarget.style.background = 'transparent'
            }}
          >
            <span style={{ display: 'flex' }}><Target size={28} strokeWidth={1.6} /></span>
            <span style={{ fontWeight: 700 }}>New Segment</span>
            <span style={{ fontSize: '.75rem', opacity: .7 }}>Click to create</span>
          </button>
        </div>
      )}

      {/* ── Modal ───────────────────────────────────────────────────── */}
      {modalOpen && (
        <SegmentModal
          editingSegment={editingSegment}
          tags={tags}
          onClose={closeModal}
          onSaved={afterSaved}
        />
      )}
    </div>
  )
}
