/** Ready-made replies for the support inbox. {name} becomes the customer's first name. Edit the wording here. */
export const SUPPORT_TEMPLATES = [
  { key: 'looking', label: 'Looking into it', text: "Hi {name},\n\nThanks for getting in touch. We're looking into this now and will update you here shortly." },
  { key: 'details', label: 'Need more details', text: "Hi {name},\n\nTo find the cause quickly, could you tell us:\n1. Which page you were on\n2. What you expected to happen\n3. What happened instead\n\nA screenshot helps a lot. Just reply here." },
  { key: 'whatsapp', label: 'Reconnect WhatsApp', text: "Hi {name},\n\nPlease reconnect WhatsApp from Settings → WhatsApp: remove the number, then add it again with a fresh access token from Meta Business Manager. Let us know if it still shows an error and we'll take a look from our side." },
  { key: 'invoice', label: 'Where are my invoices', text: "Hi {name},\n\nEvery GST invoice is e-mailed to you when you pay, and you can download them any time under Settings → Billing → GST invoices. If you want your GSTIN printed on future invoices, add it under Settings → Business & invoicing before your next payment." },
  { key: 'policy', label: 'Refund policy', text: "Hi {name},\n\nOur fees are non-refundable once a plan period starts, but you can cancel any time and your plan simply runs to the end of the period you paid for. If you were charged by mistake (for example twice), tell us the details and we'll correct it." },
  { key: 'fixed', label: 'Fixed, please check', text: "Hi {name},\n\nWe've fixed this on our side. Could you try again and let us know if it works? If it still doesn't, reply here and we'll keep looking." },
  { key: 'closing', label: 'Resolved, closing', text: "Hi {name},\n\nGlad that's sorted. We'll mark this as resolved. If anything else comes up, just reply or open a new request from Help & support." },
]

export const firstName = (full) => { const f = String(full || '').trim().split(/\s+/)[0]; return f.length >= 2 ? f : 'there' }
export const fillTemplate = (t, customerName) => t.text.replace(/\{name\}/g, firstName(customerName))

/** 90 -> "1h 30m", 45 -> "45 min", null -> "—" */
export function minutesText(m) {
  if (m == null) return '—'
  if (m < 60) return `${m} min`
  const h = Math.floor(m / 60), r = m % 60
  return r === 0 ? `${h}h` : `${h}h ${r}m`
}
