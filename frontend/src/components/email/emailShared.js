/** Helpers shared by the email marketing pages. */

export const EMAIL_STATUS_BADGE = {
  draft:      'badge-draft',
  scheduled:  'badge-paused',
  processing: 'badge-contacted',
  paused:     'badge-paused',
  done:       'badge-active',
  failed:     'badge-lost',
  cancelled:  'badge-lost',
}

export const EMAIL_STATUS_LABEL = {
  processing: 'Sending', done: 'Sent',
}

/** The backend stores naive UTC strings; without the 'Z' the browser reads them as local time. */
export function fmtUtc(s) {
  if (!s) return '—'
  const d = new Date(String(s).replace(' ', 'T') + 'Z')
  return d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/** Segment JSON shapes are the ones the backend SegmentResolver reads. */
export function audienceKind(segment) {
  if (segment?.followup_of) return 'followup'
  if (!segment || segment.all) return 'all'
  if (segment.tag_ids) return 'tag'
  if (segment.statuses || segment.status) return 'status'
  if (segment.segment_id) return 'segment'
  return 'all'
}

export function audienceLabel(segment) {
  const kind = audienceKind(segment)
  if (kind === 'followup') {
    const who = { not_clicked: "who haven't clicked", not_opened: "who haven't opened", all: 'who received it' }[segment.engagement] ?? "who haven't clicked"
    return `Follow-up: people ${segment.name ? `"${segment.name}"` : 'the first email'} reached ${who}`
  }
  if (kind === 'tag')     return 'Tagged contacts'
  if (kind === 'status')  return `Status: ${(segment.statuses ?? [segment.status]).join(', ')}`
  if (kind === 'segment') return `Segment: ${segment.name ?? segment.segment_id}`
  return 'All contacts with an email'
}

export const ENGAGEMENT_OPTIONS = [
  { value: 'not_clicked', label: "Haven't clicked any email in the sequence" },
  { value: 'not_opened',  label: "Haven't opened any email in the sequence" },
  { value: 'all',         label: 'Everyone the first email reached' },
]

export const CONTACT_STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost']
