import { useState, useEffect, useCallback } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'
import { fmt } from '../components/Charts'

// ── Sales leaderboard (Phase I4) ─────────────────────────────────────────────

const MEDAL = ['🥇', '🥈', '🥉']

export default function LeaderboardPage() {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [period, setPeriod] = useState({ from: new Date().toISOString().slice(0, 8) + '01', to: new Date().toISOString().slice(0, 10) })

  const load = useCallback(async () => {
    setLoading(true)
    try { const r = await crm.leaderboard(period.from, period.to); setRows(r.data ?? []) }
    catch (e) { toast.error('Failed to load leaderboard', e?.message) }
    setLoading(false)
  }, [period])
  useEffect(() => { load() }, [load])

  const top = rows[0]

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🏆 Leaderboard</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Composite of won revenue, conversions, pipeline, and follow-up.</p>
        </div>
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 12.5 }}>
          <input type="date" value={period.from} onChange={e => setPeriod(p => ({ ...p, from: e.target.value }))} style={dt} />
          <span style={{ color: '#94a3b8' }}>→</span>
          <input type="date" value={period.to} onChange={e => setPeriod(p => ({ ...p, to: e.target.value }))} style={dt} />
        </div>
      </div>

      {loading ? (
        <div style={{ padding: '2.5rem', textAlign: 'center', color: '#9ca3af' }}>Loading…</div>
      ) : rows.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 14, border: '1px solid #e5e7eb', color: '#6b7280' }}>No activity in this period.</div>
      ) : (
        <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead><tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
              <th style={th}>#</th><th style={th}>Rep</th><th style={th}>Score</th><th style={th}>Won</th><th style={th}>Conversions</th><th style={th}>Pipeline</th><th style={th}>Follow-up</th>
            </tr></thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={r.user_id} style={{ borderTop: '1px solid #f6f7f9', background: i === 0 ? '#fffdf5' : '#fff' }}>
                  <td style={{ ...td, fontSize: 16 }}>{MEDAL[i] ?? r.rank}</td>
                  <td style={{ ...td, fontWeight: 700, color: '#111827' }}>{r.name}</td>
                  <td style={{ ...td }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                      <span style={{ width: 60, height: 7, background: '#f1f5f9', borderRadius: 4, overflow: 'hidden' }}>
                        <span style={{ display: 'block', height: '100%', width: `${Math.min(100, top ? (r.score / top.score) * 100 : 0)}%`, background: '#0a6cc4' }} />
                      </span>
                      <strong>{r.score}</strong>
                    </span>
                  </td>
                  <td style={td}>{fmt.inr(r.won_revenue)}</td>
                  <td style={td}>{r.conversions}</td>
                  <td style={td}>{r.qualified}</td>
                  <td style={td}>{r.follow_up}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
const dt = { border: '1px solid #e5e7eb', borderRadius: 7, padding: '.35rem .5rem' }
const th = { padding: '.6rem 1rem', fontWeight: 700 }
const td = { padding: '.65rem 1rem', color: '#374151' }
