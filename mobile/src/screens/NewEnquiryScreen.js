import { useState } from 'react'
import { View, Text, Alert, Platform } from 'react-native'
import { api } from '../api'
import { sendOrQueue } from '../outbox'
import { c, sp, type } from '../theme'
import { Screen, Card, Input, Btn, Chip, ok } from '../ui'
import { validateEnquiry, indiaToday } from '../logic'
import DateField from '../DateField'

const blank = { name: '', phone: '', destination: '', start_date: '', nights: '', adults: '2', children: '0', budget_rs: '', notes: '' }
const POPULAR = ['Bali', 'Dubai', 'Thailand', 'Goa', 'Kerala', 'Kashmir', 'Singapore', 'Maldives']

export default function NewEnquiryScreen({ navigation }) {
  const [f, setF] = useState(blank)
  const [errs, setErrs] = useState({})
  const [busy, setBusy] = useState(false)
  const set = (k) => (v) => { setF(x => ({ ...x, [k]: v })); setErrs(e => ({ ...e, [k]: undefined })) }

  async function submit() {
    if (busy) return
    const e = validateEnquiry({ ...f, adults: Number(f.adults) })
    setErrs(e)
    if (Object.keys(e).length) return
    setBusy(true)
    try {
      const q = await sendOrQueue('enquiry', { ...f, adults: Number(f.adults), children: Number(f.children) || 0, nights: f.nights === '' ? undefined : Number(f.nights), budget_rs: f.budget_rs === '' ? undefined : Number(f.budget_rs) }, f.name.trim())
      ok()
      if (q.queued) { Alert.alert('Saved on your phone', 'No internet right now. This enquiry will be created automatically as soon as you are back online.'); navigation.goBack(); return }
      const r = q.data
      if (r.existing) Alert.alert('Already open', 'This customer already has an open enquiry for this trip, so we opened it instead of creating a duplicate.')
      navigation.replace('Trip', { id: Number(r.trip.id) })
    } catch (er) { Alert.alert('Could not save', er.message) } finally { setBusy(false) }
  }
  return (
    <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>
      <Card>
        <Text style={[type.h3, { marginBottom: sp.m }]}>Who is travelling?</Text>
        <Input label="Customer name" value={f.name} onChangeText={set('name')} error={errs.name} autoCapitalize="words" placeholder="Rohit Sharma" />
        <Input label="Mobile / WhatsApp number" value={f.phone} onChangeText={set('phone')} error={errs.phone} keyboardType="phone-pad" placeholder="98765 43210" style={{ marginBottom: 0 }} />
      </Card>
      <Card>
        <Text style={[type.h3, { marginBottom: sp.m }]}>Where and when?</Text>
        <Input label="Destination" value={f.destination} onChangeText={set('destination')} placeholder="Bali, Kashmir, Goa…" autoCapitalize="words" style={{ marginBottom: sp.s }} />
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: sp.m }}>{POPULAR.map(p => <Chip key={p} label={p} active={f.destination === p} onPress={() => set('destination')(p)} />)}</View>
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <DateField label="Travel date" value={f.start_date} onChange={set('start_date')} error={errs.start_date} minimumDate={new Date()} clearable placeholder="Choose a date (optional)" style={{ flex: 1.6 }} />
          <Input label="Nights" value={f.nights} onChangeText={set('nights')} error={errs.nights} keyboardType="number-pad" maxLength={2} placeholder="5" style={{ flex: 1 }} />
        </View>
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <Input label="Adults" value={f.adults} onChangeText={set('adults')} error={errs.adults} keyboardType="number-pad" maxLength={2} style={{ flex: 1 }} />
          <Input label="Children" value={f.children} onChangeText={set('children')} keyboardType="number-pad" maxLength={2} style={{ flex: 1 }} />
          <Input label="Budget ₹" value={f.budget_rs} onChangeText={set('budget_rs')} keyboardType="number-pad" placeholder="150000" style={{ flex: 1.4 }} />
        </View>
        <Input label="Notes" value={f.notes} onChangeText={set('notes')} multiline placeholder="Honeymoon, vegetarian, wants a villa…" style={{ marginBottom: 0 }} />
      </Card>
      <Btn label="Create enquiry" icon="checkmark-circle" onPress={submit} busy={busy} />
    </Screen>)
}
