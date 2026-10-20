import { cloneElement, useId } from 'react'
import { AlertCircle, CheckCircle2 } from 'lucide-react'

/**
 * A labelled form field with inline validation: required marker, red highlight + message when `error` is set, green tick when `valid`,
 * optional hint. Wrap ONE input/select/textarea; the wrapper adds the id, aria-invalid and the highlight class to it.
 * Pair with `firstInvalid(formEl)` (lib/formFocus.js) to jump to the first problem on submit.
 */
export default function Field({ label, required, error, valid, validText, hint, prefix, children, style }) {
  const id = useId()
  const input = cloneElement(children, {
    id,
    'aria-invalid': error ? 'true' : undefined,
    'aria-describedby': error ? id + '-e' : undefined,
    className: [children.props.className, error ? 'is-invalid' : '', !error && valid ? 'is-valid' : ''].filter(Boolean).join(' '),
  })
  return (
    <div className="ff" style={style}>
      {label && <label className="ff-l" htmlFor={id}>{label}{required && <i className="ff-req" title="Required"> *</i>}</label>}
      {prefix ? <div style={{ display: 'flex', gap: 4 }}>{prefix}{input}</div> : input}
      {error ? <div className="ff-err" id={id + '-e'} role="alert"><AlertCircle size={13} />{error}</div>
        : valid && validText ? <div className="ff-ok"><CheckCircle2 size={13} />{validText}</div>
        : hint ? <div className="ff-hint">{hint}</div> : null}
    </div>
  )
}
