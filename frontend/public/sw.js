/* TravelPilot push service worker — shows reply alerts + focuses the inbox on click. */
self.addEventListener('push', (event) => {
  let data = {}
  try { data = event.data ? event.data.json() : {} } catch (e) { data = {} }
  const title = data.title || 'New reply'
  const options = {
    body: data.body || 'A customer replied to you.',
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    data: { url: data.url || '/#/inbox' },
    tag: 'lp-reply',
    renotify: true,
  }
  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = (event.notification.data && event.notification.data.url) || '/#/inbox'
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if ('focus' in c) { c.navigate(url); return c.focus() }
      }
      if (clients.openWindow) return clients.openWindow(url)
    })
  )
})
