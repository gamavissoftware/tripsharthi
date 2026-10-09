/** Mirror of backend Gst::validGstin so the form can tell the user instantly. The server re-validates everything. */
const ALPHA = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'

export function gstinCheckChar(first14) {
  let sum = 0
  for (let i = 0; i < 14; i++) {
    const v = ALPHA.indexOf(first14[i]) * (i % 2 === 0 ? 1 : 2)
    sum += Math.floor(v / 36) + (v % 36)
  }
  return ALPHA[(36 - (sum % 36)) % 36]
}

export function validGstin(g) {
  const s = String(g || '').trim().toUpperCase()
  if (!/^\d{2}[A-Z]{5}\d{4}[A-Z]\d[A-Z0-9]Z[A-Z0-9]$/.test(s)) return false
  return gstinCheckChar(s.slice(0, 14)) === s[14]
}

export const stateOfGstin = (g) => String(g || '').trim().slice(0, 2)
export const panOfGstin = (g) => String(g || '').trim().toUpperCase().slice(2, 12)
