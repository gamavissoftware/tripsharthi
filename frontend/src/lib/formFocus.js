/** Focus (and scroll to) the first invalid field inside a form/dialog. Returns true when there was one. */
export function firstInvalid(root) {
  const el = root?.querySelector?.('[aria-invalid="true"]')
  if (!el) return false
  el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.focus({ preventScroll: true })
  return true
}

/** Shake a dialog/card once (CSS .shake) to say "not saved". */
export function shake(el) {
  if (!el) return
  el.classList.remove('shake'); void el.offsetWidth; el.classList.add('shake')
}
