import { View } from 'react-native'
import { Text } from './T'
import { ageLabel } from './logic'
import { Icon } from './ui'
import { c } from './theme'

/** Shown whenever the screen is displaying SAVED data instead of live data: never pass stale data off as current. */
export default function OfflineBanner({ stale, at }) {
  if (!stale) return null
  return (
    <View style={{ backgroundColor: '#fff4de', paddingVertical: 8, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 8 }}>
      <Icon name="cloud-offline" size={16} color={c.warn} />
      <Text style={{ color: c.warn, fontSize: 12.5, flex: 1 }}>Offline. Showing saved data{at ? ` from ${ageLabel(at)}` : ''}. Pull down to retry.</Text>
    </View>)
}
