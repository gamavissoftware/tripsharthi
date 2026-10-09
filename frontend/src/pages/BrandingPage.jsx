import { useState, useEffect, useMemo, useCallback } from 'react'
import { settings as settingsApi } from '../api/settings'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import { useBranding, BRANDING_DEFAULTS, shade, rgba } from '../branding/BrandingContext'
import { Eye, Lock, Check } from 'lucide-react'

// ── Inject styles once ──────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-brand-css')) {
  const el = document.createElement('style')
  el.id = 'lp-brand-css'
  el.textContent = `
    @keyframes lp-brand-in   { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    @keyframes lp-brand-spin { to { transform:rotate(360deg); } }
    @keyframes lp-brand-bar  { from { transform:translateY(120%); } to { transform:translateY(0); } }
    .lp-brand-grid {
      display:grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap:1.5rem; align-items:start;
    }
    @media (max-width: 980px) { .lp-brand-grid { grid-template-columns: 1fr; } }
    .lp-brand-card {
      background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:1.5rem 1.6rem;
      margin-bottom:1.25rem; animation: lp-brand-in .25s ease both;
    }
    .lp-brand-label { font-size:13px; font-weight:700; color:#374151; margin-bottom:.4rem; display:block; }
    .lp-brand-hint  { font-size:12px; color:#9ca3af; margin-top:.4rem; line-height:1.5; }
    .lp-brand-input {
      width:100%; padding:.62rem .85rem; border-radius:10px; border:1.5px solid #e5e7eb;
      background:#f9fafb; font-size:14px; color:#111827; outline:none; transition:border-color .15s, background .15s;
      box-sizing:border-box;
    }
    .lp-brand-input:focus { border-color:var(--lp-accent,#0a6cc4); background:#fff; }
    .lp-brand-input.invalid { border-color:#fca5a5; background:#fef2f2; }
    .lp-brand-swatch {
      width:30px; height:30px; border-radius:8px; cursor:pointer; border:2px solid #fff;
      box-shadow:0 0 0 1px #e5e7eb; transition:transform .12s, box-shadow .12s;
    }
    .lp-brand-swatch:hover { transform:scale(1.12); }
    .lp-brand-swatch.sel { box-shadow:0 0 0 2px #fff, 0 0 0 4px currentColor; }
    .lp-brand-err { font-size:12px; color:#dc2626; margin-top:.35rem; }
  `
  document.head.appendChild(el)
}

const COLOR_PRESETS = [
  '#0a6cc4', '#08569f', '#2563eb', '#0891b2', '#059669',
  '#16a34a', '#ca8a04', '#ea580c', '#dc2626', '#db2777',
  '#9333ea', '#0f172a',
]

const HEX_RE   = /^#[0-9a-fA-F]{6}$/
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function isValidUrl(v) {
  if (!v) return true
  try { const u = new URL(v); return u.protocol === 'http:' || u.protocol === 'https:' } catch { return false }
}

function normalizeBranding(b) {
  return {
    app_name:      b?.app_name ?? BRANDING_DEFAULTS.app_name ?? '',
    primary_color: b?.primary_color ?? BRANDING_DEFAULTS.primary_color,
    logo_url:      b?.logo_url ?? '',
    support_email: b?.support_email ?? '',
    custom_domain: b?.custom_domain ?? '',
  }
}

// ── Logo mark (image with graceful fallback) ────────────────────────────────
function LogoMark({ logoUrl, name, color, size = 32, radius = 8 }) {
  const [broken, setBroken] = useState(false)
  useEffect(() => { setBroken(false) }, [logoUrl])
  const letter = (name || 'L').trim().charAt(0).toUpperCase()

  if (logoUrl && !broken) {
    return (
      <img
        src={logoUrl}
        alt={name}
        onError={() => setBroken(true)}
        style={{ width:size, height:size, borderRadius:radius, objectFit:'cover', flexShrink:0, background:'#fff' }}
      />
    )
  }
  return (
    <div style={{
      width:size, height:size, borderRadius:radius, background:color, flexShrink:0,
      display:'flex', alignItems:'center', justifyContent:'center',
      color:'#fff', fontWeight:800, fontSize:size * 0.5,
    }}>
      {letter}
    </div>
  )
}

// ── Live preview ────────────────────────────────────────────────────────────
function LivePreview({ form }) {
  const color = HEX_RE.test(form.primary_color) ? form.primary_color : '#0a6cc4'
  const name  = form.app_name?.trim() || 'TripSarthi'

  return (
    <div style={{ position:'sticky', top:'1rem' }}>
      <div style={{ fontSize:13, fontWeight:700, color:'#374151', marginBottom:'.7rem', display:'flex', alignItems:'center', gap:'.4rem' }}>
        <Eye size={14} strokeWidth={2} /> Live preview
      </div>

      {/* App shell mock */}
      <div style={{
        borderRadius:16, overflow:'hidden', border:'1px solid #e5e7eb',
        boxShadow:'0 12px 36px rgba(0,0,0,.10)', background:'#fff', marginBottom:'1.1rem',
      }}>
        <div style={{ display:'flex', height:230 }}>
          {/* mini sidebar */}
          <div style={{ width:124, background:'#0f172a', padding:'.8rem .55rem', display:'flex', flexDirection:'column', gap:'.55rem' }}>
            <div style={{ display:'flex', alignItems:'center', gap:'.45rem', padding:'0 .15rem .35rem' }}>
              <LogoMark logoUrl={form.logo_url} name={name} color={color} size={24} radius={6} />
              <span style={{ color:'#fff', fontWeight:800, fontSize:12, letterSpacing:'-.2px', whiteSpace:'nowrap', overflow:'hidden', textOverflow:'ellipsis' }}>{name}</span>
            </div>
            {[['Contacts', true], ['Inbox', false], ['Campaigns', false], ['Flows', false]].map(([label, active]) => (
              <div key={label} style={{
                fontSize:11, fontWeight:active ? 700 : 500, padding:'.32rem .5rem', borderRadius:7,
                color: active ? '#fff' : '#94a3b8',
                background: active ? color : 'transparent',
                boxShadow: active ? `0 2px 8px ${rgba(color, .45)}` : 'none',
              }}>{label}</div>
            ))}
          </div>
          {/* mini content */}
          <div style={{ flex:1, padding:'.9rem 1rem', background:'#f8fafc' }}>
            <div style={{ height:9, width:'46%', borderRadius:5, background:'#e2e8f0', marginBottom:10 }} />
            <div style={{ display:'flex', gap:'.5rem', marginBottom:14 }}>
              <button style={{
                border:'none', borderRadius:8, padding:'.4rem .75rem', fontSize:11, fontWeight:700,
                color:'#fff', background:color, boxShadow:`0 2px 8px ${rgba(color, .4)}`, cursor:'default',
              }}>+ New campaign</button>
              <button style={{
                border:`1.5px solid ${color}`, borderRadius:8, padding:'.4rem .75rem', fontSize:11, fontWeight:700,
                color, background:'#fff', cursor:'default',
              }}>Filter</button>
            </div>
            {[1,2,3].map(i => (
              <div key={i} style={{ display:'flex', alignItems:'center', gap:'.5rem', marginBottom:8 }}>
                <div style={{ width:7, height:7, borderRadius:'50%', background: i===1 ? color : '#cbd5e1' }} />
                <div style={{ height:7, borderRadius:4, background:'#e2e8f0', flex:1, maxWidth: `${88 - i*12}%` }} />
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Login screen mock */}
      <div style={{
        borderRadius:16, border:'1px solid #e5e7eb', boxShadow:'0 12px 36px rgba(0,0,0,.10)',
        padding:'1.4rem', background:`linear-gradient(135deg, ${rgba(color,.08)}, ${rgba(color,.02)})`,
        textAlign:'center', marginBottom:'1.1rem',
      }}>
        <div style={{ display:'inline-flex', flexDirection:'column', alignItems:'center', gap:'.5rem' }}>
          <LogoMark logoUrl={form.logo_url} name={name} color={color} size={44} radius={12} />
          <div style={{ fontSize:15, fontWeight:800, color:'#111827' }}>{name}</div>
          <div style={{ fontSize:12, color:'#6b7280', marginTop:-2 }}>Sign in to your account</div>
          <button style={{
            marginTop:6, border:'none', borderRadius:9, padding:'.5rem 1.4rem', fontSize:12.5, fontWeight:700,
            color:'#fff', background:color, boxShadow:`0 3px 10px ${rgba(color,.4)}`, cursor:'default',
          }}>Sign in</button>
        </div>
      </div>

      {/* Email footer mock */}
      <div style={{ borderRadius:12, border:'1px solid #e5e7eb', padding:'.85rem 1rem', background:'#fff' }}>
        <div style={{ fontSize:10.5, fontWeight:700, textTransform:'uppercase', letterSpacing:'.06em', color:'#9ca3af', marginBottom:6 }}>Email footer</div>
        <div style={{ display:'flex', alignItems:'center', gap:'.5rem' }}>
          <LogoMark logoUrl={form.logo_url} name={name} color={color} size={20} radius={5} />
          <span style={{ fontSize:12, color:'#374151' }}>
            Sent via <strong style={{ color }}>{name}</strong>
            {form.support_email && EMAIL_RE.test(form.support_email) && (
              <> · <a href={`mailto:${form.support_email}`} style={{ color, textDecoration:'none' }}>{form.support_email}</a></>
            )}
          </span>
        </div>
      </div>
    </div>
  )
}

// ── Field ───────────────────────────────────────────────────────────────────
function Field({ label, hint, error, children }) {
  return (
    <div style={{ marginBottom:'1.15rem' }}>
      <label className="lp-brand-label">{label}</label>
      {children}
      {error
        ? <div className="lp-brand-err">{error}</div>
        : hint ? <div className="lp-brand-hint">{hint}</div> : null}
    </div>
  )
}

// ── Page ────────────────────────────────────────────────────────────────────
export default function BrandingPage() {
  const { applyBranding } = useBranding()
  const [form, setForm]         = useState(null)
  const [baseline, setBaseline] = useState(null)
  const [loading, setLoading]   = useState(true)
  const [saving, setSaving]     = useState(false)
  const [isOwner, setIsOwner]   = useState(true)

  useEffect(() => {
    settingsApi.getBranding()
      .then(res => {
        const b = normalizeBranding(res?.data ?? res)
        setForm(b); setBaseline(b)
      })
      .catch(() => {
        const b = normalizeBranding(BRANDING_DEFAULTS)
        setForm(b); setBaseline(b)
      })
      .finally(() => setLoading(false))

    api.get('/auth/me')
      .then(res => setIsOwner((res?.user?.role ?? 'owner') === 'owner'))
      .catch(() => {})
  }, [])

  const errors = useMemo(() => {
    if (!form) return {}
    const e = {}
    if (!form.app_name?.trim())                         e.app_name = 'App name is required.'
    else if (form.app_name.length > 255)                e.app_name = 'App name is too long.'
    if (!HEX_RE.test(form.primary_color))               e.primary_color = 'Enter a valid hex colour (#rrggbb).'
    if (form.logo_url && !isValidUrl(form.logo_url))    e.logo_url = 'Enter a valid http(s) image URL.'
    if (form.support_email && !EMAIL_RE.test(form.support_email)) e.support_email = 'Enter a valid email address.'
    return e
  }, [form])

  const dirty   = useMemo(() => form && baseline && JSON.stringify(form) !== JSON.stringify(baseline), [form, baseline])
  const canSave = isOwner && dirty && Object.keys(errors).length === 0 && !saving

  const setField = useCallback((k, v) => setForm(f => ({ ...f, [k]: v })), [])

  async function handleSave() {
    if (!canSave) return
    setSaving(true)
    try {
      // Send empty strings as null so the backend clears the field.
      const payload = {
        app_name:      form.app_name.trim(),
        primary_color: form.primary_color,
        logo_url:      form.logo_url?.trim() || null,
        support_email: form.support_email?.trim() || null,
        custom_domain: form.custom_domain?.trim() || null,
      }
      const res = await settingsApi.updateBranding(payload)
      const saved = normalizeBranding(res?.data ?? payload)
      setForm(saved); setBaseline(saved)
      applyBranding(res?.data ?? payload)   // update the real sidebar + accent instantly
      toast.success('Branding saved', 'Your white-label settings are now live.')
    } catch (err) {
      if (err.status === 403) toast.error('Not allowed', 'Only the workspace owner can change branding.')
      else toast.error('Save failed', err.message ?? 'Please try again.')
    } finally {
      setSaving(false)
    }
  }

  function handleDiscard() { setForm(baseline) }
  function handleResetDefaults() { setForm(normalizeBranding(BRANDING_DEFAULTS)) }

  if (loading || !form) {
    return (
      <div className="page">
        <div style={{ height:28, width:200, borderRadius:8, background:'#e5e7eb', marginBottom:24 }} />
        <div className="lp-brand-grid">
          <div style={{ height:420, borderRadius:18, background:'#f3f4f6' }} />
          <div style={{ height:420, borderRadius:18, background:'#f3f4f6' }} />
        </div>
      </div>
    )
  }

  const accentVar = HEX_RE.test(form.primary_color) ? form.primary_color : '#0a6cc4'

  return (
    <div className="page" style={{ '--lp-accent': accentVar, paddingBottom: dirty ? 88 : undefined }}>
      {/* Header */}
      <div style={{ marginBottom:'1.5rem' }}>
        <h1 style={{ margin:0, fontSize:'1.5rem', fontWeight:800, color:'#111827', letterSpacing:'-.02em' }}>
          Branding & White-label
        </h1>
        <p style={{ margin:'4px 0 0', fontSize:13.5, color:'#6b7280' }}>
          Make TripSarthi yours — logo, colours, and domain across the whole workspace.
        </p>
      </div>

      {!isOwner && (
        <div style={{ background:'#fffbeb', border:'1px solid #fde68a', borderRadius:12, padding:'.8rem 1rem', marginBottom:'1.25rem', fontSize:13.5, color:'#92400e', display:'flex', gap:'.55rem' }}>
          <Lock size={14} strokeWidth={2} style={{ flexShrink:0, marginTop:2 }} /> You're viewing branding in read-only mode. Only the workspace owner can make changes.
        </div>
      )}

      <div className="lp-brand-grid">
        {/* ── Controls ── */}
        <div>
          {/* Identity */}
          <div className="lp-brand-card">
            <div style={{ fontSize:15, fontWeight:800, color:'#111827', marginBottom:'1.1rem' }}>Identity</div>

            <Field label="App name" hint="Shown in the sidebar, login screen, browser tab and emails." error={errors.app_name}>
              <input
                className={`lp-brand-input ${errors.app_name ? 'invalid' : ''}`}
                value={form.app_name}
                disabled={!isOwner}
                maxLength={255}
                onChange={e => setField('app_name', e.target.value)}
                placeholder="TripSarthi"
              />
            </Field>

            <Field label="Logo" hint="Paste a public image URL (PNG or SVG, square works best). Leave empty to use a coloured monogram." error={errors.logo_url}>
              <div style={{ display:'flex', gap:'.75rem', alignItems:'center' }}>
                <LogoMark logoUrl={isValidUrl(form.logo_url) ? form.logo_url : ''} name={form.app_name} color={accentVar} size={46} radius={10} />
                <input
                  className={`lp-brand-input ${errors.logo_url ? 'invalid' : ''}`}
                  value={form.logo_url}
                  disabled={!isOwner}
                  onChange={e => setField('logo_url', e.target.value)}
                  placeholder="https://cdn.example.com/logo.png"
                />
                {form.logo_url && isOwner && (
                  <button
                    onClick={() => setField('logo_url', '')}
                    title="Remove logo"
                    style={{ border:'1.5px solid #e5e7eb', background:'#fff', borderRadius:9, padding:'.55rem .7rem', cursor:'pointer', color:'#6b7280', fontSize:13, flexShrink:0 }}
                  >Clear</button>
                )}
              </div>
            </Field>
          </div>

          {/* Appearance */}
          <div className="lp-brand-card">
            <div style={{ fontSize:15, fontWeight:800, color:'#111827', marginBottom:'1.1rem' }}>Appearance</div>

            <Field label="Primary colour" hint="Used for buttons, active navigation, links and accents across the app." error={errors.primary_color}>
              <div style={{ display:'flex', flexWrap:'wrap', gap:'.55rem', marginBottom:'.85rem' }}>
                {COLOR_PRESETS.map(c => (
                  <div
                    key={c}
                    className={`lp-brand-swatch ${form.primary_color.toLowerCase() === c.toLowerCase() ? 'sel' : ''}`}
                    style={{ background:c, color:c, opacity: isOwner ? 1 : .6, pointerEvents: isOwner ? 'auto' : 'none' }}
                    onClick={() => setField('primary_color', c)}
                    title={c}
                  />
                ))}
              </div>
              <div style={{ display:'flex', alignItems:'center', gap:'.6rem' }}>
                <input
                  type="color"
                  value={HEX_RE.test(form.primary_color) ? form.primary_color : '#0a6cc4'}
                  disabled={!isOwner}
                  onChange={e => setField('primary_color', e.target.value)}
                  style={{ width:42, height:42, border:'1.5px solid #e5e7eb', borderRadius:10, background:'#fff', cursor: isOwner ? 'pointer' : 'default', padding:2 }}
                />
                <input
                  className={`lp-brand-input ${errors.primary_color ? 'invalid' : ''}`}
                  value={form.primary_color}
                  disabled={!isOwner}
                  onChange={e => setField('primary_color', e.target.value.startsWith('#') ? e.target.value : '#' + e.target.value)}
                  placeholder="#0a6cc4"
                  style={{ maxWidth:140, fontFamily:'monospace', letterSpacing:'.04em' }}
                />
                <div style={{ display:'flex', gap:4, marginLeft:'auto' }}>
                  {[['', accentVar], ['-dark', shade(accentVar, -0.12)], ['-light', shade(accentVar, 0.42)]].map(([t, c]) => (
                    <div key={t} title={`--primary${t}`} style={{ width:26, height:26, borderRadius:6, background:c, boxShadow:'0 0 0 1px #e5e7eb' }} />
                  ))}
                </div>
              </div>
            </Field>
          </div>

          {/* Contact & domain */}
          <div className="lp-brand-card">
            <div style={{ fontSize:15, fontWeight:800, color:'#111827', marginBottom:'1.1rem' }}>Contact & domain</div>

            <Field label="Support email" hint="Where your customers reach you — shown in emails and help links." error={errors.support_email}>
              <input
                className={`lp-brand-input ${errors.support_email ? 'invalid' : ''}`}
                value={form.support_email}
                disabled={!isOwner}
                onChange={e => setField('support_email', e.target.value)}
                placeholder="support@yourbrand.com"
              />
            </Field>

            <Field
              label="Custom domain"
              hint="Serve the app from your own domain. After saving, point a CNAME record for this hostname at app.travelpilot.io, then contact support to issue the SSL certificate."
            >
              <input
                className="lp-brand-input"
                value={form.custom_domain}
                disabled={!isOwner}
                onChange={e => setField('custom_domain', e.target.value)}
                placeholder="app.yourbrand.com"
              />
            </Field>
          </div>

          {isOwner && (
            <button
              onClick={handleResetDefaults}
              style={{ background:'none', border:'none', color:'#6b7280', fontSize:13, cursor:'pointer', textDecoration:'underline', padding:0 }}
            >
              Reset to TripSarthi defaults
            </button>
          )}
        </div>

        {/* ── Preview ── */}
        <LivePreview form={form} />
      </div>

      {/* Sticky save bar */}
      {isOwner && dirty && (
        <div style={{
          position:'fixed', bottom:0, left:228, right:0, zIndex:50,
          background:'rgba(255,255,255,.92)', backdropFilter:'blur(8px)', borderTop:'1px solid #e5e7eb',
          padding:'.85rem 1.75rem', display:'flex', alignItems:'center', justifyContent:'flex-end', gap:'.75rem',
          animation:'lp-brand-bar .22s ease',
        }}>
          <span style={{ marginRight:'auto', fontSize:13, color:'#6b7280' }}>
            {Object.keys(errors).length > 0 ? 'Fix the highlighted fields to save.' : 'You have unsaved changes.'}
          </span>
          <button
            onClick={handleDiscard}
            disabled={saving}
            style={{ padding:'.6rem 1.1rem', borderRadius:10, border:'1.5px solid #e5e7eb', background:'#fff', color:'#374151', fontWeight:600, fontSize:14, cursor:'pointer' }}
          >
            Discard
          </button>
          <button
            onClick={handleSave}
            disabled={!canSave}
            style={{
              padding:'.6rem 1.4rem', borderRadius:10, border:'none',
              background: canSave ? `linear-gradient(135deg, ${accentVar}, ${shade(accentVar,-0.12)})` : '#c7cdd6',
              color:'#fff', fontWeight:700, fontSize:14, cursor: canSave ? 'pointer' : 'not-allowed',
              display:'flex', alignItems:'center', gap:'.45rem', boxShadow: canSave ? `0 2px 10px ${rgba(accentVar,.4)}` : 'none',
            }}
          >
            {saving
              ? <><span style={{ width:14, height:14, border:'2px solid rgba(255,255,255,.4)', borderTopColor:'#fff', borderRadius:'50%', animation:'lp-brand-spin .7s linear infinite' }}/> Saving…</>
              : <><Check size={15} strokeWidth={2} /> Save changes</>}
          </button>
        </div>
      )}
    </div>
  )
}
