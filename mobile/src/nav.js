import { createNavigationContainerRef } from '@react-navigation/native'

export const navRef = createNavigationContainerRef()

let pending = null   // a tap that arrived before the navigator (or the login) was ready

/** Screens a notification may open. Anything else falls back to the inbox. */
const STACK = { Trip: 'Trip', Booking: 'Booking', Contact: 'Contact' }

export function openTarget(data) {
  const screen = data?.screen
  const params = data?.params && typeof data.params === 'object' ? data.params : {}
  if (!navRef.isReady()) { pending = data; return }
  pending = null
  if (screen && STACK[screen] && params.id) navRef.navigate(STACK[screen], { id: Number(params.id) })
  else navRef.navigate('Home', { screen: 'Inbox' })
}

/** Call once the user is signed in and the navigator is mounted. */
export function flushPending() {
  if (pending && navRef.isReady()) openTarget(pending)
}

/** In-app notification rows carry a WEB route (/trips/7); map it to a phone screen. */
export function linkToTarget(link) {
  const m = String(link || '').match(/^\/(trips|bookings|contacts)\/(\d+)/)
  if (!m) return null
  return { screen: { trips: 'Trip', bookings: 'Booking', contacts: 'Contact' }[m[1]], params: { id: Number(m[2]) } }
}
