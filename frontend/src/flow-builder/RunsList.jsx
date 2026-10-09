import { useState, useEffect } from 'react'
import { flows as flowsApi } from '../api/flows'

const STATUS_STYLE = {
  running:   { bg:'#eff6ff', color:'#1d4ed8', label:'running' },
  waiting:   { bg:'#fff7ed', color:'#c2410c', label:'waiting' },
  completed: { bg:'#f0fdf4', color:'#15803d', label:'done' },
  stopped:   { bg:'#f9fafb', color:'#374151', label:'stopped' },
  failed:    { bg:'#fef2f2', color:'#b91c1c', label:'failed' },
}

function StatusBadge({ status }) {
  const s = STATUS_STYLE[status] ?? STATUS_STYLE.stopped
  return (
    <span style={{
      background: s.bg, color: s.color,
      padding:'2px 8px', borderRadius:12, fontSize:11, fontWeight:600,
    }}>
      {s.label}
    </span>
  )
}

function timeAgo(dt) {
  if (!dt) return '—'
  const ms = Date.now() - new Date(dt).getTime()
  const m  = Math.floor(ms / 60000)
  if (m < 1)   return 'just now'
  if (m < 60)  return `${m}m ago`
  if (m < 1440) return `${Math.floor(m/60)}h ago`
  return `${Math.floor(m/1440)}d ago`
}

export default function RunsList({ flowId }) {
  const [runs, setRuns]       = useState([])
  const [expanded, setExpanded] = useState(null)
  const [logs, setLogs]       = useState([])
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!flowId) return
    setLoading(true)
    flowsApi.runs(flowId)
      .then(r => setRuns(r.data ?? []))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [flowId])

  async function toggleRun(runId) {
    if (expanded === runId) { setExpanded(null); return }
    setExpanded(runId)
    const r = await flowsApi.run(flowId, runId).catch(() => null)
    if (r) setLogs(r.logs ?? [])
  }

  if (loading) return <div style={{ padding:24, color:'#9ca3af', textAlign:'center' }}>Loading runs…</div>
  if (runs.length === 0) return <div style={{ padding:24, color:'#9ca3af', textAlign:'center' }}>No runs yet. Activate the flow to see enrollments here.</div>

  return (
    <div style={{ overflow:'auto' }}>
      <table style={{ width:'100%', borderCollapse:'collapse', fontSize:13 }}>
        <thead>
          <tr style={{ background:'#f9fafb', borderBottom:'1px solid #e5e7eb' }}>
            {['Status','Contact','Current node','Steps','Entered'].map(h => (
              <th key={h} style={{ textAlign:'left', padding:'8px 12px', color:'#6b7280', fontWeight:500 }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {runs.map(run => (
            <>
              <tr
                key={run.id}
                style={{ borderBottom:'1px solid #f3f4f6', cursor:'pointer' }}
                onClick={() => toggleRun(run.id)}
              >
                <td style={{ padding:'8px 12px' }}><StatusBadge status={run.status} /></td>
                <td style={{ padding:'8px 12px' }}>
                  <div style={{ fontWeight:500 }}>{run.contact_name || '—'}</div>
                  <div style={{ color:'#9ca3af', fontSize:11 }}>{run.contact_wa}</div>
                </td>
                <td style={{ padding:'8px 12px', color:'#6b7280', fontFamily:'monospace', fontSize:11 }}>
                  {run.current_node_id || (run.status === 'completed' ? '(done)' : '—')}
                </td>
                <td style={{ padding:'8px 12px', color:'#6b7280' }}>{run.steps_executed}</td>
                <td style={{ padding:'8px 12px', color:'#9ca3af' }}>{timeAgo(run.entered_at)}</td>
              </tr>
              {expanded === run.id && logs.length > 0 && (
                <tr key={`logs-${run.id}`}>
                  <td colSpan={5} style={{ background:'#f9fafb', padding:'8px 16px' }}>
                    <div style={{ fontSize:11, color:'#6b7280', marginBottom:4 }}>Execution log:</div>
                    {logs.map(log => (
                      <div key={log.id} style={{ display:'flex', gap:12, padding:'3px 0', fontSize:12 }}>
                        <span style={{ color:'#9ca3af', fontFamily:'monospace', minWidth:80 }}>{log.node_id}</span>
                        <span style={{ color:'#6b7280', minWidth:80 }}>{log.node_type}</span>
                        <span style={{
                          color: log.result === 'failed' ? '#dc2626' : log.result === 'blocked_window' ? '#f97316' : '#16a34a',
                          fontWeight:500
                        }}>{log.result}</span>
                        {log.detail && <span style={{ color:'#9ca3af', flex:1 }}>{log.detail}</span>}
                      </div>
                    ))}
                  </td>
                </tr>
              )}
            </>
          ))}
        </tbody>
      </table>
    </div>
  )
}
