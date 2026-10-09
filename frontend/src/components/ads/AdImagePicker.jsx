import { useState, useEffect, useRef, useCallback } from 'react'
import { travel } from '../../api/travel'
import { fetchImageUrl } from '../../api/client'
import { toast } from '../Toast'

const SHAPE = { landscape: 'Landscape 1.91:1', square: 'Square 1:1', portrait: 'Portrait 4:5', tall: 'Tall 9:16', other: 'Other ratio' }

function Thumb({ id, size = 84 }) {
  const [url, setUrl] = useState('')
  useEffect(() => {
    let live = true, u = ''
    fetchImageUrl(`/ad-campaigns/assets/${id}/file`).then(x => { u = x; if (live) setUrl(x); else URL.revokeObjectURL(x) }).catch(() => {})
    return () => { live = false; if (u) URL.revokeObjectURL(u) }
  }, [id])
  return url ? <img src={url} alt="" style={{ width: size, height: size, objectFit: 'cover', borderRadius: 8, display: 'block' }} /> : <div style={{ width: size, height: size, background: 'var(--border)', borderRadius: 8 }} />
}

/**
 * Upload images from your computer and pick from your image library. `shape` limits the choices to images with that ratio
 * (landscape / square). `value` is a list of asset ids; `max` = how many may be chosen.
 */
export default function AdImagePicker({ value, onChange, shape, max = 1, label, hint }) {
  const [assets, setAssets] = useState([])
  const [busy, setBusy] = useState(false)
  const ref = useRef(null)
  const load = useCallback(() => travel.adAssets().then(setAssets).catch(() => {}), [])
  useEffect(() => { load() }, [load])

  async function upload(e) {
    const files = [...(e.target.files || [])]; e.target.value = ''
    if (!files.length) return
    setBusy(true)
    for (const f of files) {
      try { const a = await travel.uploadAdAsset(f); if (!shape || a.shape === shape) onChange(max === 1 ? [a.id] : [...new Set([...value, a.id])].slice(0, max)); else toast.error(`“${f.name}” is a ${a.shape} image`, `This slot needs a ${SHAPE[shape].toLowerCase()} image. It was added to your library.`) }
      catch (er) { toast.error(`Could not upload ${f.name}`, er.message) }
    }
    setBusy(false); load()
  }
  const toggle = (id) => onChange(value.includes(id) ? value.filter(x => x !== id) : max === 1 ? [id] : [...value, id].slice(0, max))
  const shown = assets.filter(a => !shape || a.shape === shape)

  return (
    <div>
      {label && <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-2)', marginBottom: 4 }}>{label}</div>}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-start' }}>
        {shown.map(a => (
          <div key={a.id} onClick={() => toggle(a.id)} title={`${a.name} · ${a.width}×${a.height}`} style={{ cursor: 'pointer', outline: value.includes(a.id) ? '3px solid var(--primary)' : '1px solid var(--border)', borderRadius: 8, position: 'relative' }}>
            <Thumb id={a.id} />{value.includes(a.id) && <span style={{ position: 'absolute', top: 2, right: 4, background: 'var(--primary)', color: '#fff', borderRadius: 999, fontSize: 11, padding: '0 5px' }}>✓</span>}
          </div>))}
        <input ref={ref} type="file" accept="image/jpeg,image/png" multiple={max > 1} hidden onChange={upload} />
        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => ref.current?.click()} style={{ height: 84, minWidth: 84 }}>{busy ? 'Uploading…' : '+ Upload'}</button>
      </div>
      <small style={{ color: 'var(--text-3)' }}>{hint || (shape ? `${SHAPE[shape]} · JPG or PNG, at least 600 px wide, under 5 MB.` : 'JPG or PNG, at least 600 px wide, under 5 MB.')} {max > 1 ? `Choose up to ${max}.` : ''}</small>
    </div>
  )
}
