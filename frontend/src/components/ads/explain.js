/** Explain a refused ad action in the user's terms: every spend-guardrail violation is listed; platform errors pass through. */
export function explain(e) {
  const v = e?.data?.violations
  return v?.length ? v.join('\n') : e.message
}
