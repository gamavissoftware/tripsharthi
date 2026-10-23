import { useState, useEffect, useCallback, useRef } from 'react'
import { View, Text, Alert, Share, Linking, TextInput } from 'react-native'
import { api, inr } from '../api'
import { c, sp, type } from '../theme'
import { Screen, Card, Section, Pill, Btn, Row, Progress, Sheet, Input, Chip, Icon, Skeleton, ErrorBox, ok } from '../ui'
import { fmtDay, inrFull, pctOf, indiaToday, dueLabel, passportStatus } from '../logic'
import TravellerSheet from '../TravellerSheet'

const MODES = [['upi', 'UPI', 'phone-portrait'], ['bank', 'Bank transfer', 'business'], ['cash', 'Cash', 'cash'], ['card', 'Card', 'card']]
const SVC = { confirmed: 'ok', booked: 'ok', to_book: 'warn', requested: 'info', cancelled: 'danger' }

export default function BookingScreen({ route, navigation }) {
  const [b, setB] = useState(null)
  const [err, setErr] = useState('')
  const [pay, setPay] = useState(null)             // the payment being marked paid
  const [mode, setMode] = useState('upi')
  const [ref, setRef] = useState('')
  const [busy, setBusy] = useState('')
  const [trv, setTrv] = useState(undefined)         // undefined = closed, null = adding, object = editing
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])
  const load = useCallback(() => api.booking(route.params.id).then(x => { if (alive.current) { setErr(''); setB(x) } }).catch(e => alive.current && setErr(e.message)), [route.params.id])
  useEffect(() => { load() }, [load])
  const today = indiaToday()

  if (!b) return <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>{err ? <ErrorBox text={err} onRetry={load} /> : <><Skeleton h={130} r={16} /><Skeleton h={200} r={16} /></>}</Screen>

  const sendLink = async (p) => { setBusy('l' + p.id); try { const l = await api.paymentLink(p.id); await Share.share({ message: `Payment link for ${b.booking_ref} – ${p.label}: ${l.short_url}` }) } catch (e) { Alert.alert('Could not create link', e.message) } finally { setBusy('') } }
  const remind = async (p) => { setBusy('r' + p.id); try { await api.remind(p.id); ok(); Alert.alert('Reminder sent', 'The customer was reminded on WhatsApp.'); load() } catch (e) { Alert.alert('Reminder not sent', e.message) } finally { setBusy('') } }
  async function markPaid() {
    setBusy('paid')
    try { await api.markPaid(pay.id, mode, ref.trim() || undefined); ok(); setPay(null); setRef(''); setMode('upi'); load() }
    catch (e) { Alert.alert('Could not record it', e.message) } finally { setBusy('') }
  }
  const due = Number(b.due_amount)
  const portal = b.portal_token ? `https://app.tripsarthi.com/#/trip/${b.portal_token}` : null

  return (
    <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center' }}>
          <Text style={{ fontSize: 12.5, fontWeight: '800', color: c.primary, letterSpacing: 0.5, flex: 1 }}>{b.booking_ref}</Text>
          <Pill status={b.status} text={b.status} />
        </View>
        <Text style={[type.h2, { marginTop: 6 }]}>{b.title}</Text>
        <Text style={[type.small, { marginTop: 4 }]}>{b.travel_start ? `${fmtDay(b.travel_start)}${b.travel_end ? ' → ' + fmtDay(b.travel_end) : ''} · ${dueLabel(b.travel_start, today)}` : 'Dates to be confirmed'}</Text>
        <View style={{ marginTop: sp.l }}>
          <Progress pct={pctOf(b.paid_amount, b.total_amount)} color={due > 0 ? c.orange : c.ok} height={10} />
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 12 }}>
            <Stat label="Total" value={inrFull(b.total_amount)} />
            <Stat label="Paid" value={inrFull(b.paid_amount)} color={c.ok} />
            <Stat label="Due" value={inrFull(due)} color={due > 0 ? c.danger : c.ok} right />
          </View>
        </View>
        <View style={{ flexDirection: 'row', gap: 10, marginTop: sp.l }}>
          {!!b.contact_id && <Btn label="Customer" icon="person-circle-outline" kind="ghost" small onPress={() => navigation.navigate('Contact', { id: Number(b.contact_id) })} style={{ flex: 1 }} />}
          {!!b.trip_id && <Btn label="Enquiry" icon="airplane-outline" kind="ghost" small onPress={() => navigation.navigate('Trip', { id: Number(b.trip_id) })} style={{ flex: 1 }} />}
          {!!portal && <Btn label="Portal link" icon="link" kind="ghost" small onPress={() => Share.share({ message: `Your trip details, payments and documents: ${portal}` })} style={{ flex: 1 }} />}
        </View>
      </Card>

      <Text style={[type.h2, { marginTop: sp.s }]}>Payments</Text>
      {(b.payments || []).map(p => {
        const open = p.status !== 'paid' && p.status !== 'waived'
        return (
          <Card key={p.id}>
            <View style={{ flexDirection: 'row', alignItems: 'center' }}>
              <View style={{ flex: 1 }}><Text style={type.h3}>{p.label}</Text><Text style={[type.small, { marginTop: 2 }]}>{open ? `Due ${fmtDay(p.due_date)} · ${dueLabel(p.due_date, today)}` : p.paid_at ? `Paid ${fmtDay(p.paid_at)}${p.mode ? ' by ' + p.mode : ''}` : 'Settled'}</Text></View>
              <View style={{ alignItems: 'flex-end', gap: 5 }}><Text style={{ fontSize: 17, fontWeight: '800', color: c.text }}>{inrFull(p.amount)}</Text><Pill status={p.status} text={p.status} /></View>
            </View>
            {open && <View style={{ flexDirection: 'row', gap: 8, marginTop: sp.m }}>
              <Btn label="Link" icon="link" kind="ghost" small busy={busy === 'l' + p.id} onPress={() => sendLink(p)} style={{ flex: 1 }} />
              <Btn label="Remind" icon="logo-whatsapp" kind="ghost" small busy={busy === 'r' + p.id} onPress={() => remind(p)} style={{ flex: 1 }} />
              <Btn label="Paid" icon="checkmark-circle" kind="ok" small onPress={() => { setPay(p); setMode('upi'); setRef('') }} style={{ flex: 1.2 }} />
            </View>}
          </Card>)
      })}

      {(b.services || []).length > 0 && <>
        <Text style={[type.h2, { marginTop: sp.s }]}>Supplier services</Text>
        <Card pad={false} style={{ paddingHorizontal: sp.l }}>
          {b.services.map((x, i, arr) => <Row key={x.id} icon={x.service_type === 'hotel' ? 'bed' : x.service_type === 'flight' ? 'airplane' : x.service_type === 'transfer' ? 'car' : 'ticket'} tone={c.primary} title={x.title} last={i === arr.length - 1}
            sub={[x.service_date && fmtDay(x.service_date), x.confirmation_no && `Ref ${x.confirmation_no}`].filter(Boolean).join(' · ') || String(x.service_type).replace(/_/g, ' ')} right={<Pill tone={SVC[x.status] || 'neutral'} text={x.status} />} />)}
        </Card></>}

      <Text style={[type.h2, { marginTop: sp.s }]}>Travellers</Text>
      <Card pad={false} style={{ paddingHorizontal: sp.l }}>
        {(b.travelers || []).length === 0 && <Text style={[type.small, { paddingVertical: sp.l }]}>No travellers added yet. Add them to get their passport and visa checklist.</Text>}
        {(b.travelers || []).map((t, i, arr) => {
          const ps = passportStatus(t, { tripEnd: b.travel_end || b.travel_start, today, international: Number(b.is_international) === 1 })
          return <Row key={t.id} icon={t.pax_type === 'child' || t.pax_type === 'infant' ? 'happy' : 'person'} tone={c.primary} title={t.full_name} last={i === arr.length - 1} onPress={() => setTrv(t)}
            sub={[t.pax_type, t.nationality].filter(Boolean).join(' · ')} right={<Pill tone={ps.tone} text={ps.label} />} />
        })}
      </Card>
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <Btn label="Add traveller" icon="person-add" kind="soft" onPress={() => setTrv(null)} style={{ flex: 1 }} />
        <Btn label="Documents" icon="document-text" kind="soft" onPress={() => navigation.navigate('Documents', { bookingId: b.id })} style={{ flex: 1 }} />
      </View>
      <Btn label="Invoices & more on the web screen" icon="open-outline" kind="ghost" onPress={() => navigation.navigate('Web', { path: `/bookings/${b.id}`, title: b.booking_ref })} />
      <TravellerSheet visible={trv !== undefined} onClose={() => setTrv(undefined)} bookingId={b.id} traveler={trv || null} onSaved={() => { setTrv(undefined); load() }} />

      <Sheet visible={!!pay} onClose={() => setPay(null)} title="Record a payment">
        {!!pay && <>
          <View style={{ backgroundColor: c.bg, borderRadius: 14, padding: sp.l, marginBottom: sp.l }}>
            <Text style={type.small}>{pay.label} · {b.booking_ref}</Text><Text style={{ fontSize: 26, fontWeight: '800', color: c.text, marginTop: 2 }}>{inrFull(pay.amount)}</Text>
          </View>
          <Text style={[type.label, { marginBottom: 8 }]}>How was it paid?</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: sp.l }}>{MODES.map(([k, l, i]) => <Chip key={k} label={l} icon={i} active={mode === k} onPress={() => setMode(k)} />)}</View>
          <Input label="Reference / UTR (optional)" value={ref} onChangeText={setRef} placeholder="e.g. UPI transaction id" autoCapitalize="characters" />
          <Btn label="Mark as paid" icon="checkmark" kind="ok" onPress={markPaid} busy={busy === 'paid'} />
        </>}
      </Sheet>
    </Screen>)
}

function Stat({ label, value, color, right }) {
  return <View style={{ alignItems: right ? 'flex-end' : 'flex-start' }}><Text style={type.small}>{label}</Text><Text style={{ fontSize: 16, fontWeight: '800', color: color || c.text, marginTop: 1 }}>{value}</Text></View>
}
