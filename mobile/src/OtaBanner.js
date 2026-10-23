import { useEffect, useState } from 'react'
import { TouchableOpacity, AppState } from 'react-native'
import { Text } from './T'
import * as Updates from 'expo-updates'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Icon } from './ui'
import { c } from './theme'

/**
 * Over-the-air updates: fixes to the app's screens and logic arrive by themselves, with no new APK. The app asks the update server when it
 * opens and whenever it comes back to the front (at most every 10 minutes); a found update is downloaded quietly and this bar offers
 * "restart now". Nothing is ever restarted while someone is typing. Changes to native parts (new permissions, new modules) still need a new
 * APK: those builds have a different runtimeVersion (app.json) and never receive an update meant for another one.
 */
export default function OtaBanner({ signedIn }) {
  const [ready, setReady] = useState(false)
  const inset = useSafeAreaInsets()

  useEffect(() => {
    if (__DEV__ || !Updates.isEnabled) return
    let dead = false, last = 0
    const check = async () => {
      if (dead || Date.now() - last < 10 * 60 * 1000) return
      last = Date.now()
      try {
        const r = await Updates.checkForUpdateAsync()
        if (!r.isAvailable) return
        const f = await Updates.fetchUpdateAsync()
        if (!dead && f.isNew) setReady(true)
      } catch { /* offline or no update server: try again next time */ }
    }
    check()
    const sub = AppState.addEventListener('change', st => { if (st === 'active') check() })
    return () => { dead = true; sub.remove() }
  }, [signedIn])

  if (!ready) return null
  return (
    <TouchableOpacity activeOpacity={0.8} onPress={() => Updates.reloadAsync().catch(() => {})}
      style={{ backgroundColor: c.primarySoft, paddingTop: inset.top + 6, paddingBottom: 8, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 8 }}>
      <Icon name="sparkles" size={16} color={c.primary} />
      <Text style={{ color: c.primary, fontSize: 12.5, fontWeight: '700', flex: 1 }}>A quick update is ready. Tap to restart and get it.</Text>
    </TouchableOpacity>)
}
