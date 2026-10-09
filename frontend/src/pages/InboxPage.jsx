import { useState, useEffect, useRef, useCallback } from 'react'
import {
  MessageCircle, Hourglass, Mail, Image as ImageIcon, FileText, Film, Music,
  X, Paperclip, CreditCard, Sparkles, Smile, Send, AlertTriangle,
} from 'lucide-react'
import { inbox as inboxApi } from '../api/inbox'
import { contacts as contactsApi } from '../api/contacts'
import { payments as paymentsApi } from '../api/payments'
import { ai as aiApi } from '../api/ai'
import { toast } from '../components/Toast'
import { api } from '../api/client'

// DB stores timestamps in UTC (PHP timezone = UTC).
// Append 'Z' so JavaScript's Date() correctly treats them as UTC
// and toLocaleTimeString() converts to the browser's local timezone (e.g. IST).
function parseDbDate(isoString) {
  if (!isoString) return null
  // Already has timezone info → use as-is
  if (isoString.includes('Z') || isoString.includes('+') || isoString.includes('-', 10)) {
    return new Date(isoString)
  }
  // MySQL "YYYY-MM-DD HH:MM:SS" without timezone → treat as UTC
  return new Date(isoString.replace(' ', 'T') + 'Z')
}

function formatTime(isoString) {
  if (!isoString) return ''
  const d = parseDbDate(isoString)
  if (!d || isNaN(d.getTime())) return ''
  const now = new Date()
  const diffDays = Math.floor((now - d) / 86400000)
  if (diffDays === 0) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (diffDays === 1) return 'Yesterday'
  if (diffDays < 7) return d.toLocaleDateString([], { weekday: 'short' })
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function formatWindowBadge(secondsRemaining) {
  if (!secondsRemaining || secondsRemaining <= 0) return null
  const h = Math.floor(secondsRemaining / 3600)
  const m = Math.floor((secondsRemaining % 3600) / 60)
  return `${h}h ${m}m`
}

function formatWindowCountdown(secondsRemaining) {
  if (!secondsRemaining || secondsRemaining <= 0) return null
  const h = Math.floor(secondsRemaining / 3600)
  const m = Math.floor((secondsRemaining % 3600) / 60)
  const s = secondsRemaining % 60
  if (h > 0) return `${h}h ${m}m ${s}s`
  if (m > 0) return `${m}m ${s}s`
  return `${s}s`
}

function groupMessagesByDate(messages) {
  const groups = []
  let currentDate = null
  let currentGroup = null
  for (const msg of messages) {
    const d = parseDbDate(msg.created_at)
    if (!d || isNaN(d.getTime())) continue
    const dateKey = d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })
    if (dateKey !== currentDate) {
      currentDate = dateKey
      currentGroup = { date: dateKey, messages: [] }
      groups.push(currentGroup)
    }
    currentGroup.messages.push(msg)
  }
  return groups
}

function StatusIcon({ status }) {
  if (status === 'read') return <span style={{ color: '#0a6cc4', fontSize: '.75rem' }}>✓✓</span>
  if (status === 'delivered') return <span style={{ color: '#94a3b8', fontSize: '.75rem' }}>✓✓</span>
  if (status === 'sent') return <span style={{ color: '#94a3b8', fontSize: '.75rem' }}>✓</span>
  if (status === 'failed') return <span style={{ color: '#ef4444', fontSize: '.75rem' }}>✗</span>
  return null
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

function getMediaType(mimeType) {
  if (!mimeType) return 'document'
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('video/')) return 'video'
  if (mimeType.startsWith('audio/')) return 'audio'
  return 'document'
}

// Body-variable names for a template. `variables` is stored as a JSON string
// (e.g. '["contact_name","product_name"]'); fall back to counting {{n}} in body.
function templateVarNames(tpl) {
  if (!tpl) return []
  let v = tpl.variables
  if (typeof v === 'string') { try { v = JSON.parse(v) } catch { v = null } }
  if (Array.isArray(v) && v.length) return v.map(String)
  const nums = [...String(tpl.body || '').matchAll(/\{\{(\d+)\}\}/g)].map(m => Number(m[1]))
  const max  = nums.length ? Math.max(...nums) : 0
  return Array.from({ length: max }, (_, i) => `Variable ${i + 1}`)
}

export default function InboxPage() {
  const [conversations, setConversations] = useState([])
  const [filteredConvs, setFilteredConvs] = useState([])
  const [activeId, setActiveId] = useState(null)
  // Phones: show the list OR the thread, never both side by side.
  const [narrow, setNarrow] = useState(() => typeof window !== 'undefined' && window.innerWidth < 768)
  useEffect(() => {
    const onResize = () => setNarrow(window.innerWidth < 768)
    window.addEventListener('resize', onResize)
    return () => window.removeEventListener('resize', onResize)
  }, [])
  const [activeConv, setActiveConv] = useState(null)
  const [messages, setMessages] = useState([])
  const [searchQ, setSearchQ] = useState('')
  const [statusTab, setStatusTab] = useState('all')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [catList, setCatList] = useState([])
  const [loadingList, setLoadingList] = useState(true)
  const [loadingMessages, setLoadingMessages] = useState(false)
  const [replyText, setReplyText] = useState('')
  const [sending, setSending] = useState(false)
  const [sendError, setSendError] = useState(null)
  const [windowClosed, setWindowClosed] = useState(false)
  const [liveSeconds, setLiveSeconds] = useState(null)
  const [mediaFile, setMediaFile] = useState(null)   // { media_id, type, filename, mime_type }
  const [uploading, setUploading] = useState(false)

  // Template picker state
  const [showTemplatePicker, setShowTemplatePicker] = useState(false)
  const [templates, setTemplates] = useState([])
  const [selectedTpl, setSelectedTpl] = useState(null)
  const [tplVars, setTplVars] = useState([])   // body-variable values for the selected template
  const [tplLoading, setTplLoading] = useState(false)
  const [tplListLoading, setTplListLoading] = useState(false)
  const [tplListError, setTplListError]   = useState(null)

  const [showEmojiPicker, setShowEmojiPicker] = useState(false)

  const [showPayModal, setShowPayModal] = useState(false)
  const [payForm, setPayForm] = useState({ amount: '', description: '' })
  const [payBusy, setPayBusy] = useState(false)

  const messagesEndRef = useRef(null)
  const pollRef = useRef(null)
  const listPollRef = useRef(null)
  const activeIdRef = useRef(null)
  const categoryRef = useRef('')
  const countdownRef = useRef(null)
  const fileInputRef = useRef(null)
  const textareaRef = useRef(null)
  const emojiPickerRef = useRef(null)

  // Keep a ref of the open conversation so the list-poll interval (set up once)
  // always reads the current selection instead of a stale closure value.
  useEffect(() => { activeIdRef.current = activeId }, [activeId])

  useEffect(() => {
    contactsApi.categories().then(r => setCatList(r.data ?? [])).catch(() => {})
  }, [])

  // Load conversation list on mount
  useEffect(() => {
    loadList()
  }, [])

  // Filter conversations when search or tab changes
  useEffect(() => {
    let list = conversations
    if (statusTab !== 'all') {
      list = list.filter(c => c.status === statusTab)
    }
    if (searchQ.trim()) {
      const q = searchQ.toLowerCase()
      // Company and category are shown on each row, so they should be
      // searchable too — otherwise typing a company name you can see on
      // screen returns nothing.
      list = list.filter(c =>
        (c.contact_name || '').toLowerCase().includes(q) ||
        (c.wa_number || '').includes(q) ||
        (c.company_name || '').toLowerCase().includes(q) ||
        (c.contact_category || '').toLowerCase().includes(q)
      )
    }
    setFilteredConvs(list)
  }, [conversations, statusTab, searchQ])

  // Scroll to bottom when messages change
  useEffect(() => {
    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' })
    }
  }, [messages])

  // Real-time updates via polling.
  //
  // SSE was removed deliberately: a long-lived `/stream` request pins PHP's
  // single worker under `spark serve` (and one PHP-FPM worker per open inbox in
  // production), which is exactly why sending hung and inbound messages didn't
  // appear. Short-interval polling is stateless, holds no worker, and behaves
  // identically in dev and prod.
  //
  // Open conversation → poll messages every 2s for a snappy thread.
  useEffect(() => {
    if (pollRef.current) clearInterval(pollRef.current)
    if (!activeId) return
    pollRef.current = setInterval(() => {
      loadMessages(activeId, false)
    }, 2000)
    return () => clearInterval(pollRef.current)
  }, [activeId])

  // Conversation list → poll every 4s so new inbound on ANY chat surfaces
  // (unread badge, reordering, last-message preview) like a live notification,
  // and the open chat's 24h-window state stays fresh.
  useEffect(() => {
    if (listPollRef.current) clearInterval(listPollRef.current)
    listPollRef.current = setInterval(() => { loadConversations() }, 4000)
    return () => clearInterval(listPollRef.current)
  }, [])

  // Live countdown timer
  useEffect(() => {
    if (countdownRef.current) clearInterval(countdownRef.current)
    if (!activeConv || !activeConv.seconds_remaining || activeConv.seconds_remaining <= 0) {
      setLiveSeconds(0)
      return
    }
    setLiveSeconds(activeConv.seconds_remaining)
    countdownRef.current = setInterval(() => {
      setLiveSeconds(s => {
        if (s <= 1) {
          clearInterval(countdownRef.current)
          return 0
        }
        return s - 1
      })
    }, 1000)
    return () => clearInterval(countdownRef.current)
  }, [activeConv])

  // Load approved templates. Always refetch (don't cache forever) so newly
  // approved templates appear, and surface failures (e.g. a rate-limited
  // request) instead of silently leaving the picker empty.
  const loadApprovedTemplates = useCallback(async () => {
    setTplListLoading(true); setTplListError(null)
    try {
      const r = await api.get('/templates?meta_status=approved')
      setTemplates(r.data ?? [])
    } catch (err) {
      setTplListError(err.message ?? 'Failed to load templates.')
    } finally {
      setTplListLoading(false)
    }
  }, [])

  // Fetch once on mount so the list is ready, and again each time the picker opens.
  useEffect(() => { loadApprovedTemplates() }, [loadApprovedTemplates])
  useEffect(() => { if (showTemplatePicker) loadApprovedTemplates() }, [showTemplatePicker, loadApprovedTemplates])

  // Reset variable inputs whenever the selected template changes; pre-fill a
  // name-like first variable with the contact's name for convenience.
  useEffect(() => {
    const names = templateVarNames(selectedTpl)
    setTplVars(names.map((n, i) =>
      (i === 0 && /name/i.test(n) && activeConv?.contact_name) ? activeConv.contact_name : ''
    ))
    setSendError(null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedTpl])

  // Close emoji picker on outside click
  useEffect(() => {
    if (!showEmojiPicker) return
    function handleClick(e) {
      if (emojiPickerRef.current && !emojiPickerRef.current.contains(e.target)) {
        setShowEmojiPicker(false)
      }
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [showEmojiPicker])

  function insertEmoji(emoji) {
    const ta = textareaRef.current
    if (!ta) {
      setReplyText(t => t + emoji)
      return
    }
    const start = ta.selectionStart ?? replyText.length
    const end   = ta.selectionEnd   ?? replyText.length
    const newText = replyText.slice(0, start) + emoji + replyText.slice(end)
    setReplyText(newText)
    // Restore cursor position after React re-render
    requestAnimationFrame(() => {
      ta.focus()
      const pos = start + emoji.length
      ta.setSelectionRange(pos, pos)
    })
  }

  async function loadList() {
    setLoadingList(true)
    try {
      const res = await inboxApi.list(categoryRef.current ? { category: categoryRef.current } : {})
      setConversations(res.data ?? [])
    } catch (err) {
      setConversations([])
    }
    setLoadingList(false)
  }

  // Refresh the list and keep the OPEN chat's 24h-window state in sync (each list
  // entry carries send_mode + seconds_remaining), so inbound that reopens the
  // window flips the composer back to free-form without a manual reload.
  /**
   * Category is filtered server-side: the list is paginated, so filtering the
   * fetched page in the browser would only ever search the newest 25 threads.
   *
   * The filter is read from a ref at call time because the 4s poll is
   * registered once and would otherwise keep re-fetching with the value
   * captured on first render.
   *
   * Set the ref synchronously here so the refetch below already sees it.
   */
  function changeCategory(value) {
    categoryRef.current = value
    setCategoryFilter(value)
    loadList()
  }

  function loadConversations() {
    inboxApi.list(categoryRef.current ? { category: categoryRef.current } : {}).then(res => {
      const list = res.data ?? []
      setConversations(list)
      const curId = activeIdRef.current
      if (curId) {
        const fresh = list.find(c => c.id === curId)
        if (fresh) {
          setActiveConv(prev => (prev ? { ...prev, ...fresh } : fresh))
          setWindowClosed(fresh.send_mode === 'template_only')
          if (typeof fresh.seconds_remaining === 'number') setLiveSeconds(fresh.seconds_remaining)
        }
      }
    }).catch(() => {})
  }

  async function selectConversation(conv) {
    setActiveId(conv.id)
    setActiveConv(conv)
    setSendError(null)
    setWindowClosed(conv.send_mode === 'template_only')
    setMediaFile(null)
    setShowTemplatePicker(false)
    setSelectedTpl(null)
    await loadMessages(conv.id, true)
    // Refresh full conv details
    try {
      const res = await inboxApi.get(conv.id)
      if (res.data) {
        setActiveConv(res.data)
        setWindowClosed(res.data.send_mode === 'template_only')
      }
    } catch (_) {}
    // Mark as read — fire-and-forget, don't block UI
    if (!conv.is_read) {
      inboxApi.markRead(conv.id).catch(() => {})
      setConversations(list => list.map(c => c.id === conv.id ? { ...c, is_read: 1 } : c))
    }
  }

  async function loadMessages(id, showLoader) {
    if (showLoader) setLoadingMessages(true)
    try {
      const res = await inboxApi.messages(id)
      const server = res.data ?? []
      setMessages(prev => {
        // Preserve any optimistic (still-sending) bubbles the server hasn't
        // returned yet, so polling never makes a just-sent message flicker out.
        const pending = prev.filter(m => m._pending)
        const prevReal = prev.filter(m => !m._pending)
        const sameTail =
          prevReal.length === server.length &&
          server.length > 0 &&
          prevReal[prevReal.length - 1]?.id === server[server.length - 1]?.id
        if (sameTail && pending.length === 0) return prev   // no change → no re-render
        return [...server, ...pending]
      })
    } catch (_) {}
    if (showLoader) setLoadingMessages(false)
  }

  async function handleFileChange(e) {
    const file = e.target.files?.[0]
    if (!file || !activeId) return
    // Reset input value so same file can be re-selected
    e.target.value = ''

    const mime = file.type
    const type = getMediaType(mime)

    setUploading(true)
    setMediaFile({ media_id: null, type, filename: file.name, mime_type: mime })
    setSendError(null)

    try {
      const token = localStorage.getItem('tp_token')
      const form = new FormData()
      form.append('file', file)
      const res = await fetch(`/api/v1/inbox/${activeId}/upload-media`, {
        method: 'POST',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
        body: form,
      }).then(r => r.json())

      // Backend returns {success, media_id, filename, mime_type} — NOT nested under data
      const mediaId = res.media_id ?? res.data?.media_id
      if (res.success && mediaId) {
        setMediaFile({
          media_id: mediaId,
          type,
          filename: res.filename ?? res.data?.filename ?? file.name,
          mime_type: res.mime_type ?? res.data?.mime_type ?? mime,
        })
      } else {
        setSendError(res.error ?? res.message ?? 'File upload failed.')
        setMediaFile(null)
      }
    } catch (err) {
      setSendError(err.message ?? 'File upload failed.')
      setMediaFile(null)
    }
    setUploading(false)
  }

  function handleRemoveMedia() {
    setMediaFile(null)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const [suggesting, setSuggesting] = useState(false)
  async function handleSuggest() {
    if (!activeId) return
    setSuggesting(true)
    try {
      const res = await aiApi.suggest(activeId)
      const text = res.data?.suggestion ?? ''
      if (text) {
        setReplyText(prev => prev ? `${prev}\n${text}` : text)
        toast.success('AI draft ready', 'Review and edit before sending.')
      } else {
        toast.error('No suggestion returned', 'Try again in a moment.')
      }
    } catch (err) {
      const msg = err?.message ?? ''
      if (/not configured|no anthropic key|api key/i.test(msg)) {
        toast.error('AI isn’t set up yet', 'Add your Anthropic API key in Settings → AI Assistant to enable suggested replies.')
      } else if (/limit/i.test(msg)) {
        toast.error('AI usage limit reached', msg)
      } else {
        toast.error('Could not generate a suggestion', msg)
      }
    }
    setSuggesting(false)
  }

  async function handleSendPayment() {
    const amount = parseFloat(payForm.amount)
    if (!activeConv?.contact_id) { toast.error('No contact linked to this conversation'); return }
    if (!amount || amount <= 0) { toast.error('Enter a valid amount'); return }
    setPayBusy(true)
    try {
      const res = await paymentsApi.createLink({
        contact_id: activeConv.contact_id,
        amount,
        description: payForm.description || 'Payment request',
      })
      if (res.delivered) toast.success('Payment link sent', `₹${amount} requested on WhatsApp.`)
      else toast.info?.('Payment link created', res.message || 'Window closed — copy the link from Payments.')
      setShowPayModal(false)
      setPayForm({ amount: '', description: '' })
      if (activeId) loadMessages(activeId, true)
    } catch (err) {
      if (err?.message?.includes('not connected')) toast.error('Connect Razorpay first', 'Settings → Payments')
      else toast.error('Could not create payment link', err?.message)
    }
    setPayBusy(false)
  }

  async function handleSend() {
    if (!activeId) return
    if (windowClosed) return
    const hasMedia = mediaFile && mediaFile.media_id
    const hasText = replyText.trim()
    if (!hasMedia && !hasText) return

    const convId  = activeId
    const tempId  = `temp-${Date.now()}`
    const textToSend = hasText

    // Optimistic bubble for text replies — appears instantly so the agent gets
    // immediate feedback even though the Meta round-trip takes a moment.
    if (!hasMedia) {
      setMessages(prev => [...prev, {
        id: tempId, _pending: true, direction: 'out', type: 'text',
        body: textToSend, status: 'sending', created_at: new Date().toISOString(),
      }])
      setReplyText('')   // clear composer right away
    }

    setSending(true)
    setSendError(null)
    try {
      let payload
      if (hasMedia) {
        payload = {
          type: mediaFile.type,
          media_id: mediaFile.media_id,
          caption: textToSend || undefined,
          filename: mediaFile.filename,
        }
      } else {
        payload = { type: 'text', body: textToSend }
      }

      const res = await inboxApi.send(convId, payload)
      if (res.code === 'WINDOW_CLOSED') {
        setWindowClosed(true)
        setSendError('Window closed — only templates can be sent.')
        setMessages(prev => prev.filter(m => m.id !== tempId))
        if (!hasMedia) setReplyText(textToSend)   // restore so it isn't lost
      } else if (res.success) {
        setMediaFile(null)
        if (fileInputRef.current) fileInputRef.current.value = ''
        // Drop the optimistic bubble; the real message comes from the server.
        setMessages(prev => prev.filter(m => m.id !== tempId))
        await loadMessages(convId, false)
        loadList()   // update last-message preview in the list
      } else {
        setSendError(res.message ?? 'Failed to send message.')
        setMessages(prev => prev.map(m => m.id === tempId ? { ...m, status: 'failed', _pending: false } : m))
      }
    } catch (err) {
      setSendError(err.message ?? 'Failed to send message.')
      setMessages(prev => prev.map(m => m.id === tempId ? { ...m, status: 'failed', _pending: false } : m))
    }
    setSending(false)
  }

  async function handleSendTemplate() {
    if (!selectedTpl || !activeId) return
    const names = templateVarNames(selectedTpl)
    // A template with body variables needs every value, or Meta rejects it.
    if (names.length && names.some((_, i) => !(tplVars[i] || '').trim())) {
      setSendError(`This template has ${names.length} variable(s) — fill them all before sending.`)
      return
    }
    setTplLoading(true)
    setSendError(null)
    try {
      const res = await inboxApi.send(activeId, {
        type: 'template',
        template_name: selectedTpl.name,
        language: selectedTpl.language ?? 'en',
        template_id: selectedTpl.id,
        variables: names.map((_, i) => (tplVars[i] ?? '').trim()),
      })
      // The API returns {success:false, error} (HTTP 200/422) on a Meta reject.
      if (res && res.success === false) {
        setSendError(res.error ?? res.message ?? 'Failed to send template.')
        return
      }
      setShowTemplatePicker(false)
      setSelectedTpl(null)
      setTplVars([])
      await loadMessages(activeId, false)
    } catch (err) {
      setSendError(err.message ?? 'Failed to send template.')
    } finally {
      setTplLoading(false)
    }
  }

  async function handleResolve() {
    if (!activeId) return
    try {
      await inboxApi.resolve(activeId)
      setActiveConv(c => ({ ...c, status: 'resolved' }))
      setConversations(list => list.map(c => c.id === activeId ? { ...c, status: 'resolved' } : c))
    } catch (err) {
      setSendError(err.message ?? 'Failed.')
    }
  }

  async function handleReopen() {
    if (!activeId) return
    try {
      await inboxApi.reopen(activeId)
      setActiveConv(c => ({ ...c, status: 'open' }))
      setConversations(list => list.map(c => c.id === activeId ? { ...c, status: 'open' } : c))
    } catch (err) {
      setSendError(err.message ?? 'Failed.')
    }
  }

  function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  const messageGroups = groupMessagesByDate(messages)
  const windowOpen = liveSeconds > 0
  const countdownLabel = formatWindowCountdown(liveSeconds)
  const canSend = windowOpen && !sending && !uploading && (replyText.trim() || (mediaFile && mediaFile.media_id))

  return (
    <div style={{ display: 'flex', height: 'calc(100vh - 0px)', overflow: 'hidden', background: 'var(--bg)' }}>

      {/* ── Left Panel: Conversation List ── */}
      <div style={{
        width: narrow ? '100%' : '340px',
        flexShrink: 0,
        display: narrow && activeConv ? 'none' : 'flex',
        flexDirection: 'column',
        background: 'var(--surface)',
        borderRight: '1px solid var(--border)',
        overflow: 'hidden'
      }}>

        {/* Panel header */}
        <div style={{ padding: '1rem 1rem .75rem', borderBottom: '1px solid var(--border)' }}>
          <div style={{ fontWeight: 700, fontSize: '1rem', marginBottom: '.75rem', color: 'var(--text)' }}>
            Inbox
          </div>
          <input
            className="form-input"
            placeholder="Search name, number or company…"
            value={searchQ}
            onChange={e => setSearchQ(e.target.value)}
            style={{ marginBottom: '.6rem' }}
          />
          {catList.length > 0 && (
            <select
              className="form-input"
              value={categoryFilter}
              onChange={e => changeCategory(e.target.value)}
              title="Filter conversations by company category"
              style={{ marginBottom: '.6rem', width: '100%', cursor: 'pointer' }}
            >
              <option value="">All categories</option>
              {catList.map(c => <option key={c} value={c}>{c}</option>)}
            </select>
          )}
          {/* Status tabs */}
          <div style={{ display: 'flex', gap: '.25rem' }}>
            {['all', 'open', 'resolved'].map(tab => (
              <button
                key={tab}
                onClick={() => setStatusTab(tab)}
                className={`btn btn-sm ${statusTab === tab ? 'btn-primary' : 'btn-ghost'}`}
                style={{ textTransform: 'capitalize', flex: 1, justifyContent: 'center' }}
              >
                {tab}
              </button>
            ))}
          </div>
        </div>

        {/* Conversation list */}
        <div style={{ flex: 1, overflowY: 'auto' }}>
          {loadingList ? (
            <div className="empty-state">
              <div className="empty-state-icon"><Hourglass size={40} strokeWidth={1.4} /></div>
              <div className="empty-state-text">Loading…</div>
            </div>
          ) : filteredConvs.length === 0 ? (
            <div className="empty-state">
              <div className="empty-state-icon"><MessageCircle size={40} strokeWidth={1.4} /></div>
              <div className="empty-state-text">
                {categoryFilter
                  ? `No conversations in ${categoryFilter}`
                  : 'No conversations'}
              </div>
            </div>
          ) : (
            filteredConvs.map(conv => {
              const isActive = conv.id === activeId
              const windowLabel = formatWindowBadge(conv.seconds_remaining)
              return (
                <div
                  key={conv.id}
                  onClick={() => selectConversation(conv)}
                  style={{
                    display: 'flex',
                    alignItems: 'flex-start',
                    gap: '.75rem',
                    padding: '.85rem 1rem',
                    cursor: 'pointer',
                    background: isActive ? 'var(--primary-light)' : 'transparent',
                    borderLeft: isActive ? '3px solid var(--primary)' : '3px solid transparent',
                    borderBottom: '1px solid var(--border)',
                    transition: 'background .1s',
                  }}
                >
                  <div className="avatar" style={{ width: 38, height: 38, fontSize: '.8rem', flexShrink: 0 }}>
                    {initials(conv.contact_name)}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '.5rem' }}>
                      <span style={{ fontWeight: conv.is_read ? 500 : 700, fontSize: '.875rem', color: 'var(--text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {conv.contact_name || conv.wa_number}
                        {!conv.is_read && (
                          <span style={{ display: 'inline-block', width: 7, height: 7, borderRadius: '50%', background: '#08569f', marginLeft: 5, verticalAlign: 'middle' }} />
                        )}
                      </span>
                      <span style={{ fontSize: '.72rem', color: 'var(--text-3)', flexShrink: 0 }}>
                        {formatTime(conv.last_message_at)}
                      </span>
                    </div>
                    <div style={{ fontSize: '.78rem', color: 'var(--text-2)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', marginTop: '.1rem' }}>
                      {conv.wa_number}
                    </div>
                    {(conv.company_name || conv.contact_category) && (
                      <div
                        title={[conv.company_name, conv.contact_category].filter(Boolean).join(' · ')}
                        style={{ fontSize: '.72rem', color: 'var(--text-3)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', marginTop: '.1rem' }}
                      >
                        {conv.company_name && <span style={{ fontWeight: 600 }}>{conv.company_name}</span>}
                        {conv.company_name && conv.contact_category && ' · '}
                        {conv.contact_category}
                      </div>
                    )}
                    <div style={{ display: 'flex', alignItems: 'center', gap: '.4rem', marginTop: '.3rem' }}>
                      {windowLabel ? (
                        <span style={{ fontSize: '.7rem', fontWeight: 600, color: '#065f46', background: '#d1fae5', borderRadius: '999px', padding: '.1rem .45rem' }}>
                          {windowLabel}
                        </span>
                      ) : (
                        <span style={{ fontSize: '.7rem', fontWeight: 600, color: '#991b1b', background: '#fee2e2', borderRadius: '999px', padding: '.1rem .45rem' }}>
                          Closed
                        </span>
                      )}
                      {conv.status === 'resolved' && (
                        <span className="badge" style={{ fontSize: '.65rem', padding: '.1rem .4rem', background: 'var(--border)', color: 'var(--text-3)' }}>
                          Resolved
                        </span>
                      )}
                      {conv.handoff_at && conv.status !== 'resolved' && (
                        <span title="Handed off to a human by a flow" style={{ fontSize: '.7rem', fontWeight: 600, color: '#5b21b6', background: '#ede9fe', borderRadius: '999px', padding: '.1rem .45rem' }}>
                          Handoff{conv.assigned_agent_name ? ` · ${conv.assigned_agent_name}` : ''}
                        </span>
                      )}
                    </div>
                  </div>
                </div>
              )
            })
          )}
        </div>
      </div>

      {/* ── Right Panel: Chat View ── */}
      <div style={{ flex: 1, display: narrow && !activeConv ? 'none' : 'flex', flexDirection: 'column', overflow: 'hidden', minWidth: 0 }}>

        {!activeConv ? (
          <div className="empty-state" style={{ flex: 1 }}>
            <div className="empty-state-icon"><MessageCircle size={40} strokeWidth={1.4} /></div>
            <div className="empty-state-text">Select a conversation to start messaging</div>
          </div>
        ) : (
          <>
            {/* Chat header */}
            <div style={{
              display: 'flex',
              alignItems: 'center',
              gap: '1rem',
              padding: '.85rem 1.25rem',
              background: 'var(--surface)',
              borderBottom: '1px solid var(--border)',
              flexShrink: 0
            }}>
              {narrow && (
                <button onClick={() => { setActiveConv(null); setActiveId(null) }} aria-label="Back to conversations"
                  style={{ background: 'none', border: 'none', fontSize: 22, lineHeight: 1, cursor: 'pointer', color: 'var(--text)', padding: '0 4px' }}>←</button>
              )}
              <div className="avatar" style={{ width: 40, height: 40, flexShrink: 0 }}>
                {initials(activeConv.contact_name)}
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontWeight: 700, fontSize: '.9375rem', color: 'var(--text)' }}>
                  {activeConv.contact_name || activeConv.wa_number}
                </div>
                <div style={{ fontSize: '.78rem', color: 'var(--text-3)', fontFamily: 'ui-monospace, monospace' }}>
                  {activeConv.wa_number}
                </div>
                {(activeConv.company_name || activeConv.contact_category) && (
                  <div
                    title={[activeConv.company_name, activeConv.contact_category].filter(Boolean).join(' · ')}
                    style={{ fontSize: '.75rem', color: 'var(--text-2)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', marginTop: '.1rem' }}
                  >
                    {activeConv.company_name && <span style={{ fontWeight: 600 }}>{activeConv.company_name}</span>}
                    {activeConv.company_name && activeConv.contact_category && ' · '}
                    {activeConv.contact_category}
                  </div>
                )}
              </div>
              <div className="flex gap-2 items-center">
                <span className={`badge ${activeConv.status === 'resolved' ? 'badge-lost' : 'badge-active'}`}>
                  {activeConv.status ?? 'open'}
                </span>
                {activeConv.status === 'resolved' ? (
                  <button className="btn btn-sm btn-ghost" onClick={handleReopen}>
                    Reopen
                  </button>
                ) : (
                  <button className="btn btn-sm btn-success" onClick={handleResolve}>
                    Resolve
                  </button>
                )}
              </div>
            </div>

            {/* Messages area */}
            <div style={{ flex: 1, overflowY: 'auto', padding: '1rem 1.25rem', background: 'var(--bg)' }}>
              {loadingMessages ? (
                <div className="empty-state">
                  <div className="empty-state-icon"><Hourglass size={40} strokeWidth={1.4} /></div>
                  <div className="empty-state-text">Loading messages…</div>
                </div>
              ) : messages.length === 0 ? (
                <div className="empty-state">
                  <div className="empty-state-icon"><Mail size={40} strokeWidth={1.4} /></div>
                  <div className="empty-state-text">No messages yet</div>
                </div>
              ) : (
                messageGroups.map(group => (
                  <div key={group.date}>
                    {/* Date separator */}
                    <div style={{ display: 'flex', alignItems: 'center', gap: '.75rem', margin: '1rem 0 .75rem' }}>
                      <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
                      <span style={{ fontSize: '.72rem', color: 'var(--text-3)', fontWeight: 600, whiteSpace: 'nowrap', padding: '0 .5rem' }}>
                        {group.date}
                      </span>
                      <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
                    </div>

                    {group.messages.map(msg => {
                      const isOut = msg.direction === 'out'
                      return (
                        <div
                          key={msg.id}
                          style={{
                            display: 'flex',
                            justifyContent: isOut ? 'flex-end' : 'flex-start',
                            marginBottom: '.5rem'
                          }}
                        >
                          <div style={{
                            maxWidth: Array.isArray(msg.cards) && msg.cards.length > 0 ? '94%' : (narrow ? '86%' : '70%'),
                            width: Array.isArray(msg.cards) && msg.cards.length > 0 ? (narrow ? '94%' : 640) : undefined,
                            background: isOut ? '#0a6cc4' : 'var(--surface)',
                            color: isOut ? '#fff' : 'var(--text)',
                            borderRadius: isOut ? '14px 14px 4px 14px' : '14px 14px 14px 4px',
                            padding: msg.type === 'image' && msg.media_url ? '.35rem' : '.6rem .85rem',
                            boxShadow: 'var(--shadow-sm)',
                            border: isOut ? 'none' : '1px solid var(--border)',
                            wordBreak: 'break-word',
                            lineHeight: 1.5,
                            overflow: 'hidden',
                          }}>
                            {/* ── Media rendering ── */}
                            {/* Helper: build authenticated media URL */}
                            {(() => {
                              const tok = localStorage.getItem('tp_token') ?? ''
                              const mediaUrl = (id) => `/api/v1/inbox/media/${id}?token=${encodeURIComponent(tok)}`
                              if (msg.type === 'image' && msg.media_url) return (
                                <div>
                                  <img
                                    src={mediaUrl(msg.media_url)}
                                    alt={msg.body || 'Image'}
                                    style={{ maxWidth: '240px', maxHeight: '240px', display: 'block', borderRadius: 8, cursor: 'pointer' }}
                                    onClick={() => window.open(mediaUrl(msg.media_url), '_blank')}
                                    onError={e => { e.currentTarget.style.display='none'; e.currentTarget.nextSibling.style.display='flex' }}
                                  />
                                  <div style={{ display:'none', alignItems:'center', gap:6, padding:'6px 8px', fontSize:'.875rem' }}>
                                    <ImageIcon size={14} strokeWidth={2} /> {msg.body || 'Image'}
                                  </div>
                                  {msg.body && <div style={{ fontSize: '.75rem', padding: '4px 6px 2px', opacity: .85 }}>{msg.body}</div>}
                                </div>
                              )
                              if (msg.type === 'document' && msg.media_url) return (
                                <div style={{ padding: '2px 6px' }}>
                                  <a href={mediaUrl(msg.media_url)} target="_blank" rel="noreferrer"
                                     style={{ color: isOut ? '#bcdcf6' : '#08569f', textDecoration: 'none', fontSize: '.875rem', display:'flex', alignItems:'center', gap:6 }}>
                                    <FileText size={14} strokeWidth={2} /> {msg.body || 'Document'}
                                  </a>
                                </div>
                              )
                              if (msg.type === 'audio' && msg.media_url) return (
                                <div style={{ padding: '4px 6px' }}>
                                  <audio controls src={mediaUrl(msg.media_url)} style={{ maxWidth: 220, height: 36 }} />
                                </div>
                              )
                              if (msg.type === 'video' && msg.media_url) return (
                                <div>
                                  <video controls src={mediaUrl(msg.media_url)} style={{ maxWidth: 240, borderRadius: 8, display:'block' }} />
                                  {msg.body && <div style={{ fontSize: '.75rem', padding: '4px', opacity: .85 }}>{msg.body}</div>}
                                </div>
                              )
                              return (
                                <>
                                  <div style={{ fontSize: '.875rem', whiteSpace: 'pre-wrap' }}>{msg.body}</div>
                                  {Array.isArray(msg.cards) && msg.cards.length > 0 && (
                                    <div style={{ display: 'flex', gap: 8, overflowX: 'auto', marginTop: 8, paddingBottom: 6 }}>
                                      {msg.cards.map((c, ci) => (
                                        <div key={ci} style={{ flex: '0 0 230px', background: 'rgba(255,255,255,.75)', border: '1px solid rgba(0,0,0,.08)', borderRadius: 10, overflow: 'hidden', color: '#111827' }}>
                                          {c?.image_url && <img src={c.image_url} alt="" loading="lazy" style={{ width: '100%', height: 130, objectFit: 'cover', display: 'block', background: '#e5e7eb' }} />}
                                          {c?.body && <div style={{ fontSize: '.78rem', padding: '6px 8px', whiteSpace: 'pre-wrap' }}>{String(c.body)}</div>}
                                          {Array.isArray(c?.buttons) && c.buttons.map((bt, bi) => (
                                            <div key={bi} style={{ borderTop: '1px solid rgba(0,0,0,.08)', textAlign: 'center', fontSize: '.78rem', fontWeight: 600, color: '#0e7490', padding: '6px 4px' }}>{String(bt?.text ?? '')}</div>
                                          ))}
                                        </div>
                                      ))}
                                    </div>
                                  )}
                                </>
                              )
                            })()}
                            <div style={{
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'flex-end',
                              gap: '.35rem',
                              marginTop: '.25rem',
                              padding: msg.type !== 'text' ? '0 .5rem .25rem' : 0,
                            }}>
                              <span style={{ fontSize: '.68rem', opacity: .7 }}>
                                {parseDbDate(msg.created_at)?.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) ?? ''}
                              </span>
                              {isOut && <StatusIcon status={msg.status} />}
                            </div>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                ))
              )}
              <div ref={messagesEndRef} />
            </div>

            {/* Window countdown banner + template picker + reply box */}
            <div style={{ flexShrink: 0, background: 'var(--surface)', borderTop: '1px solid var(--border)' }}>

              {/* Window banner */}
              {windowOpen ? (
                <div style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '.5rem',
                  padding: '.55rem 1.25rem',
                  background: '#d1fae5',
                  fontSize: '.8rem',
                  color: '#065f46',
                  fontWeight: 600,
                  borderBottom: '1px solid #a7f3d0'
                }}>
                  <MessageCircle size={14} strokeWidth={2} style={{ flexShrink: 0 }} /> Free messaging window: {countdownLabel}
                </div>
              ) : (
                <div style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '.5rem',
                  padding: '.55rem 1.25rem',
                  background: '#fff7ed',
                  fontSize: '.8rem',
                  color: '#92400e',
                  fontWeight: 600,
                  borderBottom: '1px solid #fed7aa'
                }}>
                  <AlertTriangle size={14} strokeWidth={2} style={{ flexShrink: 0 }} /> Window closed — only templates can be sent
                </div>
              )}

              {/* Template picker — shown when window is closed and a conversation is active */}
              {windowClosed && activeConv && (
                <div style={{ padding: '8px 16px', background: '#fff7ed', borderBottom: '1px solid #fed7aa' }}>
                  {!showTemplatePicker ? (
                    <button
                      onClick={() => setShowTemplatePicker(true)}
                      className="btn btn-sm btn-primary"
                      style={{ fontSize: 12 }}
                    >
                      <Send size={13} strokeWidth={2} /> Send Template Message
                    </button>
                  ) : (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                      <select
                        style={{ fontSize: 12, padding: '4px 8px', borderRadius: 4, border: '1px solid #d1d5db', flex: 1, minWidth: 200 }}
                        value={selectedTpl?.id ?? ''}
                        onChange={e => setSelectedTpl(templates.find(t => String(t.id) === e.target.value) ?? null)}
                        disabled={templates.length === 0}
                      >
                        <option value="">
                          {templates.length > 0 ? '— select template —'
                            : tplListLoading ? 'Loading templates…'
                            : 'No approved templates'}
                        </option>
                        {templates.map(t => (
                          <option key={t.id} value={t.id}>
                            {t.display_name ?? t.name} ({t.category})
                          </option>
                        ))}
                      </select>
                      {!tplListLoading && (tplListError || templates.length === 0) && (
                        <div style={{ fontSize: 11, color: tplListError ? '#dc2626' : '#92400e', flex: '100%', display: 'flex', alignItems: 'center', gap: 6 }}>
                          {tplListError
                            ? <>{tplListError} <button onClick={loadApprovedTemplates} style={{ background: 'none', border: 'none', color: 'var(--primary,#0a6cc4)', fontWeight: 600, cursor: 'pointer', padding: 0, fontSize: 11 }}>Retry</button></>
                            : <>No approved templates yet — approve one on the <a href="#/templates" style={{ color: 'var(--primary,#0a6cc4)', fontWeight: 600 }}>Templates</a> page.</>}
                        </div>
                      )}
                      {selectedTpl && (
                        <div style={{ fontSize: 11, color: '#6b7280', flex: '100%', marginTop: 2 }}>
                          Preview: {(selectedTpl.body ?? '').slice(0, 80)}{selectedTpl.body?.length > 80 ? '…' : ''}
                        </div>
                      )}
                      {selectedTpl && templateVarNames(selectedTpl).length > 0 && (
                        <div style={{ flex: '100%', display: 'flex', flexDirection: 'column', gap: 5, marginTop: 4 }}>
                          {templateVarNames(selectedTpl).map((name, i) => (
                            <input
                              key={i}
                              value={tplVars[i] ?? ''}
                              onChange={e => setTplVars(prev => { const c = [...prev]; c[i] = e.target.value; return c })}
                              placeholder={`{{${i + 1}}} — ${name}`}
                              style={{ fontSize: 12, padding: '4px 8px', borderRadius: 4, border: '1px solid #d1d5db', width: '100%', boxSizing: 'border-box' }}
                            />
                          ))}
                        </div>
                      )}
                      <button
                        onClick={handleSendTemplate}
                        disabled={!selectedTpl || tplLoading}
                        className="btn btn-sm btn-primary"
                        style={{ fontSize: 12 }}
                      >
                        {tplLoading ? 'Sending…' : 'Send'}
                      </button>
                      <button
                        onClick={() => { setShowTemplatePicker(false); setSelectedTpl(null) }}
                        className="btn btn-sm btn-ghost"
                        style={{ fontSize: 12 }}
                      >
                        Cancel
                      </button>
                    </div>
                  )}
                </div>
              )}

              {/* Send error */}
              {sendError && (
                <div style={{ padding: '.4rem 1.25rem', background: '#fee2e2', color: '#991b1b', fontSize: '.8rem', fontWeight: 500 }}>
                  {sendError}
                </div>
              )}

              {/* Media preview pill */}
              {(mediaFile || uploading) && (
                <div style={{ padding: '.5rem 1.25rem .25rem', display: 'flex', alignItems: 'center', gap: '.5rem' }}>
                  <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '.4rem',
                    background: 'var(--primary-light)',
                    border: '1px solid var(--border)',
                    borderRadius: '999px',
                    padding: '.25rem .65rem',
                    fontSize: '.8rem',
                    color: 'var(--text)',
                    maxWidth: '100%',
                  }}>
                    {uploading ? (
                      <>
                        <span style={{
                          display: 'inline-block',
                          width: '1rem',
                          height: '1rem',
                          border: '2px solid var(--primary)',
                          borderTopColor: 'transparent',
                          borderRadius: '50%',
                          animation: 'spin 0.7s linear infinite',
                          flexShrink: 0,
                        }} />
                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          {mediaFile?.filename ?? 'Uploading…'}
                        </span>
                      </>
                    ) : (
                      <>
                        <span style={{ flexShrink: 0, display: 'flex' }}>
                          {mediaFile?.type === 'image' ? <ImageIcon size={14} strokeWidth={2} /> :
                           mediaFile?.type === 'video' ? <Film size={14} strokeWidth={2} /> :
                           mediaFile?.type === 'audio' ? <Music size={14} strokeWidth={2} /> : <FileText size={14} strokeWidth={2} />}
                        </span>
                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          {mediaFile?.filename}
                        </span>
                      </>
                    )}
                    {!uploading && (
                      <button
                        onClick={handleRemoveMedia}
                        style={{
                          background: 'none',
                          border: 'none',
                          cursor: 'pointer',
                          color: 'var(--text-3)',
                          padding: '0 .1rem',
                          display: 'flex',
                          alignItems: 'center',
                          flexShrink: 0,
                        }}
                        title="Remove file"
                        aria-label="Remove attached file"
                      >
                        <X size={14} strokeWidth={2} />
                      </button>
                    )}
                  </div>
                </div>
              )}

              {/* Hidden file input */}
              <input
                ref={fileInputRef}
                type="file"
                accept="image/*,video/*,audio/*,application/pdf,text/plain,text/csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                style={{ display: 'none' }}
                onChange={handleFileChange}
              />

              {/* Reply box */}
              <div style={{ display: 'flex', flexWrap: narrow ? 'wrap' : 'nowrap', gap: narrow ? '.5rem' : '.75rem', padding: narrow ? '.65rem .85rem' : '.85rem 1.25rem', alignItems: 'flex-end' }}>
                {/* Paperclip button */}
                <button
                  type="button"
                  onClick={() => windowOpen && fileInputRef.current?.click()}
                  disabled={!windowOpen || sending || uploading}
                  title="Attach file"
                  aria-label="Attach file"
                  style={{
                    flexShrink: 0,
                    background: 'none',
                    border: '1px solid var(--border)',
                    borderRadius: '8px',
                    width: '2.5rem',
                    height: '2.5rem',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    cursor: windowOpen && !sending && !uploading ? 'pointer' : 'not-allowed',
                    opacity: windowOpen && !sending && !uploading ? 1 : 0.5,
                    fontSize: '1.1rem',
                    alignSelf: 'flex-end',
                    transition: 'background .15s',
                  }}
                  onMouseEnter={e => { if (windowOpen && !sending && !uploading) e.currentTarget.style.background = 'var(--bg)' }}
                  onMouseLeave={e => { e.currentTarget.style.background = 'none' }}
                >
                  <Paperclip size={18} strokeWidth={2} />
                </button>

                {/* Request payment button */}
                <button
                  type="button"
                  title="Request payment"
                  onClick={() => { if (windowOpen) { setPayForm({ amount: '', description: '' }); setShowPayModal(true) } }}
                  disabled={!windowOpen || sending}
                  style={{
                    background: 'none', border: '1px solid var(--border)', borderRadius: '8px',
                    width: '2.5rem', height: '2.5rem', display: 'flex', alignItems: 'center', justifyContent: 'center',
                    cursor: windowOpen && !sending ? 'pointer' : 'not-allowed',
                    opacity: windowOpen && !sending ? 1 : 0.5, fontSize: '1.1rem', alignSelf: 'flex-end',
                  }}
                >
                  <CreditCard size={18} strokeWidth={2} />
                </button>

                {/* AI suggest button */}
                <button
                  type="button"
                  title="Suggest a reply with AI"
                  onClick={handleSuggest}
                  disabled={!windowOpen || sending || suggesting}
                  style={{
                    background: 'none', border: '1px solid var(--border)', borderRadius: '8px',
                    width: '2.5rem', height: '2.5rem', display: 'flex', alignItems: 'center', justifyContent: 'center',
                    cursor: windowOpen && !sending && !suggesting ? 'pointer' : 'not-allowed',
                    opacity: windowOpen && !sending && !suggesting ? 1 : 0.5, fontSize: '1.1rem', alignSelf: 'flex-end',
                  }}
                >
                  {suggesting ? '…' : <Sparkles size={18} strokeWidth={2} />}
                </button>

                {/* Emoji picker button + panel */}
                <div style={{ position: 'relative', flexShrink: 0, alignSelf: 'flex-end' }} ref={emojiPickerRef}>
                  <button
                    type="button"
                    onClick={() => windowOpen && setShowEmojiPicker(p => !p)}
                    disabled={!windowOpen || sending}
                    title="Emoji"
                    style={{
                      background: 'none',
                      border: '1px solid var(--border)',
                      borderRadius: '8px',
                      width: '2.5rem',
                      height: '2.5rem',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: windowOpen && !sending ? 'pointer' : 'not-allowed',
                      opacity: windowOpen && !sending ? 1 : 0.5,
                      fontSize: '1.1rem',
                      transition: 'background .15s',
                    }}
                    onMouseEnter={e => { if (windowOpen) e.currentTarget.style.background = 'var(--bg)' }}
                    onMouseLeave={e => { e.currentTarget.style.background = 'none' }}
                  >
                    <Smile size={18} strokeWidth={2} />
                  </button>

                  {showEmojiPicker && (
                    <div style={{
                      ...(narrow
                        ? { position: 'fixed', left: 8, right: 8, bottom: 160 }
                        : { position: 'absolute', bottom: '3rem', left: 0 }),
                      background: '#fff',
                      border: '1px solid var(--border)',
                      borderRadius: 12,
                      boxShadow: '0 8px 32px rgba(0,0,0,.15)',
                      padding: '10px',
                      zIndex: 1000,
                      width: narrow ? 'auto' : 300,
                      maxHeight: narrow ? '45vh' : 280,
                      overflowY: 'auto',
                    }}>
                      {[
                        { label: 'Smileys', emojis: ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥','😔','😪','🤤','😴','😷','🤒','🤕','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','😎','🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖'] },
                        { label: 'Gestures', emojis: ['👋','🤚','🖐','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍️','💪','🦾','🦿','🦵','🦶','👂','🦻','👃','🫦','👁','👀','🫀','🫁','🧠','🦷','🦴'] },
                        { label: 'Hearts & Love', emojis: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️','🚷','🚯','🚳','🚱','🔞','📵','🔕'] },
                        { label: 'People', emojis: ['👶','🧒','👦','👧','🧑','👱','👨','🧔','👩','🧓','👴','👵','🙍','🙎','🙅','🙆','💁','🙋','🧏','🙇','🤦','🤷','💆','💇','🚶','🧍','🧎','🏃','💃','🕺','🕴','👯','🧖','🧗','🏌️','🏇','🏊','🏄','🚣','🏋️','🤼','🤸','🤺','⛹️','🤾','🏐','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🏓','🏸','🏒','🏑','🥍','🏏','⛳','🪃','🥊','🎿','🛷','🥌'] },
                        { label: 'Animals', emojis: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐒','🐔','🐧','🐦','🐤','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐜','🦟','🦗','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🐊','🐅','🐆','🦓','🦍','🦧','🦣','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🦬','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🦌','🐕','🐩','🦮','🐈','🐈‍⬛','🐓','🦃','🦤','🦚','🦜','🦢','🦩','🕊','🐇','🦝','🦨','🦡','🦫','🦦','🦥','🐁','🐀','🐿','🦔'] },
                        { label: 'Food', emojis: ['🍎','🍊','🍋','🍇','🍓','🍒','🍑','🥭','🍍','🥝','🍅','🥥','🥑','🍆','🥔','🥕','🌽','🌶','🥒','🥬','🥦','🧄','🧅','🍄','🥜','🌰','🍞','🥐','🥖','🥨','🧀','🥚','🍳','🧈','🥞','🧇','🥓','🥩','🍗','🍖','🦴','🌭','🍔','🍟','🍕','🫓','🥪','🥙','🧆','🌮','🌯','🫔','🥗','🥘','🫕','🥫','🍱','🍘','🍙','🍚','🍛','🍜','🍝','🍠','🍢','🍣','🍤','🍥','🥮','🍡','🥟','🥠','🥡','🍦','🍧','🍨','🍩','🍪','🎂','🍰','🧁','🥧','🍫','🍬','🍭','🍮','🍯','🍼','🥛','☕','🫖','🍵','🍶','🍾','🍷','🍸','🍹','🍺','🍻','🥂','🥃'] },
                        { label: 'Activities', emojis: ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🏒','🏑','🥍','🏏','🪃','⛳','🪁','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸','🥌','🎿','🛷','🥌','🎯','🪤','🎳','🏋️','🤼','🤸','🏌️','🏇','🏊','🤽','🚣','🧘','🛤','🎖','🏆','🥇','🥈','🥉','🏅','🎗','🎫','🎟','🎪','🤹','🎭','🩰','🎨','🎬','🎤','🎧','🎼','🎷','🪗','🎸','🎹','🥁','🪘','🎺','🎻','🪕','🎲','♟','🎯','🎮','🎰','🧩'] },
                        { label: 'Travel', emojis: ['🚗','🚕','🚙','🚌','🚎','🏎','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🏍','🛵','🛺','🚲','🛴','🛹','🛼','🚏','🛣','🛤','⛽','🚧','⚓','🚢','✈️','🛩','🛫','🛬','🪂','💺','🚁','🛸','🚀','🛶','⛵','🚤','🛥','🛳','🚂','🚃','🚄','🚅','🚆','🚇','🚈','🚉','🚊','🚝','🚞','🚋','🚌','🚍','🚎','🚐','🏎','🚑','🚒','🚓','🚔','🚕','🚖','🚗','🚘','🚙','🛻','🚚','🚛','🚜','🏗','🚧','🛗'] },
                        { label: 'Objects', emojis: ['⌚','📱','📲','💻','⌨️','🖥','🖨','🖱','🖲','🕹','🗜','💽','💾','💿','📀','📼','📷','📸','📹','🎥','📽','🎞','📞','☎️','📟','📠','📺','📻','🧭','⏱','⏲','⏰','🕰','⌛','⏳','📡','🔋','🔌','💡','🔦','🕯','🪔','🧱','🔮','🪄','🪆','🛒','🎁','🎈','🎏','🎀','🎊','🎉','🎎','🎐','🧧','🎑','🎃','👻','🎄','✨','🎋','🎍','🧨','✨','🎆','🎇','🧲','🪄','🧸','🪅','🎭','🪆','🖼','🪞','🪟','🛋','🚪','🛏','🛁','🪥','🚿','🪒','🧴','🧷','🧹','🧺','🧻','🪣','🧼','🫧','🪥','🧽','🪜','🛒','🛌','🧸'] },
                      ].map(({ label, emojis }) => (
                        <div key={label} style={{ marginBottom: 8 }}>
                          <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-3)', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 4 }}>{label}</div>
                          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 2 }}>
                            {emojis.map(em => (
                              <button
                                key={em}
                                type="button"
                                onClick={() => insertEmoji(em)}
                                style={{
                                  background: 'none',
                                  border: 'none',
                                  cursor: 'pointer',
                                  fontSize: '1.2rem',
                                  width: '2rem',
                                  height: '2rem',
                                  display: 'flex',
                                  alignItems: 'center',
                                  justifyContent: 'center',
                                  borderRadius: 6,
                                  transition: 'background .1s',
                                }}
                                onMouseEnter={e => e.currentTarget.style.background = 'var(--primary-light)'}
                                onMouseLeave={e => e.currentTarget.style.background = 'none'}
                              >
                                {em}
                              </button>
                            ))}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>

                <textarea
                  ref={textareaRef}
                  className="form-input"
                  placeholder={windowOpen ? (narrow ? 'Type a message…' : 'Type a message… (Enter to send, Shift+Enter for newline)') : (narrow ? 'Window closed — send a template' : 'Window closed — use a template campaign to message this contact')}
                  value={replyText}
                  onChange={e => setReplyText(e.target.value)}
                  onKeyDown={handleKeyDown}
                  disabled={!windowOpen || sending}
                  rows={2}
                  style={{
                    flex: narrow ? '1 1 100%' : 1,
                    order: narrow ? -1 : 0,
                    minWidth: 0,
                    resize: 'none',
                    opacity: windowOpen ? 1 : .5,
                    cursor: windowOpen ? 'text' : 'not-allowed',
                    lineHeight: 1.5
                  }}
                />
                <button
                  className="btn btn-primary"
                  onClick={handleSend}
                  disabled={!canSend}
                  style={{ flexShrink: 0, alignSelf: 'flex-end', marginLeft: narrow ? 'auto' : 0 }}
                >
                  {sending ? '…' : 'Send'}
                </button>
              </div>
            </div>
          </>
        )}
      </div>

      {/* Request payment modal */}
      {showPayModal && (
        <div onClick={e => { if (e.target === e.currentTarget) setShowPayModal(false) }}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
          <div style={{ background: '#fff', borderRadius: 14, maxWidth: 400, width: '100%', padding: '1.5rem' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1rem' }}>
              <h2 style={{ margin: 0, fontSize: 18, fontWeight: 800 }}>Request Payment</h2>
              <button onClick={() => setShowPayModal(false)} style={{ background: 'none', border: 'none', display: 'flex', cursor: 'pointer', color: '#9ca3af' }}><X size={18} strokeWidth={2} /></button>
            </div>
            <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 14 }}>
              Sends a Razorpay payment link to {activeConv?.contact_name || activeConv?.wa_number} on WhatsApp.
            </div>
            <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Amount (₹)</label>
            <input type="number" min="1" step="0.01" autoFocus value={payForm.amount}
              onChange={e => setPayForm(f => ({ ...f, amount: e.target.value }))}
              style={{ width: '100%', boxSizing: 'border-box', padding: '10px 12px', border: '1.5px solid #e5e7eb', borderRadius: 9, fontSize: 15, marginBottom: 12 }}
              placeholder="e.g. 499" />
            <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Description</label>
            <input value={payForm.description} maxLength={255}
              onChange={e => setPayForm(f => ({ ...f, description: e.target.value }))}
              style={{ width: '100%', boxSizing: 'border-box', padding: '10px 12px', border: '1.5px solid #e5e7eb', borderRadius: 9, fontSize: 15 }}
              placeholder="e.g. Order #1234" />
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: '1.5rem' }}>
              <button className="btn btn-ghost" onClick={() => setShowPayModal(false)} disabled={payBusy}>Cancel</button>
              <button className="btn btn-primary" onClick={handleSendPayment} disabled={payBusy}>
                {payBusy ? 'Sending…' : 'Send link'}
              </button>
            </div>
          </div>
        </div>
      )}

      <style>{`
        @keyframes spin {
          to { transform: rotate(360deg); }
        }
      `}</style>
    </div>
  )
}
