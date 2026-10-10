// Shared by the trip cards and the board filters, so "overdue" means the same thing everywhere.
// Task times are stored as typed (no time zone), so they are read as local time, like the Tasks page does.
const parse = (s) => (s ? new Date(String(s).replace(' ', 'T')) : null)

export function dueInfo(due, now = new Date()) {
  const d = parse(due)
  if (!d || isNaN(d)) return { txt: 'No date', overdue: false, dated: false }
  const day0 = new Date(now); day0.setHours(0, 0, 0, 0)
  const day = new Date(d); day.setHours(0, 0, 0, 0)
  const diff = Math.round((day - day0) / 86400000)
  let txt = d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
  if (diff === 0) txt = 'Today'
  else if (diff === 1) txt = 'Tomorrow'
  else if (diff === -1) txt = 'Yesterday'
  return { txt, overdue: d < now, dated: true, diff }
}

export const OPEN_STATUSES = ['enquiry', 'quoted', 'negotiating']

/** 'overdue' (earliest open task is past due) | 'planned' | 'none' (nothing scheduled) | null (not an open trip / no deal). */
export function activityState(trip, now = new Date()) {
  if (!OPEN_STATUSES.includes(trip.status) || !trip.deal_id) return null
  if (!(Number(trip.task_open) > 0) || !trip.task_next) return 'none'
  return dueInfo(trip.task_next.due_at, now).overdue ? 'overdue' : 'planned'
}
