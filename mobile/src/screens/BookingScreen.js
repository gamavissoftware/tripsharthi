import { useState, useEffect, useCallback } from 'react'
import { View, Text, ScrollView, TouchableOpacity, Alert, Share, StyleSheet } from 'react-native'
import { api, inr } from '../api'
import { c } from '../theme'

export default function BookingScreen({ route }) {
  const [b, setB] = useState(null)
  const load = useCallback(() => api.booking(route.params.id).then(setB).catch(e => Alert.alert('Could not load', e.message)), [route.params.id])
  useEffect(() => { load() }, [load])
  if (!b) return <Text style={{ padding: 20 }}>Loading…</Text>

  const sendLink = (p) => api.paymentLink(p.id).then(l => Share.share({ message: `Payment link for ${b.booking_ref} – ${p.label}: ${l.short_url}` })).catch(e => Alert.alert('Could not create link', e.message))
  const remind = (p) => api.remind(p.id).then(() => { Alert.alert('Reminder sent', 'The customer was reminded on WhatsApp.'); load() }).catch(e => Alert.alert('Reminder not sent', e.message))
  const markPaid = (p) => Alert.alert('Mark as paid?', `${p.label} · ${inr(p.amount)}`, [
    { text: 'Cancel', style: 'cancel' },
    ...['upi', 'bank', 'cash'].map(mode => ({ text: mode.toUpperCase(), onPress: () => api.markPaid(p.id, mode).then(load).catch(e => Alert.alert('Failed', e.message)) })),
  ])
  return (
    <ScrollView style={{ flex: 1, backgroundColor: c.bg }} contentContainerStyle={{ padding: 14, gap: 12 }}>
      <View style={s.card}>
        <Text style={s.h}>{b.booking_ref}</Text><Text style={{ color: c.sub }}>{b.title}</Text>
        <Text style={{ marginTop: 8, color: c.text }}>Total {inr(b.total_amount)} · Paid {inr(b.paid_amount)} · <Text style={{ color: b.due_amount > 0 ? c.danger : c.ok, fontWeight: '800' }}>Due {inr(b.due_amount)}</Text></Text>
      </View>
      <View style={s.card}>
        <Text style={s.h2}>Payments</Text>
        {b.payments.map(p => (
          <View key={p.id} style={s.row}>
            <View style={{ flex: 1 }}><Text style={{ fontWeight: '700', color: c.text }}>{p.label}</Text><Text style={{ color: c.sub }}>Due {p.due_date} · {p.status}</Text></View>
            <Text style={{ fontWeight: '800', marginRight: 8 }}>{inr(p.amount)}</Text>
            {p.status !== 'paid' && p.status !== 'waived' && (
              <View style={{ flexDirection: 'row', gap: 6 }}>
                <TouchableOpacity style={s.ghost} onPress={() => sendLink(p)}><Text>🔗</Text></TouchableOpacity>
                <TouchableOpacity style={s.ghost} onPress={() => remind(p)}><Text>💬</Text></TouchableOpacity>
                <TouchableOpacity style={s.pay} onPress={() => markPaid(p)}><Text style={{ color: '#fff', fontWeight: '700' }}>Paid</Text></TouchableOpacity>
              </View>)}
          </View>))}
      </View>
      <View style={s.card}>
        <Text style={s.h2}>Supplier services</Text>
        {b.services.map(x => <View key={x.id} style={s.row}><Text style={{ flex: 1, color: c.text }}>{x.title}</Text><Text style={{ color: x.status === 'confirmed' ? c.ok : c.warn, fontWeight: '700' }}>{x.status.replace('_', ' ')}</Text></View>)}
      </View>
    </ScrollView>
  )
}
const s = StyleSheet.create({
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border },
  h: { fontSize: 18, fontWeight: '800', color: c.text }, h2: { fontSize: 15, fontWeight: '800', marginBottom: 8, color: c.text },
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: 8, borderTopWidth: StyleSheet.hairlineWidth, borderColor: c.border },
  ghost: { borderWidth: 1, borderColor: c.border, paddingHorizontal: 10, paddingVertical: 7, borderRadius: 10 },
  pay: { backgroundColor: c.ok, paddingHorizontal: 12, paddingVertical: 7, borderRadius: 10 },
})
