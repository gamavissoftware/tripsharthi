import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Plus, Trash2, Send, Clock, Link2, AlertTriangle, RefreshCw,
  CalendarClock, CheckCircle2, XCircle, Share2, Image as ImageIcon,
  Smile, Upload,
} from 'lucide-react'

// lucide dropped brand icons — tiny inline glyphs instead
function FacebookGlyph({ size = 14, style }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" style={{ color: '#1877f2', flexShrink: 0, ...style }}>
      <path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11.02 10.13 11.93v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.09 24 18.09 24 12.07z" />
    </svg>
  )
}

function InstagramGlyph({ size = 14, style }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ color: '#c13584', flexShrink: 0, ...style }}>
      <rect x="2" y="2" width="20" height="20" rx="5" ry="5" />
      <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" />
      <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" />
    </svg>
  )
}
import { social } from '../api/social'
import { toast } from '../components/Toast'

/**
 * Social Planner — schedule Facebook Page / Instagram Business posts.
 *
 * Connect a Page with Facebook Login (the token goes Meta → backend, never
 * through this page), compose text (+ optional public image URL), pick
 * platforms and a time. The backend cron (spark social:dispatch) publishes due
 * posts.
 *
 * Pasting a Page Access Token by hand still works — it is the only option on an
 * install with no Meta app of its own — but it is deliberately the secondary
 * path: hand-made tokens are usually short-lived, and a connection that dies
 * quietly two hours later is the exact failure this flow was built to end.
 */

const STATUS_BADGE = {
  scheduled:  'badge-paused',
  publishing: 'badge-contacted',
  published:  'badge-active',
  failed:     'badge-lost',
}

/**
 * Caption ceilings Meta enforces. Facebook's is generous enough that nobody
 * hits it; Instagram's 2,200 is a real wall, so show the tighter one whenever
 * Instagram is among the selected platforms.
 */
const LIMIT_FACEBOOK  = 63206
const LIMIT_INSTAGRAM = 2200

/** Kept small and inline on purpose — no emoji library, no CDN, no CSP risk. */
const EMOJI_GROUPS = [
  { name: 'Reactions', emoji: ['😀','😃','😄','😊','🙂','😉','😍','🥰','😎','🤩','🥳','😂','🤝','🙏','👏','👍','💪','🔥','✨','💯'] },
  { name: 'Business',  emoji: ['📈','📊','💼','🧾','💰','💳','🏆','🎯','🚀','🛠️','⚙️','🔧','📌','📢','📣','🗓️','⏰','✅','❗','⭐'] },
  { name: 'Commerce',  emoji: ['🛒','🛍️','🎁','🏷️','💥','🔖','📦','🚚','🏬','💡'] },
  { name: 'Contact',   emoji: ['📞','📱','💬','✉️','📧','🌐','📍','🔗','▶️','👉'] },
]

/** One-tap snippets for the shapes every promo caption ends up needing. */
const QUICK_INSERTS = [
  { label: '• Bullet',  text: '\n• ',        title: 'Start a bullet point on a new line' },
  { label: '#Hashtag',  text: ' #',          title: 'Insert a hashtag' },
  { label: 'Line break', text: '\n\n',       title: 'Insert a blank line' },
]

function PlatformIcon({ platform, size = 14 }) {
  return platform === 'instagram'
    ? <InstagramGlyph size={size} />
    : <FacebookGlyph size={size} />
}

function fmtWhen(s) {
  if (!s) return '—'
  // The backend runs on UTC (Config\App::$appTimezone) and stores naive UTC
  // strings. Without the 'Z' the browser reads them as local time and every
  // scheduled post displays shifted by the viewer's offset.
  const d = new Date(s.replace(' ', 'T') + 'Z')
  return d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

export default function SocialPage() {
  const [accounts, setAccounts] = useState([])
  const [posts, setPosts]       = useState([])
  const [loading, setLoading]   = useState(true)

  // Connect form (manual fallback)
  const [showConnect, setShowConnect] = useState(false)
  const [pageId, setPageId]           = useState('')
  const [pageToken, setPageToken]     = useState('')
  const [connecting, setConnecting]   = useState(false)

  // Facebook Login
  const [oauth, setOauth]             = useState({ configured: false, redirect_uri: '' })
  const [starting, setStarting]       = useState(false)
  const [pickerState, setPickerState] = useState('')   // the ?social_oauth_state we came back with
  const [pickerPages, setPickerPages] = useState([])
  const [pickerFilter, setPickerFilter] = useState('') // narrows a long Page list
  const [picking, setPicking]         = useState('')   // page_id currently being connected
  const [searchParams, setSearchParams] = useSearchParams()

  // Composer
  const [accountId, setAccountId]   = useState('')
  const [platforms, setPlatforms]   = useState({ facebook: true, instagram: false })
  const [message, setMessage]       = useState('')
  const [imageUrl, setImageUrl]     = useState('')
  const [imageMode, setImageMode]   = useState('upload') // 'upload' | 'url'
  const [uploading, setUploading]   = useState(false)
  const [showEmoji, setShowEmoji]   = useState(false)
  const [when, setWhen]             = useState('')
  const [saving, setSaving]         = useState(false)

  useEffect(() => { load() }, [])

  // Is Facebook Login usable on this install at all?
  useEffect(() => {
    social.oauthStatus()
      .then(r => setOauth(r.data ?? { configured: false }))
      .catch(() => setOauth({ configured: false, redirect_uri: '' }))
  }, [])

  // Coming back from Meta. The backend put either a state or an error on the
  // URL; consume it, strip it (so a refresh doesn't replay the flow), and open
  // the page picker.
  useEffect(() => {
    const err   = searchParams.get('social_oauth_error')
    const state = searchParams.get('social_oauth_state')
    if (!err && !state) return

    setSearchParams({}, { replace: true })

    if (err) { toast.error('Facebook connection failed', err); return }

    setPickerState(state)
    social.oauthPages(state)
      .then(r => {
        const pages = r.data ?? []
        setPickerPages(pages)
        if (pages.length === 0) toast.error('No Pages found on that Facebook account')
      })
      .catch(e => { setPickerState(''); toast.error('Could not list your Pages', e.message) })
  }, [searchParams, setSearchParams])

  async function load() {
    setLoading(true)
    try {
      const [a, p] = await Promise.all([social.accounts(), social.posts()])
      setAccounts(a.data ?? [])
      setPosts(p.data ?? [])
      if ((a.data ?? []).length && !accountId) setAccountId(String(a.data[0].id))
    } catch (err) {
      toast.error('Failed to load Social Planner', err.message)
    }
    setLoading(false)
  }

  async function handleConnect(e) {
    e.preventDefault()
    setConnecting(true)
    try {
      const r = await social.connect({ page_id: pageId.trim(), access_token: pageToken.trim() })
      toast.success('Page connected', r.data.page_name)
      setShowConnect(false); setPageId(''); setPageToken('')
      load()
    } catch (err) {
      toast.error('Connection failed', err.message)
    }
    setConnecting(false)
  }

  // Hand the browser to Meta's consent dialog. Nothing is stored until the
  // user comes back and picks a page.
  async function handleOauthStart() {
    setStarting(true)
    try {
      const r = await social.oauthStart()
      window.location.href = r.data.auth_url
    } catch (err) {
      toast.error('Could not start Facebook login', err.message)
      setStarting(false)
    }
  }

  // Insert at the caret rather than appending, so an emoji can land mid
  // sentence. Restores focus and selection afterwards or the next insert
  // jumps back to the end.
  function insertAtCursor(text) {
    const el = document.getElementById('social-message')
    if (!el) { setMessage(m => m + text); return }

    const start = el.selectionStart ?? message.length
    const end   = el.selectionEnd ?? message.length
    const next  = message.slice(0, start) + text + message.slice(end)

    setMessage(next)
    requestAnimationFrame(() => {
      el.focus()
      const caret = start + text.length
      el.setSelectionRange(caret, caret)
    })
  }

  async function handleUpload(file) {
    if (!file) return
    setUploading(true)
    try {
      const r = await social.uploadMedia(file)
      setImageUrl(r.data.url)
      toast.success('Creative uploaded', file.name)
    } catch (err) {
      toast.error('Upload failed', err.message)
    }
    setUploading(false)
  }

  async function handlePickPage(page) {
    setPicking(page.page_id)
    try {
      const r = await social.oauthSelect(pickerState, page.page_id)
      toast.success('Page connected', r.data.page_name)
      setPickerState(''); setPickerPages([]); setPickerFilter('')
      load()
    } catch (err) {
      toast.error('Could not connect that Page', err.message)
    }
    setPicking('')
  }

  async function handleRemoveAccount(a) {
    if (!window.confirm(`Disconnect "${a.page_name}"? Scheduled posts for it will fail.`)) return
    try {
      await social.removeAccount(a.id)
      toast.success('Page disconnected', a.page_name)
      load()
    } catch (err) {
      toast.error('Disconnect failed', err.message)
    }
  }

  async function handleSchedule(e, now = false) {
    e.preventDefault()
    const chosen = Object.keys(platforms).filter(k => platforms[k])
    if (!accountId)        return toast.error('Pick a connected page first')
    if (!chosen.length)    return toast.error('Pick at least one platform')
    if (!message.trim())   return toast.error('Write a message first')
    if (!now && !when)     return toast.error('Pick a date & time')

    setSaving(true)
    try {
      await social.createPost({
        social_account_id: Number(accountId),
        platforms: chosen,
        message: message.trim(),
        image_url: imageUrl.trim() || null,
        // Send an absolute instant. <input type="datetime-local"> yields a bare
        // wall clock ("2026-09-18T13:13") with no zone; sent as-is the backend
        // read it as UTC, so a post scheduled from IST landed 5.5 hours late.
        // new Date(when) parses it in the browser's zone, toISOString converts.
        scheduled_at: (now ? new Date() : new Date(when)).toISOString(),
      })
      toast.success(now ? 'Publishing shortly' : 'Post scheduled', `${chosen.length} platform(s)`)
      setMessage(''); setImageUrl(''); setWhen('')
      load()
    } catch (err) {
      toast.error('Scheduling failed', err.message)
    }
    setSaving(false)
  }

  async function handleRemovePost(p) {
    if (p.status === 'scheduled' && !window.confirm('Remove this scheduled post?')) return
    try {
      await social.removePost(p.id)
      load()
    } catch (err) {
      toast.error('Delete failed', err.message)
    }
  }

  const account   = accounts.find(a => String(a.id) === String(accountId))
  const igBlocked = platforms.instagram && account && !account.ig_user_id
  const limit = platforms.instagram ? LIMIT_INSTAGRAM : LIMIT_FACEBOOK

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <h1 className="page-title">Social Planner</h1>
          <p className="text-muted mt-1">Schedule posts to your Facebook Page and Instagram Business account.</p>
        </div>
        <div className="flex items-center gap-2">
          {oauth.configured ? (
            <>
              <button className="btn btn-primary" onClick={handleOauthStart} disabled={starting}>
                <FacebookGlyph size={15} style={{ color: 'currentColor' }} />
                {starting ? 'Opening Facebook…' : 'Connect with Facebook'}
              </button>
              <button className="btn btn-ghost btn-sm" onClick={() => setShowConnect(true)}
                      title="Paste a Page Access Token instead">
                Use a token
              </button>
            </>
          ) : (
            <button className="btn btn-primary" onClick={() => setShowConnect(true)}>
              <Plus size={15} strokeWidth={2} /> Connect Page
            </button>
          )}
        </div>
      </div>

      {/* ── Dead-credential warning ──
          A page whose token Graph has rejected will fail every post from now
          on. Say so once, here, instead of letting the user discover it one
          failed post at a time. */}
      {accounts.some(a => a.status === 'reauth_required') && (
        <div className="card mb-4" style={{ borderColor: 'var(--danger, #dc2626)' }}>
          <div className="card-body flex items-start gap-3">
            <AlertTriangle size={18} strokeWidth={2} style={{ color: 'var(--danger, #dc2626)', flexShrink: 0, marginTop: 2 }} />
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 600, fontSize: '.875rem' }}>
                {accounts.filter(a => a.status === 'reauth_required').length === 1
                  ? 'A connected Page needs reconnecting'
                  : 'Some connected Pages need reconnecting'}
              </div>
              <div className="text-muted" style={{ fontSize: '.8rem', marginTop: '.2rem' }}>
                Facebook has stopped accepting the stored access token, so scheduled posts
                for {accounts.filter(a => a.status === 'reauth_required').map(a => a.page_name).join(', ')} will
                keep failing until it is connected again.
              </div>
            </div>
            {oauth.configured && (
              <button className="btn btn-primary btn-sm" onClick={handleOauthStart} disabled={starting}>
                <RefreshCw size={13} strokeWidth={2} /> Reconnect
              </button>
            )}
          </div>
        </div>
      )}

      {/* ── Connected accounts ── */}
      <div className="card mb-4">
        <div className="card-body">
          <h3 style={{ fontSize: '.95rem', marginBottom: '.85rem' }}>Connected pages</h3>
          {accounts.length === 0 && !loading && (
            <div className="text-muted" style={{ display: 'flex', alignItems: 'center', gap: '.5rem' }}>
              <Share2 size={16} strokeWidth={1.8} />
              No pages connected yet — connect a Facebook Page to start scheduling.
            </div>
          )}
          <div className="flex flex-col gap-2">
            {accounts.map(a => (
              <div key={a.id} className="flex items-center gap-3" style={{ padding: '.5rem .25rem' }}>
                <PlatformIcon platform="facebook" size={16} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 600, fontSize: '.875rem', display: 'flex', alignItems: 'center', gap: '.45rem' }}>
                    {a.page_name}
                    {a.status === 'reauth_required' && <span className="badge badge-lost">reconnect needed</span>}
                    {a.connect_method === 'manual' && a.status !== 'reauth_required' && (
                      <span className="badge badge-draft" title="Connected with a hand-pasted token — these can expire without warning. Reconnect with Facebook to make it permanent.">
                        manual token
                      </span>
                    )}
                  </div>
                  <div className="text-muted" style={{ fontSize: '.75rem' }}>
                    Page {a.page_id}
                    {a.ig_user_id
                      ? <span style={{ marginLeft: '.6rem' }}><InstagramGlyph size={11} style={{ verticalAlign: '-1px' }} /> @{a.ig_username}</span>
                      : <span style={{ marginLeft: '.6rem' }}>no Instagram linked</span>}
                  </div>
                </div>
                <button className="btn btn-ghost btn-sm" onClick={() => handleRemoveAccount(a)} title="Disconnect">
                  <Trash2 size={14} strokeWidth={1.8} />
                </button>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* ── Composer ── */}
      <div className="card mb-4">
        <div className="card-body">
          <h3 style={{ fontSize: '.95rem', marginBottom: '.85rem' }}>New post</h3>
          <form onSubmit={e => handleSchedule(e, false)} className="flex flex-col gap-3">
            <div className="form-grid-2">
              <div className="form-group">
                <label className="form-label">Page</label>
                <select className="form-select" value={accountId} onChange={e => setAccountId(e.target.value)}>
                  <option value="">Select a connected page…</option>
                  {accounts.map(a => <option key={a.id} value={a.id}>{a.page_name}</option>)}
                </select>
              </div>
              <div className="form-group">
                <label className="form-label">Platforms</label>
                <div className="flex items-center gap-3" style={{ paddingTop: '.35rem' }}>
                  <label className="flex items-center gap-2" style={{ fontSize: '.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={platforms.facebook}
                           onChange={e => setPlatforms(p => ({ ...p, facebook: e.target.checked }))} />
                    <PlatformIcon platform="facebook" /> Facebook
                  </label>
                  <label className="flex items-center gap-2" style={{ fontSize: '.85rem', cursor: 'pointer' }}>
                    <input type="checkbox" checked={platforms.instagram}
                           onChange={e => setPlatforms(p => ({ ...p, instagram: e.target.checked }))} />
                    <PlatformIcon platform="instagram" /> Instagram
                  </label>
                </div>
              </div>
            </div>

            {igBlocked && (
              <div className="form-error">This page has no linked Instagram Business account, so Instagram posting isn't available for it.</div>
            )}

            <div className="form-group">
              <div className="flex items-center justify-between" style={{ marginBottom: '.35rem' }}>
                <label className="form-label" style={{ margin: 0 }}>Message</label>
                <span className="text-muted" style={{ fontSize: '.73rem' }}>
                  {message.length}{limit ? ` / ${limit}` : ''} characters
                </span>
              </div>

              <textarea id="social-message" className="form-input" rows={6} value={message}
                        onChange={e => setMessage(e.target.value)}
                        placeholder="What do you want to share?" />

              <div className="flex items-center gap-2" style={{ marginTop: '.4rem', flexWrap: 'wrap', position: 'relative' }}>
                <button type="button" className="btn btn-ghost btn-sm"
                        onClick={() => setShowEmoji(v => !v)}
                        title="Insert an emoji at the cursor">
                  <Smile size={14} /> Emoji
                </button>
                {QUICK_INSERTS.map(q => (
                  <button key={q.label} type="button" className="btn btn-ghost btn-sm"
                          title={q.title} onClick={() => insertAtCursor(q.text)}>
                    {q.label}
                  </button>
                ))}

                {showEmoji && (
                  <div style={{
                    position: 'absolute', top: '2.2rem', left: 0, zIndex: 20,
                    background: 'var(--surface)', border: '1px solid var(--border)',
                    borderRadius: 'var(--r-md)', boxShadow: 'var(--shadow-lg)',
                    padding: '.5rem', width: 302, maxHeight: 232, overflowY: 'auto',
                  }}>
                    {EMOJI_GROUPS.map(g => (
                      <div key={g.name} style={{ marginBottom: '.35rem' }}>
                        <div className="text-muted" style={{ fontSize: '.68rem', textTransform: 'uppercase', letterSpacing: '.04em', padding: '.15rem .15rem .25rem' }}>
                          {g.name}
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap' }}>
                          {g.emoji.map(e => (
                            <button key={e} type="button" title={e}
                                    onClick={() => { insertAtCursor(e); setShowEmoji(false) }}
                                    style={{
                                      fontSize: '1.15rem', lineHeight: 1, padding: '.2rem',
                                      width: 30, height: 30, border: 0, background: 'transparent',
                                      cursor: 'pointer', borderRadius: 6,
                                    }}>
                              {e}
                            </button>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="text-muted" style={{ fontSize: '.72rem', marginTop: '.4rem' }}>
                Facebook and Instagram captions are plain text — bold, italics and highlighting
                are not supported by either platform, so emoji and line breaks are what you have
                to work with. Line breaks are kept exactly as you type them.
              </div>
            </div>

            <div className="form-group">
              <label className="form-label">
                Creative {platforms.instagram ? '(required for Instagram)' : '(optional)'}
              </label>

              <div className="flex items-center gap-2" style={{ marginBottom: '.5rem' }}>
                <button type="button"
                        className={`btn btn-sm ${imageMode === 'upload' ? 'btn-primary' : 'btn-ghost'}`}
                        onClick={() => setImageMode('upload')}>
                  <Upload size={14} /> Upload
                </button>
                <button type="button"
                        className={`btn btn-sm ${imageMode === 'url' ? 'btn-primary' : 'btn-ghost'}`}
                        onClick={() => setImageMode('url')}>
                  <Link2 size={14} /> Paste a link
                </button>
              </div>

              {imageMode === 'upload' ? (
                <input className="form-input" type="file"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       disabled={uploading}
                       onChange={e => handleUpload(e.target.files?.[0])} />
              ) : (
                <input className="form-input" type="url" value={imageUrl}
                       onChange={e => setImageUrl(e.target.value)}
                       placeholder="https://…/image.jpg — must be publicly reachable" />
              )}

              {uploading && (
                <div className="text-muted" style={{ fontSize: '.75rem', marginTop: '.4rem' }}>Uploading…</div>
              )}

              {imageUrl && !uploading && (
                <div className="flex items-center gap-2" style={{ marginTop: '.5rem' }}>
                  <img src={imageUrl} alt="" onError={e => { e.currentTarget.style.display = 'none' }}
                       style={{ width: 56, height: 56, objectFit: 'cover', borderRadius: 'var(--r-sm)', border: '1px solid var(--border)' }} />
                  <div className="text-muted" style={{ fontSize: '.72rem', flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    {imageUrl}
                  </div>
                  <button type="button" className="btn btn-ghost btn-sm" onClick={() => setImageUrl('')}>
                    <Trash2 size={14} /> Remove
                  </button>
                </div>
              )}
            </div>

            <div className="form-group">
              <label className="form-label">Schedule for</label>
              <input className="form-input" type="datetime-local" value={when}
                     onChange={e => setWhen(e.target.value)} />
            </div>

            <div className="flex items-center gap-2">
              <button type="submit" className="btn btn-primary" disabled={saving || !accounts.length || igBlocked}>
                <CalendarClock size={15} strokeWidth={2} /> Schedule
              </button>
              <button type="button" className="btn btn-ghost" disabled={saving || !accounts.length || igBlocked}
                      onClick={e => handleSchedule(e, true)}>
                <Send size={15} strokeWidth={2} /> Post now
              </button>
            </div>
          </form>
        </div>
      </div>

      {/* ── Scheduled & past posts ── */}
      <div className="card">
        <div style={{ overflowX: 'auto' }}>
        <table className="data-table">
          <thead>
            <tr>
              <th>Platform</th><th>Message</th><th>Media</th><th>When</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            {posts.length === 0 && (
              <tr><td colSpan={6}>
                <div className="empty-state">
                  <span className="empty-state-icon"><CalendarClock size={40} strokeWidth={1.4} /></span>
                  <span className="empty-state-text">No posts yet — schedule your first one above.</span>
                </div>
              </td></tr>
            )}
            {posts.map(p => (
              <tr key={p.id} style={{ cursor: 'default' }}>
                <td><PlatformIcon platform={p.platform} size={16} /></td>
                <td style={{ maxWidth: 360 }}>
                  <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--text)' }}>{p.message}</div>
                  {p.status === 'failed' && p.error && (
                    <div style={{ fontSize: '.72rem', color: 'var(--danger)', marginTop: 2, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={p.error}>
                      <XCircle size={11} strokeWidth={2} style={{ verticalAlign: '-1.5px' }} /> {p.error}
                    </div>
                  )}
                  {p.status === 'published' && p.platform_post_id && (
                    <div style={{ fontSize: '.72rem', color: 'var(--success)', marginTop: 2 }}>
                      <CheckCircle2 size={11} strokeWidth={2} style={{ verticalAlign: '-1.5px' }} /> {p.platform_post_id}
                    </div>
                  )}
                </td>
                <td>
                  {p.image_url
                    ? <a href={p.image_url} target="_blank" rel="noreferrer" title={p.image_url}><ImageIcon size={15} strokeWidth={1.8} style={{ color: 'var(--text-2)' }} /></a>
                    : <span className="text-muted">—</span>}
                </td>
                <td className="cell-mono" style={{ whiteSpace: 'nowrap' }}>
                  <Clock size={12} strokeWidth={1.8} style={{ verticalAlign: '-1.5px', marginRight: 4, color: 'var(--text-3)' }} />
                  {fmtWhen(p.published_at ?? p.scheduled_at)}
                </td>
                <td><span className={`badge ${STATUS_BADGE[p.status] ?? 'badge-draft'}`}>{p.status}</span></td>
                <td style={{ textAlign: 'right' }}>
                  {p.status !== 'publishing' && (
                    <button className="btn btn-ghost btn-sm" onClick={() => handleRemovePost(p)} title="Delete">
                      <Trash2 size={13} strokeWidth={1.8} />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
      </div>

      {/* ── Page picker (after returning from Facebook) ── */}
      {pickerState && (() => {
        const connectedIds = new Set(accounts.map(a => String(a.page_id)))
        const q            = pickerFilter.trim().toLowerCase()
        const shown        = q
          ? pickerPages.filter(p => `${p.page_name} ${p.page_id}`.toLowerCase().includes(q))
          : pickerPages
        const closePicker = () => { setPickerState(''); setPickerPages([]); setPickerFilter('') }

        return (
        <div className="modal-backdrop">
          <div className="modal" style={{ width: 520 }} onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              Choose a Page to connect
              {pickerPages.length > 0 && (
                <div className="text-muted" style={{ fontSize: '.78rem', fontWeight: 400, marginTop: '.2rem' }}>
                  {pickerPages.length} {pickerPages.length === 1 ? 'Page' : 'Pages'} on this Facebook account
                  {q && ` · ${shown.length} matching`}
                </div>
              )}
            </div>
            <div className="modal-body flex flex-col gap-2">
              {pickerPages.length === 0 && (
                <div className="text-muted" style={{ fontSize: '.85rem' }}>Loading your Pages…</div>
              )}

              {/* Worth searching once there are more than a handful. */}
              {pickerPages.length > 6 && (
                <input className="form-input" type="search" autoFocus
                       placeholder="Search Pages by name or ID…"
                       value={pickerFilter}
                       onChange={e => setPickerFilter(e.target.value)}
                       style={{ marginBottom: '.25rem' }} />
              )}

              {pickerPages.length > 0 && shown.length === 0 && (
                <div className="text-muted" style={{ fontSize: '.82rem', padding: '.5rem 0' }}>
                  No Page matches “{pickerFilter}”.
                </div>
              )}

              {shown.map(p => {
                const already = connectedIds.has(String(p.page_id))
                return (
                  <button key={p.page_id} className="btn btn-ghost"
                          style={{ justifyContent: 'flex-start', textAlign: 'left', height: 'auto', padding: '.6rem .7rem' }}
                          disabled={picking !== ''}
                          title={already
                            ? `Reconnect ${p.page_name} — refreshes its token and permissions`
                            : `Connect ${p.page_name}`}
                          onClick={() => handlePickPage(p)}>
                    <FacebookGlyph size={16} />
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontWeight: 600, fontSize: '.85rem', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {p.page_name}
                      </div>
                      <div className="text-muted" style={{ fontSize: '.73rem' }}>
                        Page {p.page_id}
                        {p.ig_user_id
                          ? <span style={{ marginLeft: '.55rem' }}><InstagramGlyph size={11} style={{ verticalAlign: '-1px' }} /> @{p.ig_username}</span>
                          : <span style={{ marginLeft: '.55rem' }}>no Instagram linked</span>}
                      </div>
                    </div>
                    {already && picking !== p.page_id &&
                      <span className="text-muted" style={{ fontSize: '.73rem' }}>Connected · reconnect</span>}
                    {picking === p.page_id && <span className="text-muted" style={{ fontSize: '.75rem' }}>Connecting…</span>}
                  </button>
                )
              })}
            </div>
            <div className="modal-footer">
              <button type="button" className="btn btn-ghost" disabled={picking !== ''}
                      onClick={closePicker}>
                Cancel
              </button>
            </div>
          </div>
        </div>
        )
      })()}

      {/* ── Connect modal ── */}
      {showConnect && (
        <div className="modal-backdrop" onClick={() => setShowConnect(false)}>
          <div className="modal" onClick={e => e.stopPropagation()}>
            <div className="modal-header">Connect a Facebook Page</div>
            <form onSubmit={handleConnect}>
              <div className="modal-body flex flex-col gap-3">
                <div className="form-group">
                  <label className="form-label">Page ID</label>
                  <input className="form-input" value={pageId} onChange={e => setPageId(e.target.value)}
                         placeholder="e.g. 104291234567890" required />
                </div>
                <div className="form-group">
                  <label className="form-label">Page Access Token</label>
                  <input className="form-input" type="password" value={pageToken} onChange={e => setPageToken(e.target.value)}
                         placeholder="EAAG…" required />
                  <div className="form-hint">
                    <Link2 size={11} strokeWidth={2} style={{ verticalAlign: '-1.5px' }} /> Get one in Meta Business Suite →
                    Graph API Explorer (needs pages_manage_posts; instagram_content_publish for IG).
                    The token is validated, then stored encrypted. If the page has a linked Instagram
                    Business account it's detected automatically.
                  </div>
                  <div className="form-hint" style={{ marginTop: '.4rem', color: 'var(--danger, #dc2626)' }}>
                    <AlertTriangle size={11} strokeWidth={2} style={{ verticalAlign: '-1.5px' }} /> The
                    Explorer hands out a <strong>short-lived</strong> token by default — it will stop working
                    in an hour or two and posts will start failing. Extend it in the Access Token Debugger
                    first{oauth.configured ? ', or use Connect with Facebook, which never expires.' : '.'}
                  </div>
                </div>
              </div>
              <div className="modal-footer">
                <button type="button" className="btn btn-ghost" onClick={() => setShowConnect(false)}>Cancel</button>
                <button type="submit" className="btn btn-primary" disabled={connecting}>
                  {connecting ? 'Validating…' : 'Connect'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
