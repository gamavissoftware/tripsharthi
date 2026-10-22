import { useState, useEffect, useCallback, useRef } from 'react'
import { View, Text, Linking, Alert } from 'react-native'
import { contactApi, api, inr } from '../api'
import { c, sp, type } from '../theme'
import { Screen, Card, Pill, Btn, Row, Avatar, Skeleton, ErrorBox, Section, ok } from '../ui'
import { fmtDay } from '../logic'

const digits = (s) => String(s || '').replace(/\D/g, '')
const TIER = { hot: 'danger', warm: 'warn', cold: 'info' }

/** Who it is, one tap to call or WhatsApp, and every enquiry they have made. Where a "new lead" or "customer replied" notification lands. */
export default function ContactScreen({ route, navigation }) {
  const { id } = route.params
  const [ct, setCt] = useState(null)
  const [trips, setTrips] = useState([])
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])
  const load = useCallback(() => {
    contactApi.get(id).then(x => { if (alive.current) { setErr(''); setCt(x) } }).catch(e => alive.current && setErr(e.message))
    contactApi.trips(id).then(x => alive.current && setTrips(x)).catch(() => {})
  }, [id])
  useEffect(() => { load() }, [load])

  if (!ct) return <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>{err ? <ErrorBox text={err} onRetry={load} /> : <><Skeleton h={150} r={16} /><Skeleton h={150} r={16} /></>}</Screen>

  const phone = ct.wa_number
  async function createEnquiry() {
    setBusy(true)
    try { const t = await api.createTrip({ title: `${ct.name || 'New'} enquiry`, contact_id: ct.id, destination_text: '' }); ok(); navigation.replace('Trip', { id: Number(t.id) }) }
    catch (e) { Alert.alert('Could not create enquiry', e.message) } finally { setBusy(false) }
  }
  const KV = ({ k, v }) => (v ? <View style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 9, borderBottomWidth: 0.5, borderColor: c.line }}><Text style={type.small}>{k}</Text><Text style={{ color: c.text, fontWeight: '600', flexShrink: 1, textAlign: 'right', marginLeft: 16 }}>{v}</Text></View> : null)

  return (
    <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>
      <Card style={{ alignItems: 'center' }}>
        <Avatar name={ct.name || phone} size={72} />
        <Text style={[type.h1, { fontSize: 22, marginTop: sp.m, textAlign: 'center' }]}>{ct.name || phone}</Text>
        <View style={{ flexDirection: 'row', gap: 8, marginTop: sp.s, flexWrap: 'wrap', justifyContent: 'center' }}>
          {!!ct.lifecycle_stage && <Pill tone="info" text={ct.lifecycle_stage} />}
          {!!ct.score_tier && <Pill tone={TIER[ct.score_tier] || 'neutral'} text={`${ct.score_tier} lead`} />}
          {String(ct.opt_in) === '0' && <Pill tone="danger" icon="notifications-off" text="opted out of WhatsApp" />}
        </View>
        {!!phone && <View style={{ flexDirection: 'row', gap: 10, marginTop: sp.l, alignSelf: 'stretch' }}>
          <Btn label="Call" icon="call" kind="ok" onPress={() => Linking.openURL(`tel:${phone}`)} style={{ flex: 1 }} />
          <Btn label="WhatsApp" icon="logo-whatsapp" kind="green" onPress={() => Linking.openURL(`https://wa.me/${digits(phone)}`)} style={{ flex: 1 }} />
        </View>}
        {!!ct.email && <Btn label={ct.email} icon="mail-outline" kind="ghost" small onPress={() => Linking.openURL(`mailto:${ct.email}`)} style={{ marginTop: sp.s, alignSelf: 'stretch' }} />}
      </Card>

      <Card>
        <Text style={[type.h3, { marginBottom: 4 }]}>Details</Text>
        <KV k="Phone" v={phone} /><KV k="Second phone" v={ct.phone_secondary} /><KV k="City" v={[ct.city, ct.state].filter(Boolean).join(', ')} />
        <KV k="Interested in" v={ct.requirement_type} /><KV k="Budget" v={ct.budget_amount ? String(ct.budget_amount) : ''} /><KV k="Timeline" v={ct.timeline} />
        <KV k="Source" v={ct.source ? String(ct.source).replace(/_/g, ' ') : ''} /><KV k="Added" v={fmtDay(ct.created_at)} />
        {Array.isArray(ct.tags) && ct.tags.length > 0 && <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>{ct.tags.map(tg => <Pill key={tg.id || tg.name} tone="neutral" text={tg.name} />)}</View>}
        {!!ct.remarks && <Text style={{ marginTop: 10, color: c.text, lineHeight: 20 }}>{ct.remarks}</Text>}
      </Card>

      <Text style={[type.h2, { marginTop: sp.s }]}>Enquiries</Text>
      <Card pad={false} style={{ paddingHorizontal: sp.l }}>
        {trips.length === 0 && <Text style={[type.small, { paddingVertical: sp.l }]}>No enquiries yet for this customer.</Text>}
        {trips.map((t, i) => <Row key={t.id} icon="airplane" tone={c.primary} title={t.title} last={i === trips.length - 1} sub={`${t.destination_text || 'Destination not set'}${t.start_date ? ' · ' + fmtDay(t.start_date) : ''}`} onPress={() => navigation.navigate('Trip', { id: Number(t.id) })} right={<Pill status={t.status} text={t.status} />} />)}
      </Card>
      <Btn label="Edit contact, notes & full history" icon="create-outline" kind="ghost" onPress={() => navigation.navigate('Web', { path: `/contacts/${ct.id}`, title: ct.name || 'Contact' })} />
      <Btn label="New enquiry for this customer" icon="add-circle" onPress={createEnquiry} busy={busy} />
    </Screen>)
}
