import { Modal, View, Text, TouchableOpacity, StyleSheet } from 'react-native'
import { c } from '../theme'

/**
 * OUR explanation, shown BEFORE the one-shot OS permission dialog. iOS only lets an app ask once; asking cold ("Allow
 * notifications?") gets refused far more often than asking after saying what the person gets.
 */
export default function PushPrompt({ visible, onEnable, onLater }) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onLater}>
      <View style={s.backdrop}>
        <View style={s.card}>
          <Text style={{ fontSize: 40, textAlign: 'center' }}>🔔</Text>
          <Text style={s.h}>Never miss a lead</Text>
          <Text style={s.p}>Turn on notifications to hear the moment:</Text>
          {['A new lead arrives from your ads or website', 'A customer replies on WhatsApp', 'A customer opens or accepts your quote', 'A payment comes in or goes overdue'].map(t => <Text key={t} style={s.li}>•  {t}</Text>)}
          <Text style={[s.p, { marginTop: 8 }]}>Replying within minutes makes a big difference to who books. You can choose exactly what you get, and set quiet hours, in Settings.</Text>
          <TouchableOpacity style={s.btn} onPress={onEnable}><Text style={s.btnT}>Turn on notifications</Text></TouchableOpacity>
          <TouchableOpacity style={{ padding: 12, alignItems: 'center' }} onPress={onLater}><Text style={{ color: c.sub }}>Not now</Text></TouchableOpacity>
        </View>
      </View>
    </Modal>
  )
}
const s = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,.5)', justifyContent: 'center', padding: 24 },
  card: { backgroundColor: '#fff', borderRadius: 20, padding: 22 },
  h: { fontSize: 22, fontWeight: '800', textAlign: 'center', color: c.text, marginVertical: 6 },
  p: { color: c.sub, marginBottom: 4 }, li: { color: c.text, paddingVertical: 2 },
  btn: { backgroundColor: c.primary, padding: 15, borderRadius: 12, alignItems: 'center', marginTop: 14 }, btnT: { color: '#fff', fontWeight: '700', fontSize: 16 },
})
