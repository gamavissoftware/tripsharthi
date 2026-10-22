import { useState, useEffect, useCallback, useRef } from 'react'
import { View, Text, Linking, Share, Alert } from 'react-native'
import { api, inr } from '../api'
import { c, sp, type } from '../theme'
import { Screen, Card, Section, Pill, Btn, Row, Avatar, Icon, Skeleton, ErrorBox, ok } from '../ui'
import { latestQuote, fmtDay, inrFull } from '../logic'
import OfflineBanner from '../OfflineBanner'
import { NewTask } from './TasksScreen'

const digits = (s) => String(s || '').replace(/\D/g, '')
const MOVES = [['enquiry', 'New enquiry'], ['quoted', 'Quoted'], ['negotiating', 'Negotiating'], ['lost', 'Lost']]

export default function TripScreen({ route, navigation }) {
  const { id } = route.params
  const [t, setT] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [err, setErr] = useState('')
  const [task, setTask] = useState(false)
  const [busy, setBusy] = useState('')
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])
  const load = useCallback(() => api.tripC(id).then(r => { if (alive.current) { setErr(''); setT(r.data); setMeta({ stale: r.stale, at: r.at }) } }).catch(e => alive.current && setErr(e.message)), [id])
  useEffect(() => { load() }, [load])

  if (!t) return <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>{err ? <ErrorBox text={err} onRetry={load} /> : <><Skeleton h={120} r={16} /><Skeleton h={160} r={16} /><Skeleton h={110} r={16} /></>}</Screen>

  const phone = t.contact?.wa_number
  const latest = latestQuote(t.itineraries)       // the API lists quotes oldest first: [0] would be the OLD version
  async function sendQuote() {
    setBusy('send')
    try {
      const r = await api.shareItinerary(latest.id)
      const msg = `Hi ${t.contact?.name || ''}, here is your ${t.destination_text || 'travel'} itinerary & quote: ${r.url}`
      if (phone) await Linking.openURL(`https://wa.me/${digits(phone)}?text=${encodeURIComponent(msg)}`); else await Share.share({ message: msg })
      ok(); load()
    } catch (e) { Alert.alert('Could not share', e.message) } finally { setBusy('') }
  }
  const move = (status) => api.setTripStatus(t.id, status).then(() => { ok(); load() }).catch(e => Alert.alert('Could not move it', e.message))
  const KV = ({ k, v }) => (v ? <View style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 9, borderBottomWidth: 0.5, borderColor: c.line }}><Text style={type.small}>{k}</Text><Text style={{ color: c.text, fontWeight: '600', flexShrink: 1, textAlign: 'right', marginLeft: 16 }}>{v}</Text></View> : null)
  const when = t.start_date ? `${fmtDay(t.start_date)}${t.end_date ? ' → ' + fmtDay(t.end_date) : ''}` : t.travel_month

  return (
    <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <Avatar name={t.contact?.name || t.title} size={52} />
          <View style={{ flex: 1 }}>
            <Text style={type.h2} numberOfLines={2}>{t.title}</Text>
            {!!t.contact && <Text style={[type.small, { marginTop: 3 }]}>{t.contact.name}{phone ? ` · ${phone}` : ''}</Text>}
          </View>
        </View>
        <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}><Pill status={t.status} text={t.status} />{Number(t.is_international) ? <Pill tone="purple" icon="earth" text="international" /> : null}</View>
        {!!phone && (
          <View style={{ flexDirection: 'row', gap: 10, marginTop: sp.l }}>
            <Btn label="Call" icon="call" kind="ok" onPress={() => Linking.openURL(`tel:${phone}`)} style={{ flex: 1 }} />
            <Btn label="WhatsApp" icon="logo-whatsapp" kind="green" onPress={() => Linking.openURL(`https://wa.me/${digits(phone)}`)} style={{ flex: 1 }} />
          </View>)}
        {!!t.contact?.id && <Btn label="Open contact" icon="person-circle-outline" kind="ghost" small onPress={() => navigation.navigate('Contact', { id: Number(t.contact.id) })} style={{ marginTop: sp.s }} />}
      </Card>

      <Card>
        <Text style={[type.h3, { marginBottom: 4 }]}>Requirements</Text>
        <KV k="Destination" v={t.destination_text} /><KV k="Dates" v={when} /><KV k="Nights" v={t.nights} />
        <KV k="Travellers" v={`${t.adults} adults${Number(t.children) ? `, ${t.children} children` : ''}${Number(t.infants) ? `, ${t.infants} infants` : ''}`} />
        <KV k="Budget" v={Number(t.budget_max) ? `${inr(t.budget_max)}${t.budget_basis === 'per_person' ? ' per person' : ''}` : ''} />
        <KV k="Trip type" v={t.trip_type?.replace(/_/g, ' ')} /><KV k="Hotels" v={t.hotel_category && t.hotel_category !== 'any' ? t.hotel_category : ''} /><KV k="Passport" v={t.passport_status && t.passport_status !== 'unknown' ? t.passport_status : ''} />
        {!!t.requirements && <Text style={{ marginTop: 10, color: c.text, lineHeight: 20 }}>{t.requirements}</Text>}
      </Card>

      <Card>
        <Text style={[type.h3, { marginBottom: 8 }]}>Quote</Text>
        {latest ? (<>
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
            <View><Text style={{ fontSize: 24, fontWeight: '800', color: c.text }}>{inrFull(latest.grand_total)}</Text><Text style={type.small}>Version {latest.version} · incl. tax</Text></View>
            <View style={{ alignItems: 'flex-end', gap: 4 }}><Pill status={latest.status === 'accepted' ? 'confirmed' : undefined} tone={latest.status === 'rejected' ? 'danger' : latest.status === 'sent' ? 'info' : 'neutral'} text={latest.status} />{Number(latest.view_count) > 0 && <Text style={type.small}>viewed {latest.view_count}×</Text>}</View>
          </View>
          <View style={{ flexDirection: 'row', gap: 10, marginTop: sp.l }}>
            <Btn label="Send quote" icon="logo-whatsapp" kind="green" onPress={sendQuote} busy={busy === 'send'} style={{ flex: 1 }} />
            <Btn label="View / edit" icon="create-outline" kind="ghost" onPress={() => navigation.navigate('Quote', { tripId: t.id, itineraryId: latest.id })} style={{ flex: 1 }} />
          </View>
        </>) : (<>
          <Text style={type.small}>No quote yet. Let AI draft a day-by-day itinerary from this enquiry, priced only from your own rate cards.</Text>
          <Btn label="Draft a quote with AI" icon="sparkles" onPress={() => navigation.navigate('Quote', { tripId: t.id })} style={{ marginTop: sp.m }} />
        </>)}
        {t.booking && <Btn label={`Open booking ${t.booking.booking_ref}`} icon="briefcase-outline" kind="soft" onPress={() => navigation.navigate('Booking', { id: Number(t.booking.id) })} style={{ marginTop: sp.m }} />}
      </Card>

      <Card>
        <Text style={[type.h3, { marginBottom: sp.m }]}>Move to</Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
          {MOVES.map(([k, l]) => <Btn key={k} label={l} kind={t.status === k ? 'soft' : 'ghost'} small disabled={t.status === k} onPress={() => move(k)} />)}
        </View>
        <Btn label="Add a follow-up task" icon="alarm-outline" kind="ghost" small onPress={() => setTask(true)} style={{ marginTop: sp.m }} />
      </Card>
      <NewTask visible={task} dealId={t.deal_id ? Number(t.deal_id) : undefined} onClose={() => setTask(false)} onSaved={() => { setTask(false); Alert.alert('Task added', 'It will show on your Home and Tasks.') }} />
    </Screen>)
}
