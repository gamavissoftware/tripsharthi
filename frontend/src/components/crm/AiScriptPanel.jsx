import { useState } from 'react'
import { ai } from '../../api/ai'
import { toast } from '../Toast'

const TYPES = [
  { key: 'call_script',   label: '📞 Call script' },
  { key: 'qualification', label: '❓ Qualifying questions' },
]

/**
 * AI sales assistant for a contact and/or deal. Generates a context-aware call
 * script or a set of qualifying questions via POST /ai/script. Metered against
 * the tenant's ai_replies allowance — a 422 with a limit message is surfaced.
 */
export default function AiScriptPanel({ contactId, dealId }) {
  const [type, setType]       = useState('call_script')
  const [text, setText]       = useState('')
  const [loading, setLoading] = useState(false)

  async function generate() {
    setLoading(true)
    setText('')
    try {
      const r = await ai.script(type, { contactId, dealId })
      setText(r.data?.script ?? '')
    } catch (e) {
      toast.error('Could not generate', e.message)
    } finally {
      setLoading(false)
    }
  }

  async function copy() {
    try { await navigator.clipboard.writeText(text); toast.success('Copied') }
    catch { /* clipboard unavailable */ }
  }

  return (
    <div className="lp-cd-card">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12, flexWrap: 'wrap' }}>
        <span style={{ fontWeight: 800, fontSize: 14, color: '#111827' }}>✨ AI sales assistant</span>
        <select value={type} onChange={e => setType(e.target.value)}
          style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.35rem .5rem', fontSize: '.82rem' }}>
          {TYPES.map(t => <option key={t.key} value={t.key}>{t.label}</option>)}
        </select>
        <button onClick={generate} disabled={loading}
          style={{ marginLeft: 'auto', background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.4rem 1rem', fontSize: '.82rem', fontWeight: 600, cursor: 'pointer', opacity: loading ? .6 : 1 }}>
          {loading ? 'Generating…' : 'Generate'}
        </button>
      </div>

      {text ? (
        <div>
          <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'inherit', fontSize: 13.5, lineHeight: 1.5, color: '#1f2937', background: '#f8fafc', border: '1px solid #eef2f7', borderRadius: 10, padding: '.9rem', margin: 0 }}>{text}</pre>
          <button onClick={copy} style={{ marginTop: 8, background: 'none', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.3rem .8rem', fontSize: '.78rem', cursor: 'pointer', color: '#475569' }}>Copy</button>
        </div>
      ) : (
        <p style={{ margin: 0, color: '#94a3b8', fontSize: 13 }}>
          Generate a tailored call script or qualifying questions from this lead's context.
        </p>
      )}
    </div>
  )
}
