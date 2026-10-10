import { Link } from 'react-router-dom'

/** Shown when a detail page cannot load its record (deleted, wrong id, no access). */
export default function LoadFailed({ what = 'record', message, backTo, backLabel = 'Go back' }) {
  return (
    <div className="page" style={{ maxWidth: 520 }}>
      <div className="card card-body" style={{ textAlign: 'center', padding: '2rem' }}>
        <h3 style={{ marginTop: 0 }}>This {what} could not be loaded</h3>
        <p style={{ color: 'var(--text-3)' }}>{message ? `${String(message).replace(/\.$/, '')}. ` : ''}It may have been deleted, or you may not have access.</p>
        {backTo && <Link className="btn btn-primary" to={backTo}>{backLabel}</Link>}
      </div>
    </div>
  )
}
