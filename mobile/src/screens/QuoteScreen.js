import { useState, useEffect, useCallback } from 'react'
import { View, Text, ScrollView, TextInput, TouchableOpacity, Linking, Share, Alert, StyleSheet } from 'react-native'
import { api, inr } from '../api'
import { c } from '../theme'
import { digits } from '../logic'

/** Draft a quote with AI, check the price, adjust markup / discount, and send it — all from the phone. */
export default function QuoteScreen({ route, navigation }) {
  const { tripId, itineraryId } = route.params
  const [trip, setTrip] = useState(null)
  const [it, setIt] = useState(null)
  const [busy, setBusy] = useState('')
  const [markup, setMarkup] = useState('')
  const [discount, setDiscount] = useState('')

  const show = useCallback((x) => { setIt(x); setMarkup(String(x.markup_type === 'percent' ? Number(x.markup_value) : '')); setDiscount(String(Math.round(Number(x.discount_amount || 0) / 100) || '')) }, [])
  useEffect(() => {
    api.trip(tripId).then(setTrip).catch(e => Alert.alert('Could not load', e.message))
    if (itineraryId) api.itinerary(itineraryId).then(show).catch(e => Alert.alert('Could not load the quote', e.message))
  }, [tripId, itineraryId, show])

  async function draft() {
    setBusy('ai')
    try { const x = await api.aiItinerary(tripId); show(x.id && x.days ? x : await api.itinerary(x.id)) }
    catch (e) { Alert.alert('Could not draft', e.message) } finally { setBusy('') }
  }
  async function reprice() {
    const m = Number(markup), d = Number(discount || 0)
    if (!(m >= 0 && m <= 200)) return Alert.alert('Markup', 'Enter a markup between 0 and 200 %.')
    if (!(d >= 0)) return Alert.alert('Discount', 'Discount cannot be negative.')
    setBusy('price')
    try { show(await api.updateItinerary(it.id, { markup_type: 'percent', markup_value: m, discount_amount: Math.round(d * 100) })) }
    catch (e) { Alert.alert('Could not update', e.message) } finally { setBusy('') }
  }
  async function send() {
    setBusy('send')
    try {
      const r = await api.shareItinerary(it.id)
      const phone = trip?.contact?.wa_number
      const msg = `Hi ${trip?.contact?.name || ''}, here is your ${trip?.destination_text || 'travel'} itinerary & quote: ${r.url}`
      if (phone) Linking.openURL(`https://wa.me/${digits(phone)}?text=${encodeURIComponent(msg)}`); else Share.share({ message: msg })
    } catch (e) { Alert.alert('Could not share', e.message) } finally { setBusy('') }
  }

  if (!it) return (
    <View style={s.wrap}><View style={[s.card, { margin: 14 }]}>
      <Text style={s.h}>No quote yet</Text>
      <Text style={{ color: c.sub, marginVertical: 8 }}>{trip ? `Let AI draft a day-by-day itinerary for ${trip.destination_text || 'this trip'} from the enquiry. Prices come only from your supplier rate cards — the AI never invents a price.` : 'Loading…'}</Text>
      <TouchableOpacity style={[s.btn, busy === 'ai' && { opacity: 0.6 }]} disabled={busy === 'ai' || !trip} onPress={draft}><Text style={s.btnT}>{busy === 'ai' ? 'Drafting… (up to a minute)' : '✨ Draft with AI'}</Text></TouchableOpacity>
      <Text style={{ color: c.sub, marginTop: 10, fontSize: 12 }}>For fully manual quotes with your own line items, use the web app.</Text>
    </View></View>)

  const locked = ['accepted'].includes(it.status)
  return (
    <ScrollView style={s.wrap} contentContainerStyle={{ padding: 14, gap: 12 }} keyboardShouldPersistTaps="handled">
      <View style={s.card}>
        <Text style={s.h}>{it.title}</Text>
        <Text style={{ color: c.sub }}>{it.nights}N · {it.adults} adults{Number(it.children) ? `, ${it.children} children` : ''} · v{it.version} · {it.status}</Text>
      </View>
      <View style={s.card}>
        <Text style={s.h2}>Price</Text>
        <Row k="Price before tax" v={inr(it.sell_subtotal)} /><Row k={`GST ${Number(it.gst_rate)}%`} v={inr(it.gst_amount)} />
        {Number(it.tcs_amount) > 0 && <Row k={`TCS ${Number(it.tcs_rate)}%`} v={inr(it.tcs_amount)} />}
        <Row k="Total to customer" v={inr(it.grand_total)} bold />
        <Row k="Your margin" v={inr(it.margin_amount)} muted />
        {!locked && <View style={{ marginTop: 10, flexDirection: 'row', gap: 10, alignItems: 'flex-end' }}>
          <View style={{ flex: 1 }}><Text style={s.l}>Markup %</Text><TextInput style={s.input} keyboardType="decimal-pad" value={markup} onChangeText={setMarkup} /></View>
          <View style={{ flex: 1 }}><Text style={s.l}>Discount ₹</Text><TextInput style={s.input} keyboardType="number-pad" value={discount} onChangeText={setDiscount} /></View>
          <TouchableOpacity style={[s.btn, { paddingHorizontal: 16 }, busy === 'price' && { opacity: 0.6 }]} disabled={busy === 'price'} onPress={reprice}><Text style={s.btnT}>Update</Text></TouchableOpacity>
        </View>}
        {locked && <Text style={{ color: c.sub, marginTop: 8 }}>This quote was accepted, so the price is locked. Create a new version in the web app to change it.</Text>}
      </View>
      {(it.days || []).map(d => (
        <View key={d.id} style={s.card}>
          <Text style={s.h2}>Day {d.day_no}: {d.title}</Text>
          {!!d.description && <Text style={{ color: c.sub, marginBottom: 4 }} numberOfLines={3}>{d.description}</Text>}
          {(d.items || []).map(i => <Text key={i.id} style={{ color: c.text, paddingVertical: 2 }}>• {i.title}</Text>)}
        </View>))}
      <TouchableOpacity style={[s.btn, { backgroundColor: '#25D366' }, busy === 'send' && { opacity: 0.6 }]} disabled={busy === 'send'} onPress={send}><Text style={s.btnT}>Send quote on WhatsApp</Text></TouchableOpacity>
      <Text style={{ color: c.sub, fontSize: 12, textAlign: 'center' }}>Open the web app to add or edit individual line items, hotels or flights.</Text>
    </ScrollView>
  )
}
function Row({ k, v, bold, muted }) {
  return <View style={s.kv}><Text style={[s.k, muted && { fontSize: 12 }]}>{k}</Text><Text style={[s.v, bold && { fontSize: 17 }, muted && { color: c.sub, fontWeight: '500' }]}>{v}</Text></View>
}
const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: c.bg }, card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border },
  h: { fontSize: 18, fontWeight: '800', color: c.text }, h2: { fontSize: 15, fontWeight: '800', marginBottom: 8, color: c.text },
  kv: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 5, borderBottomWidth: StyleSheet.hairlineWidth, borderColor: c.border }, k: { color: c.sub }, v: { color: c.text, fontWeight: '700' },
  l: { color: c.sub, fontSize: 12, marginBottom: 4, fontWeight: '600' }, input: { backgroundColor: '#fff', borderWidth: 1, borderColor: c.border, borderRadius: 10, padding: 10 },
  btn: { backgroundColor: c.primary, borderRadius: 12, padding: 14, alignItems: 'center' }, btnT: { color: '#fff', fontWeight: '800' },
})
