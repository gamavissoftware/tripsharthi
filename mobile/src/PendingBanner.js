import { useEffect, useState } from 'react'
import { View, Text, TouchableOpacity, AppState, Alert } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { onOutboxChange, outboxCount, flushOutbox, outboxFailed, outboxDismissFailed } from './outbox'
import { Icon } from './ui'
import { c } from './theme'

/** Items saved on the phone while offline wait here and are sent again by themselves: when the app opens, every 30 s, and on "Send now". */
export default function PendingBanner({ signedIn }) {
  const [n, setN] = useState(0)
  const [busy, setBusy] = useState(false)
  const inset = useSafeAreaInsets()

  useEffect(() => {
    if (!signedIn) { setN(0); return }
    let dead = false
    const run = async () => {
      if (dead) return
      const r = await flushOutbox().catch(() => null)
      if (r && r.sent > 0) Alert.alert('Back online', r.sent === 1 ? '1 item saved earlier has been sent.' : `${r.sent} items saved earlier have been sent.`)
      const failed = await outboxFailed()
      if (!dead && failed.length) {
        await outboxDismissFailed()
        Alert.alert('Some items could not be sent', failed.map(f => `${f.summary}: ${f.error}`).join('\n'))
      }
    }
    outboxCount().then(x => !dead && setN(x)); run()
    const off = onOutboxChange(x => !dead && setN(x))
    const timer = setInterval(() => { outboxCount().then(x => { if (x > 0) run() }) }, 30000)
    const sub = AppState.addEventListener('change', st => { if (st === 'active') run() })
    return () => { dead = true; off(); clearInterval(timer); sub.remove() }
  }, [signedIn])

  if (!n) return null
  return (
    <TouchableOpacity activeOpacity={0.8} disabled={busy} onPress={async () => { setBusy(true); await flushOutbox().catch(() => {}); setBusy(false) }}
      style={{ backgroundColor: c.warnSoft, paddingTop: inset.top + 6, paddingBottom: 8, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 8 }}>
      <Icon name="cloud-upload" size={16} color={c.warn} />
      <Text style={{ color: c.warn, fontSize: 12.5, flex: 1 }}>{n === 1 ? '1 item is waiting to be sent' : `${n} items are waiting to be sent`}. {busy ? 'Sending…' : 'Tap to send now.'}</Text>
    </TouchableOpacity>)
}
