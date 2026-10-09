import { useState, useEffect, useCallback } from 'react'
import { View, Text, ScrollView, TouchableOpacity, Linking, Share, Alert, StyleSheet } from 'react-native'
import { api, inr } from '../api'
import { c } from '../theme'
import { StatusPill } from './TripsScreen'
import OfflineBanner from '../OfflineBanner'

const digits = (s) => String(s || '').replace(/\D/g, '')

export default function TripScreen({ route, navigation }) {
  const { id } = route.params
  const [t, setT] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const load = useCallback(() => api.tripC(id).then(r => { setT(r.data); setMeta({ stale: r.stale, at: r.at }) }).catch(e => Alert.alert('Could not load trip', e.message)), [id])
  useEffect(() => { load() }, [load])
  if (!t) return <View style={s.wrap}><Text style={{ padding: 20 }}>Loading…</Text></View>

  const phone = t.contact?.wa_number
  const latest = t.itineraries?.[0]
  async function sendQuote() {
    try {
      const r = await api.shareItinerary(latest.id)
      const msg = `Hi ${t.contact?.name || ''}, here is your ${t.destination_text || 'travel'} itinerary & quote: ${r.url}`
      if (phone) Linking.openURL(`https://wa.me/${digits(phone)}?text=${encodeURIComponent(msg)}`)
      else Share.share({ message: msg })
    } catch (e) { Alert.alert('Could not share', e.message) }
  }
  const move = (status) => api.setTripStatus(t.id, status).then(load).catch(e => Alert.alert('Failed', e.message))
  const Row = ({ k, v }) => v ? <View style={s.kv}><Text style={s.k}>{k}</Text><Text style={s.v}>{v}</Text></View> : null

  return (
    <ScrollView style={s.wrap} contentContainerStyle={{ padding: 14, gap: 12 }}>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      <View style={s.card}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}><Text style={s.h}>{t.title}</Text><StatusPill status={t.status} /></View>
        {t.contact && <Text style={{ color: c.sub, marginTop: 4 }}>{t.contact.name} · {phone}</Text>}
        {!!phone && (
          <View style={{ flexDirection: 'row', gap: 10, marginTop: 12 }}>
            <TouchableOpacity style={[s.btn, { backgroundColor: c.ok }]} onPress={() => Linking.openURL(`tel:${phone}`)}><Text style={s.btnT}>📞 Call</Text></TouchableOpacity>
            <TouchableOpacity style={[s.btn, { backgroundColor: '#25D366' }]} onPress={() => Linking.openURL(`https://wa.me/${digits(phone)}`)}><Text style={s.btnT}>💬 WhatsApp</Text></TouchableOpacity>
          </View>
        )}
      </View>

      <View style={s.card}>
        <Text style={s.h2}>Requirements</Text>
        <Row k="Destination" v={t.destination_text} /><Row k="Dates" v={t.start_date ? `${t.start_date} → ${t.end_date || ''}` : t.travel_month} />
        <Row k="Nights" v={t.nights} /><Row k="Travellers" v={`${t.adults} adults${Number(t.children) ? `, ${t.children} children` : ''}`} />
        <Row k="Budget" v={t.budget_max ? inr(t.budget_max) : ''} /><Row k="Type" v={t.trip_type?.replace(/_/g, ' ')} />
        {!!t.requirements && <Text style={{ marginTop: 8, color: c.text }}>{t.requirements}</Text>}
      </View>

      <View style={s.card}>
        <Text style={s.h2}>Quote</Text>
        {latest ? (<>
          <Text style={{ color: c.text }}>v{latest.version} · {inr(latest.grand_total)} · {latest.status}{Number(latest.view_count) ? ` · viewed ${latest.view_count}×` : ''}</Text>
          <View style={{ flexDirection: 'row', gap: 10, marginTop: 10 }}>
            <TouchableOpacity style={[s.btn, { backgroundColor: c.primary }]} onPress={sendQuote}><Text style={s.btnT}>Send on WhatsApp</Text></TouchableOpacity>
            <TouchableOpacity style={[s.btn, { backgroundColor: '#fff', borderWidth: 1, borderColor: c.border }]} onPress={() => navigation.navigate('Quote', { tripId: t.id, itineraryId: latest.id })}><Text style={{ color: c.text, fontWeight: '700' }}>View / adjust</Text></TouchableOpacity>
          </View>
        </>) : (<>
          <Text style={{ color: c.sub }}>No quote yet.</Text>
          <TouchableOpacity style={[s.btn, { backgroundColor: c.primary, marginTop: 10 }]} onPress={() => navigation.navigate('Quote', { tripId: t.id })}><Text style={s.btnT}>✨ Draft a quote with AI</Text></TouchableOpacity>
        </>)}
        {t.booking && <TouchableOpacity onPress={() => navigation.navigate('Booking', { id: t.booking.id })}><Text style={{ color: c.primary, marginTop: 10, fontWeight: '700' }}>Open booking {t.booking.booking_ref} →</Text></TouchableOpacity>}
      </View>

      <View style={s.card}>
        <Text style={s.h2}>Move to</Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
          {['enquiry', 'quoted', 'negotiating', 'lost'].map(st => (
            <TouchableOpacity key={st} disabled={t.status === st} onPress={() => move(st)} style={[s.chip, t.status === st && { opacity: 0.4 }]}><Text style={{ fontWeight: '600' }}>{st}</Text></TouchableOpacity>))}
        </View>
      </View>
    </ScrollView>
  )
}
const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: c.bg },
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border },
  h: { fontSize: 18, fontWeight: '800', color: c.text, flex: 1 }, h2: { fontSize: 15, fontWeight: '800', marginBottom: 8, color: c.text },
  kv: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 5, borderBottomWidth: StyleSheet.hairlineWidth, borderColor: c.border },
  k: { color: c.sub }, v: { color: c.text, fontWeight: '600', flexShrink: 1, textAlign: 'right' },
  btn: { flex: 1, padding: 13, borderRadius: 12, alignItems: 'center' }, btnT: { color: '#fff', fontWeight: '700' },
  chip: { borderWidth: 1, borderColor: c.border, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 8 },
})
