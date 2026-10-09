import { useEffect, useRef, useState } from 'react'
import { Code2, Eye, PenLine, Braces, Smartphone, Monitor } from 'lucide-react'
import { MERGE_TAGS } from './emailStarters'

/**
 * Email body editor with three views over ONE html string:
 *   Visual  — the email itself, editable in place (iframe designMode)
 *   HTML    — the raw markup, for pasting a design from elsewhere
 *   Preview — read-only, desktop or phone width
 *
 * The iframe is sandboxed without allow-scripts, so a pasted design can never
 * run code inside TripSarthi. Visual edits are read back from the iframe's
 * document, which keeps the full <html> shell the starters rely on.
 */
export default function EmailHtmlEditor({ value, onChange, height = 520 }) {
  const [mode, setMode]       = useState('visual')
  const [device, setDevice]   = useState('desktop')
  const [showTags, setShowTags] = useState(false)
  const frameRef   = useRef(null)
  const textRef    = useRef(null)
  const lastEmitted = useRef(value)

  // Load the value into the editable frame when entering Visual mode, or when
  // the value changes from outside (a starter picked, a template loaded).
  useEffect(() => {
    if (mode !== 'visual') return
    const frame = frameRef.current
    if (!frame) return
    if (value === lastEmitted.current && frame.dataset.loaded === '1') return
    loadFrame(frame, value, true, (html) => { lastEmitted.current = html; onChange(html) })
    lastEmitted.current = value
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mode, value])

  function insertTag(tag) {
    setShowTags(false)
    if (mode === 'html' && textRef.current) {
      const el = textRef.current
      const s = el.selectionStart ?? value.length
      const e = el.selectionEnd ?? value.length
      const next = value.slice(0, s) + tag + value.slice(e)
      onChange(next)
      requestAnimationFrame(() => { el.focus(); el.setSelectionRange(s + tag.length, s + tag.length) })
      return
    }
    const doc = frameRef.current?.contentDocument
    if (doc) {
      doc.body.focus()
      doc.execCommand('insertText', false, tag)
      const html = serialize(doc)
      lastEmitted.current = html
      onChange(html)
    }
  }

  const tab = (key, Icon, label) => (
    <button type="button" onClick={() => setMode(key)}
            className={`btn btn-sm ${mode === key ? 'btn-primary' : 'btn-ghost'}`}>
      <Icon size={13} strokeWidth={2} /> {label}
    </button>
  )

  return (
    <div style={{ border: '1px solid var(--border, #e5e7eb)', borderRadius: 8, overflow: 'hidden', background: '#fff' }}>
      <div className="flex items-center justify-between gap-2" style={{ padding: '6px 8px', borderBottom: '1px solid var(--border, #e5e7eb)', background: '#f9fafb', flexWrap: 'wrap' }}>
        <div className="flex items-center gap-2" style={{ flexWrap: 'wrap' }}>
          {tab('visual', PenLine, 'Visual')}
          {tab('html', Code2, 'HTML')}
          {tab('preview', Eye, 'Preview')}
        </div>
        <div className="flex items-center gap-2" style={{ position: 'relative' }}>
          {mode === 'preview' && (
            <>
              <button type="button" className={`btn btn-sm ${device === 'desktop' ? 'btn-primary' : 'btn-ghost'}`} onClick={() => setDevice('desktop')} title="Desktop width"><Monitor size={13} /></button>
              <button type="button" className={`btn btn-sm ${device === 'mobile' ? 'btn-primary' : 'btn-ghost'}`} onClick={() => setDevice('mobile')} title="Phone width"><Smartphone size={13} /></button>
            </>
          )}
          {mode !== 'preview' && (
            <button type="button" className="btn btn-sm btn-ghost" onClick={() => setShowTags(s => !s)}>
              <Braces size={13} strokeWidth={2} /> Insert field
            </button>
          )}
          {showTags && (
            <div className="card lp-insert-menu" style={{ position: 'absolute', right: 0, top: '110%', zIndex: 20, width: 260, maxWidth: 'calc(100vw - 48px)', padding: 6 }}>
              {MERGE_TAGS.map(t => (
                <button key={t.tag} type="button" onClick={() => insertTag(t.tag)}
                        className="btn btn-ghost btn-sm w-full" style={{ justifyContent: 'space-between', gap: 8, height: 'auto', minHeight: 30, textAlign: 'left', whiteSpace: 'normal' }}>
                  <span>{t.label}</span><code style={{ fontSize: 10.5, color: '#6b7280', overflowWrap: 'anywhere', wordBreak: 'break-all', textAlign: 'right', minWidth: 0 }}>{t.tag.replace(/\|.*\}\}/, '}}')}</code>
                </button>
              ))}
              <div className="text-muted" style={{ fontSize: 11, padding: '6px 8px' }}>
                Add a fallback after a pipe: <code>{'{{contact.first_name|there}}'}</code>. Custom fields: <code>{'{{custom.field_key}}'}</code>
              </div>
            </div>
          )}
        </div>
      </div>

      {mode === 'visual' && (
        <iframe ref={frameRef} title="Email visual editor" sandbox="allow-same-origin"
                style={{ width: '100%', height, border: 0, display: 'block' }} />
      )}
      {mode === 'html' && (
        <textarea ref={textRef} value={value} onChange={e => { lastEmitted.current = e.target.value; onChange(e.target.value) }}
                  spellCheck={false} className="font-mono"
                  style={{ width: '100%', height, border: 0, padding: 12, fontSize: 12.5, lineHeight: 1.5, resize: 'vertical', outline: 'none', boxSizing: 'border-box' }} />
      )}
      {mode === 'preview' && (
        <div style={{ background: '#e5e7eb', padding: device === 'mobile' ? '16px 0' : 0, display: 'flex', justifyContent: 'center' }}>
          <iframe title="Email preview" sandbox="" srcDoc={value}
                  style={{ width: device === 'mobile' ? 375 : '100%', maxWidth: '100%', height, border: 0, background: '#fff', display: 'block' }} />
        </div>
      )}
    </div>
  )
}

function serialize(doc) {
  const clone = doc.documentElement.cloneNode(true)
  const body  = clone.querySelector('body')
  body?.removeAttribute('contenteditable')
  // The editor's own focus styling must not end up in the saved email.
  body?.style.removeProperty('outline')
  if (body && !body.getAttribute('style')) body.removeAttribute('style')
  const doctype = doc.doctype ? '<!DOCTYPE html>\n' : ''
  return doctype + clone.outerHTML
}

function loadFrame(frame, html, editable, onEdit) {
  const doc = frame.contentDocument
  if (!doc) return
  doc.open()
  doc.write(html || '<!DOCTYPE html><html><body><p>Start writing…</p></body></html>')
  doc.close()
  frame.dataset.loaded = '1'
  if (!editable) return
  doc.body.setAttribute('contenteditable', 'true')
  doc.body.style.outline = 'none'
  // Links must stay editable text, not navigate the editor away.
  doc.addEventListener('click', e => { if (e.target.closest?.('a')) e.preventDefault() })
  doc.addEventListener('input', () => onEdit(serialize(doc)))
}
