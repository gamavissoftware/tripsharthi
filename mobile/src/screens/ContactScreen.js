import { useState, useEffect } from 'react'
import { View, Text, ScrollView, TouchableOpacity, Linking, Alert, StyleSheet } from 'react-native'
import { contactApi, api } from '../api'
import { c } from '../theme'

const digits = (s) => String(s || '').replace(/\D/g, '')

/** Where a "new lead" / "customer replied" notification lands: who it is, and one tap to call or WhatsApp them. */
export default function ContactScreen({ route, navigation }) {
  const { id } = route.params
  const [ct, setCt] = useState(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { contactApi.get(id).then(setCt).catch(e => Alert.alert('Could not load contact', e.message)) }, [id])
  if (!ct) return <Text style={{ padding: 20 }}>Loading…</Text>

  const phone = ct.wa_number
  async function createEnquiry() {
    setBusy(true)
    try { const t = await api.createTrip({ title: `${ct.name || 'New'} enquiry`, contact_id: ct.id, destination_text: '' }); navigation.replace('Trip', { id: t.id }) }
    catch (e) { Alert.alert('Could not create enquiry', e.message) } finally { setBusy(false) }
  }
  const Row = ({ k, v }) => v ? <View style={s.kv}><Text style={s.k}>{k}</Text><Text style={s.v}>{v}</Text></View> : null

  return (
    <ScrollView style={{ flex: 1, backgroundColor: c.bg }} contentContainerStyle={{ padding: 14, gap: 12 }}>
      <View style={s.card}>
        <Text style={s.h}>{ct.name || phone}</Text>
        <Text style={{ color: c.sub }}>{[ct.lifecycle_stage, ct.source?.replace(/_/g, ' ')].filter(Boolean).join(' · ')}</Text>
        {!!phone && (
          <View style={{ flexDirection: 'row', gap: 10, marginTop: 12 }}>
            <TouchableOpacity style={[s.btn, { backgroundColor: c.ok }]} onPress={() => Linking.openURL(`tel:${phone}`)}><Text style={s.btnT}>📞 Call</Text></TouchableOpacity>
            <TouchableOpacity style={[s.btn, { backgroundColor: '#25D366' }]} onPress={() => Linking.openURL(`https://wa.me/${digits(phone)}`)}><Text style={s.btnT}>💬 WhatsApp</Text></TouchableOpacity>
          </View>)}
      </View>
      <View style={s.card}>
        <Text style={s.h2}>Details</Text>
        <Row k="Phone" v={phone} /><Row k="Email" v={ct.email} /><Row k="City" v={[ct.city, ct.state].filter(Boolean).join(', ')} />
        <Row k="Interested in" v={ct.requirement_type} /><Row k="Budget" v={ct.budget_amount ? String(ct.budget_amount) : ''} /><Row k="Timeline" v={ct.timeline} />
        {!!ct.remarks && <Text style={{ marginTop: 8, color: c.text }}>{ct.remarks}</Text>}
      </View>
      <TouchableOpacity style={[s.btn, { backgroundColor: c.primary }]} disabled={busy} onPress={createEnquiry}><Text style={s.btnT}>{busy ? 'Creating…' : 'Create enquiry'}</Text></TouchableOpacity>
    </ScrollView>
  )
}
const s = StyleSheet.create({
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border },
  h: { fontSize: 20, fontWeight: '800', color: c.text }, h2: { fontSize: 15, fontWeight: '800', marginBottom: 8, color: c.text },
  kv: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 5, borderBottomWidth: StyleSheet.hairlineWidth, borderColor: c.border },
  k: { color: c.sub }, v: { color: c.text, fontWeight: '600', flexShrink: 1, textAlign: 'right' },
  btn: { flex: 1, padding: 14, borderRadius: 12, alignItems: 'center' }, btnT: { color: '#fff', fontWeight: '700' },
})
