/**
 * Makes `navigator.clipboard.writeText` work where the modern clipboard API is blocked (embedded browser panes, some permission
 * policies, non-secure pages): it falls back to the old select-and-copy method, which only needs the click that triggered it.
 * Install once at the start of each app. If both ways fail it still rejects, so the caller's "could not copy" message shows.
 */
export function installClipboardFallback() {
  if (typeof navigator === 'undefined' || typeof document === 'undefined') return
  const modern = navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ? navigator.clipboard.writeText.bind(navigator.clipboard) : null

  const legacy = (text) => {
    const ta = document.createElement('textarea')
    ta.value = text
    ta.setAttribute('readonly', '')
    ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0'
    document.body.appendChild(ta)
    ta.focus()
    ta.select()
    ta.setSelectionRange(0, text.length)
    let ok
    try { ok = document.execCommand('copy') } catch { ok = false }
    ta.remove()
    return ok
  }

  const write = async (text) => {
    const s = String(text ?? '')
    if (modern) { try { await modern(s); return } catch { /* blocked: use the fallback below */ } }
    if (!legacy(s)) throw new Error('Copying is blocked by this browser')
  }

  try {
    if (navigator.clipboard) navigator.clipboard.writeText = write
    else Object.defineProperty(navigator, 'clipboard', { value: { writeText: write }, configurable: true })
  } catch { /* leave the browser's own behaviour */ }
}
