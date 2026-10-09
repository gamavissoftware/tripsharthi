import { useState } from 'react'
import { View, Text, TextInput, TouchableOpacity, ScrollView, Alert, StyleSheet } from 'react-native'
import { api } from '../api'
import { c } from '../theme'
import { validateEnquiry } from '../logic'

const blank = { name: '', phone: '', destination: '', start_date: '', nights: '', adults: '2', children: '0', budget_rs: '', notes: '' }

function Field({ f, set, errs, k, label, ...p }) {
  return (
    <View style={{ flex: 1 }}>
      <Text style={s.l}>{label}</Text>
      <TextInput style={[s.input, errs[k] && { borderColor: c.danger }]} value={f[k]} onChangeText={set(k)} {...p} />
      {!!errs[k] && <Text style={{ color: c.danger, fontSize: 12 }}>{errs[k]}</Text>}
    </View>)
}

export default function NewEnquiryScreen({ navigation }) {
  const [f, setF] = useState(blank)
  const [errs, setErrs] = useState({})
  const [busy, setBusy] = useState(false)
  const set = (k) => (v) => setF(x => ({ ...x, [k]: v }))

  async function submit() {
    if (busy) return
    const e = validateEnquiry({ ...f, adults: Number(f.adults) })
    setErrs(e)
    if (Object.keys(e).length) return
    setBusy(true)
    try {
      const r = await api.quickEnquiry({ ...f, adults: Number(f.adults), children: Number(f.children) || 0, nights: f.nights === '' ? undefined : Number(f.nights), budget_rs: f.budget_rs === '' ? undefined : Number(f.budget_rs) })
      if (r.existing) Alert.alert('Already open', 'This customer already has an open enquiry for this trip, so we opened it instead of creating a duplicate.')
      navigation.replace('Trip', { id: r.trip.id })
    } catch (er) { Alert.alert('Could not save', er.message) } finally { setBusy(false) }
  }
  const ctx = { f, set, errs }
  return (
    <ScrollView style={{ flex: 1, backgroundColor: c.bg }} contentContainerStyle={{ padding: 14, gap: 12 }} keyboardShouldPersistTaps="handled">
      <View style={s.card}>
        <Field {...ctx} k="name" label="Customer name *" autoCapitalize="words" />
        <Field {...ctx} k="phone" label="Mobile / WhatsApp number *" keyboardType="phone-pad" placeholder="98765 43210" />
      </View>
      <View style={s.card}>
        <Field {...ctx} k="destination" label="Destination" placeholder="Bali, Kashmir, Goa…" autoCapitalize="words" />
        <View style={{ flexDirection: 'row', gap: 10 }}><Field {...ctx} k="start_date" label="Travel date" placeholder="YYYY-MM-DD" keyboardType="numbers-and-punctuation" maxLength={10} /><Field {...ctx} k="nights" label="Nights" keyboardType="number-pad" maxLength={2} /></View>
        <View style={{ flexDirection: 'row', gap: 10 }}><Field {...ctx} k="adults" label="Adults" keyboardType="number-pad" maxLength={2} /><Field {...ctx} k="children" label="Children" keyboardType="number-pad" maxLength={2} /><Field {...ctx} k="budget_rs" label="Budget ₹ (total)" keyboardType="number-pad" /></View>
        <Field {...ctx} k="notes" label="Notes" multiline />
      </View>
      <TouchableOpacity style={[s.btn, busy && { opacity: 0.6 }]} disabled={busy} onPress={submit}><Text style={{ color: '#fff', fontWeight: '800' }}>{busy ? 'Saving…' : 'Create enquiry'}</Text></TouchableOpacity>
    </ScrollView>
  )
}
const s = StyleSheet.create({
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border, gap: 10 },
  l: { color: c.sub, fontSize: 12, marginBottom: 4, fontWeight: '600' }, input: { backgroundColor: '#fff', borderWidth: 1, borderColor: c.border, borderRadius: 10, padding: 10, color: c.text },
  btn: { backgroundColor: c.primary, borderRadius: 12, padding: 15, alignItems: 'center' },
})
