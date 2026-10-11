import { useState, useEffect, Fragment } from 'react'
import { flows as flowsApi } from '../api/flows'

const STATUS_STYLE = {
  running:   { bg:'#e8f3fc', color:'#074a8c', label:'Running' },
  waiting:   { bg:'#fff4e5', color:'#b45309', label:'Waiting' },
  completed: { bg:'#e6f6ee', color:'#14753f', label:'Done' },
  stopped:   { bg:'#eef2f7', color:'#475569', label:'Stopped' },
  failed:    { bg:'#fdecea', color:'#b3261e', label:'Failed' },
}

function StatusBadge({ status }) {
  const s = STATUS_STYLE[status] ?? STATUS_STYLE.stopped
  return (
    <span style={{
      background: s.bg, color: s.color,
      padding:'3px 10px', borderRadius:999, fontSize:11.5, fontWeight:700, display:'inline-flex', alignItems:'center', gap:6,
    }}>
      <i style={{ width:6, height:6, borderRadius:'50%', background:s.color, display:'inline-block' }} />{s.label}
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
  if (runs.length === 0) return <div className="card" style={{ margin:20, padding:'40px 24px', color:'#5b6b86', textAlign:'center' }}><b style={{ display:'block', color:'#0a1f44', fontSize:16, marginBottom:4 }}>No runs yet</b>Activate the flow to see enrollments here.</div>

  return (
    <div className="card" style={{ overflow:'auto', margin:20 }}>
      <table className="fl-runs" style={{ width:'100%', borderCollapse:'collapse', fontSize:13.5 }}>
        <thead>
          <tr style={{ background:'#f3f8fd', borderBottom:'1px solid #dbe6f3' }}>
            {['Status','Contact','Current node','Steps','Entered'].map(h => (
              <th key={h} style={{ textAlign:'left', padding:'11px 14px', color:'#5b6b86', fontWeight:700, fontSize:11.5, textTransform:'uppercase', letterSpacing:.5 }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {runs.map(run => (
            <Fragment key={run.id}>
              <tr
                className="fl-run-row"
                style={{ borderBottom:'1px solid #f3f4f6', cursor:'pointer' }}
                onClick={() => toggleRun(run.id)}
              >
                <td style={{ padding:'11px 14px' }}><StatusBadge status={run.status} /></td>
                <td style={{ padding:'11px 14px' }}>
                  <div style={{ fontWeight:500 }}>{run.contact_name || '—'}</div>
                  <div style={{ color:'#9ca3af', fontSize:11 }}>{run.contact_wa}</div>
                </td>
                <td style={{ padding:'11px 14px', color:'#6b7280', fontFamily:'monospace', fontSize:11 }}>
                  {run.current_node_id || (run.status === 'completed' ? '(done)' : '—')}
                </td>
                <td style={{ padding:'11px 14px', color:'#6b7280' }}>{run.steps_executed}</td>
                <td style={{ padding:'11px 14px', color:'#9ca3af' }}>{timeAgo(run.entered_at)}</td>
              </tr>
              {expanded === run.id && logs.length > 0 && (
                <tr key={`logs-${run.id}`}>
                  <td colSpan={5} style={{ background:'#f7fafd', padding:'12px 18px', borderBottom:'1px solid #e6eef8' }}>
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
            </Fragment>
          ))}
        </tbody>
      </table>
    </div>
  )
}
