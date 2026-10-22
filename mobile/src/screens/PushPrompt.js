import { Modal, View, Text, TouchableOpacity, StyleSheet } from 'react-native'
import { c, sp, radius, type, shadow } from '../theme'
import { Icon, Btn } from '../ui'

const ITEMS = [['person-add', 'A new lead arrives from your ads or website'], ['chatbubble-ellipses', 'A customer replies on WhatsApp'], ['eye', 'A customer opens or accepts your quote'], ['cash', 'A payment comes in or goes overdue']]

/**
 * OUR explanation, shown BEFORE the one-shot OS permission dialog. iOS only lets an app ask once; asking cold ("Allow
 * notifications?") gets refused far more often than asking after saying what the person gets.
 */
export default function PushPrompt({ visible, onEnable, onLater }) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onLater}>
      <View style={s.backdrop}>
        <View style={s.card}>
          <View style={s.bell}><Icon name="notifications" size={34} color={c.primary} /></View>
          <Text style={[type.h1, { fontSize: 23, textAlign: 'center', marginTop: sp.m }]}>Never miss a lead</Text>
          <Text style={[type.small, { textAlign: 'center', marginTop: 6, marginBottom: sp.l }]}>Hear the moment it happens:</Text>
          {ITEMS.map(([i, t]) => <View key={t} style={{ flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 7 }}><View style={s.dot}><Icon name={i} size={16} color={c.primary} /></View><Text style={{ color: c.text, flex: 1, fontSize: 14.5 }}>{t}</Text></View>)}
          <Text style={[type.small, { marginTop: sp.m, lineHeight: 18 }]}>Replying within minutes makes a big difference to who books. You choose exactly what you get and can set quiet hours in Settings.</Text>
          <Btn label="Turn on notifications" icon="notifications" onPress={onEnable} style={{ marginTop: sp.l }} />
          <TouchableOpacity style={{ padding: 14, alignItems: 'center' }} onPress={onLater}><Text style={{ color: c.sub, fontWeight: '600' }}>Not now</Text></TouchableOpacity>
        </View>
      </View>
    </Modal>)
}
const s = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(10,31,68,0.55)', justifyContent: 'center', padding: 22 },
  card: { backgroundColor: '#fff', borderRadius: 26, padding: 24, ...shadow },
  bell: { alignSelf: 'center', width: 72, height: 72, borderRadius: 36, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center' },
  dot: { width: 34, height: 34, borderRadius: 11, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center' },
})
