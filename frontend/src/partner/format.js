export const inr = (paise) => '₹' + (Number(paise || 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 })
// The API sends UTC "YYYY-MM-DD HH:MM:SS".
const parse = (s) => new Date(String(s).replace(' ', 'T') + 'Z')
export const fmtDate = (s) => (s ? parse(s).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
export const th = { padding: '10px 14px', textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase', whiteSpace: 'nowrap' }
export const td = { padding: '10px 14px', borderTop: '1px solid var(--border)', verticalAlign: 'middle' }
