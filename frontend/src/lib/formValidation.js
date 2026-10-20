import { toast } from '../components/Toast'

/**
 * App-wide form validation UX, so every existing form (there are ~70) behaves like the new ones without being rewritten.
 * It takes over the browser's native "required / email / min length" checks:
 *   - the native bubble is suppressed; the field gets a red highlight (.is-invalid) and a plain-language message under it
 *   - one growl toast says how many things need fixing; the first bad field is focused and scrolled into view
 *   - a flagged field clears the moment it becomes valid; a typed-in bad value (e.g. an e-mail) is flagged when you leave the field
 * Forms that validate themselves (noValidate, components/FormField.jsx) never trigger native checks, so they are unaffected.
 * Opt a field out with data-native-validation.
 */
const MSG_CLASS = 'ff-err ff-auto'

function labelOf(el) {
  const raw = el.labels?.[0]?.textContent || el.getAttribute('aria-label') || el.getAttribute('placeholder') || ''
  return raw.replace(/[*(].*$/, '').replace(/\s+/g, ' ').trim()
}

function messageFor(el) {
  const v = el.validity; const name = labelOf(el)
  if (v.valueMissing) {
    if (el.type === 'checkbox') return 'Please tick this box to continue'
    if (el.tagName === 'SELECT' || el.type === 'radio') return 'Please choose an option'
    if (el.type === 'file') return 'Please choose a file'
    return name && name.length < 40 ? `${name} is required` : 'This field is required'
  }
  if (v.typeMismatch) return el.type === 'email' ? 'Enter a valid email address, like name@company.com' : el.type === 'url' ? 'Enter a full web address starting with https://' : 'This value is not in the right format'
  if (v.tooShort) return `Use at least ${el.minLength} characters (you have ${el.value.length})`
  if (v.tooLong) return `Use at most ${el.maxLength} characters`
  if (v.patternMismatch) return el.title || 'This does not match the format we need'
  const isDate = ['date', 'datetime-local', 'month', 'time'].includes(el.type)
  if (v.rangeUnderflow) return isDate ? `Pick a date on or after ${el.min.replace('T', ' ')}` : `Must be ${el.min} or more`
  if (v.rangeOverflow) return isDate ? `Pick a date on or before ${el.max.replace('T', ' ')}` : `Must be ${el.max} or less`
  if (v.stepMismatch || v.badInput) return 'Enter a valid number'
  return el.validationMessage || 'Please check this field'
}

function anchorFor(el) {
  const p = el.parentElement
  // an input inside a flex row (currency + amount, etc.): put the message under the whole row
  return p && p !== document.body && getComputedStyle(p).display === 'flex' && p.children.length > 1 && !p.classList.contains('form-group') ? p : el
}
function clearMark(el) {
  el.classList.remove('is-invalid'); el.removeAttribute('aria-invalid')
  const a = anchorFor(el); const n = a.nextElementSibling
  if (n && n.dataset.autoFor === el.id) n.remove()
  else if (n && n.classList.contains('ff-auto') && !el.id) n.remove()
}
let uid = 0
function mark(el) {
  if (!el.id) el.id = 'fv-' + (++uid)
  const text = messageFor(el)
  const a = anchorFor(el); let n = a.nextElementSibling
  if (!(n && n.classList.contains('ff-auto') && n.dataset.autoFor === el.id)) {
    n = document.createElement('div'); n.className = MSG_CLASS; n.dataset.autoFor = el.id; n.setAttribute('role', 'alert'); n.style.marginTop = '4px'
    a.after(n)
  }
  n.textContent = text
  el.classList.add('is-invalid'); el.setAttribute('aria-invalid', 'true')
}

let pending = null
export function installFormValidation() {
  if (typeof document === 'undefined' || window.__fvInstalled) return
  window.__fvInstalled = true

  // Fired for every invalid control when a form is submitted (does not bubble, so listen in the capture phase).
  document.addEventListener('invalid', (e) => {
    const el = e.target
    if (!(el instanceof HTMLElement) || el.hasAttribute('data-native-validation')) return
    e.preventDefault()                                   // no native bubble
    mark(el)
    clearTimeout(pending)
    pending = setTimeout(() => {                         // once per submit, after all invalid events have fired
      const form = el.form || document
      const bad = [...form.querySelectorAll('.is-invalid')]
      if (!bad.length) return
      const first = bad[0]
      first.scrollIntoView({ block: 'center', behavior: 'smooth' }); first.focus({ preventScroll: true })
      toast.warning(bad.length === 1 ? 'One thing needs fixing' : `${bad.length} things need fixing`, first.parentElement?.querySelector('.ff-auto')?.textContent || first.nextElementSibling?.textContent)
      const box = first.closest('.modal, .dlg, form'); if (box) { box.classList.remove('shake'); void box.offsetWidth; box.classList.add('shake') }
    }, 0)
  }, true)

  // Fixed as you type.
  const recheck = (e) => {
    const el = e.target
    if (el?.classList?.contains('is-invalid') && el.checkValidity?.()) clearMark(el)
  }
  document.addEventListener('input', recheck, true)
  document.addEventListener('change', recheck, true)

  // A value that is there but wrong (bad e-mail, too short) is flagged when you leave the field. Empty fields wait for submit.
  document.addEventListener('focusout', (e) => {
    const el = e.target
    if (!(el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) || el.hasAttribute('data-native-validation') || el.form?.noValidate) return
    if (el.value && !el.validity.valid && el.willValidate) mark(el)
  }, true)
}
