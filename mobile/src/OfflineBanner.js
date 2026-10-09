import { View, Text } from 'react-native'
import { ageLabel } from './logic'
import { c } from './theme'

/** Shown whenever the screen is displaying SAVED data instead of live data — never pass stale data off as current. */
export default function OfflineBanner({ stale, at }) {
  if (!stale) return null
  return (
    <View style={{ backgroundColor: '#fef3c7', paddingVertical: 6, paddingHorizontal: 12 }}>
      <Text style={{ color: '#92400e', fontSize: 12.5 }}>Offline — showing saved data{at ? ` from ${ageLabel(at)}` : ''}. Pull down to retry.</Text>
    </View>)
}
