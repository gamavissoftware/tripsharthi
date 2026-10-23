import { useState, useEffect, useCallback, useRef } from 'react'
import { View, Text, Linking, Share, Alert, TouchableOpacity } from 'react-native'
import { api, inr } from '../api'
import { c, sp, type } from '../theme'
import { Screen, Card, Pill, Btn, Input, Icon, Skeleton, ErrorBox, Empty, ok } from '../ui'
import { digits, isFlatMarkup, quotePatch, inrFull, itemWorking } from '../logic'
import QuoteItemSheet from '../QuoteItemSheet'

/** Draft a quote with AI, check the price, adjust markup / discount, and send it, all from the phone. */
export default function QuoteScreen({ route, navigation }) {
  const { tripId, itineraryId } = route.params
  const [trip, setTrip] = useState(null)
  const [it, setIt] = useState(null)
  const [busy, setBusy] = useState('')
  const [markup, setMarkup] = useState('')
  const [discount, setDiscount] = useState('')
  const [err, setErr] = useState('')
  const [sheet, setSheet] = useState(null)        // { day, item } while the add / edit sheet is open
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])

  const show = useCallback((x) => { setIt(x); setMarkup(String(x.markup_type === 'percent' ? Number(x.markup_value) : '')); setDiscount(String(Math.round(Number(x.discount_amount || 0) / 100) || '')) }, [])
  useEffect(() => {
    api.trip(tripId).then(setTrip).catch(e => setErr(e.message))
    if (itineraryId) api.itinerary(itineraryId).then(show).catch(e => setErr(e.message))
  }, [tripId, itineraryId, show])

  async function draft() {
    setBusy('ai')
    try { const x = await api.aiItinerary(tripId); ok(); show(x.id && x.days ? x : await api.itinerary(x.id)) }
    catch (e) { Alert.alert('Could not draft', e.message) } finally { setBusy('') }
  }
  async function blank() {
    setBusy('blank')
    try {
      const x = await api.createItinerary({ trip_id: tripId })
      const n = Math.min(Math.max(Number(x.nights) || Number(trip?.nights) || 1, 1), 14)
      for (let d = 1; d <= n; d++) await api.addDay(x.id, { day_no: d, title: `Day ${d}` })
      ok(); show(await api.itinerary(x.id))
    } catch (e) { Alert.alert('Could not start the quote', e.message) } finally { setBusy('') }
  }
  async function addDay() {
    setBusy('day')
    try { const n = (it.days || []).length + 1; await api.addDay(it.id, { day_no: n, title: `Day ${n}` }); show(await api.itinerary(it.id)) } catch (e) { Alert.alert('Could not add a day', e.message) } finally { setBusy('') }
  }
  async function reprice() {
    const r = quotePatch(it, markup, discount)
    if (r.error) return Alert.alert(r.error[0], r.error[1])
    setBusy('price')
    try { show(await api.updateItinerary(it.id, r.patch)); ok() } catch (e) { Alert.alert('Could not update', e.message) } finally { setBusy('') }
  }
  async function reload() { setSheet(null); try { show(await api.itinerary(it.id)) } catch (e) { Alert.alert('Could not refresh', e.message) } }
  async function send() {
    setBusy('send')
    try {
      const r = await api.shareItinerary(it.id)
      const phone = trip?.contact?.wa_number
      const msg = `Hi ${trip?.contact?.name || ''}, here is your ${trip?.destination_text || 'travel'} itinerary & quote: ${r.url}`
      if (phone) await Linking.openURL(`https://wa.me/${digits(phone)}?text=${encodeURIComponent(msg)}`); else await Share.share({ message: msg })
      ok()
    } catch (e) { Alert.alert('Could not share', e.message) } finally { setBusy('') }
  }

  if (!it) return (
    <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>
      {!!err && <ErrorBox text={err} />}
      {itineraryId && !err ? <><Skeleton h={120} r={16} /><Skeleton h={200} r={16} /></> : (
        <Card style={{ alignItems: 'center', paddingVertical: sp.xl }}>
          <View style={{ width: 64, height: 64, borderRadius: 32, backgroundColor: c.purpleSoft, alignItems: 'center', justifyContent: 'center' }}><Icon name="sparkles" size={30} color={c.purple} /></View>
          <Text style={[type.h2, { marginTop: sp.m }]}>Draft a quote with AI</Text>
          <Text style={[type.small, { textAlign: 'center', marginVertical: sp.m, lineHeight: 19 }]}>{trip ? `A day-by-day itinerary for ${trip.destination_text || 'this trip'}, built from the enquiry. Prices come only from your supplier rate cards. The AI never invents a price.` : 'Loading the enquiry…'}</Text>
          <Btn label={busy === 'ai' ? 'Drafting… up to a minute' : 'Draft with AI'} icon="sparkles" onPress={draft} busy={busy === 'ai'} disabled={!trip} style={{ alignSelf: 'stretch' }} />
          <Text style={[type.small, { marginTop: sp.l, textAlign: 'center' }]}>Or build it yourself, line by line:</Text>
          <Btn label="Start a blank quote" icon="create-outline" kind="ghost" onPress={blank} busy={busy === 'blank'} disabled={!trip} style={{ alignSelf: 'stretch', marginTop: sp.s }} />
        </Card>)}
    </Screen>)

  const locked = ['accepted', 'rejected', 'expired'].includes(it.status)
  const canCost = it.cost_total !== undefined          // the server leaves cost fields out for agents
  const Line = ({ k, v, bold, muted }) => <View style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, borderBottomWidth: 0.5, borderColor: c.line }}><Text style={[type.small, muted && { color: c.faint }]}>{k}</Text><Text style={{ color: muted ? c.faint : c.text, fontWeight: bold ? '800' : '600', fontSize: bold ? 17 : 14.5 }}>{v}</Text></View>
  return (
    <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'flex-start' }}>
          <View style={{ flex: 1 }}><Text style={type.h2}>{it.title}</Text><Text style={[type.small, { marginTop: 4 }]}>{it.nights} nights · {it.adults} adults{Number(it.children) ? `, ${it.children} children` : ''} · version {it.version}</Text></View>
          <Pill tone={it.status === 'accepted' ? 'ok' : it.status === 'rejected' || it.status === 'expired' ? 'danger' : it.status === 'sent' ? 'info' : 'neutral'} text={it.status} />
        </View>
        <Text style={{ fontSize: 30, fontWeight: '800', color: c.text, marginTop: sp.l, letterSpacing: -0.5 }}>{inrFull(it.grand_total)}</Text>
        <Text style={type.small}>Total to the customer, tax included</Text>
      </Card>

      <Card>
        <Text style={[type.h3, { marginBottom: 4 }]}>Price</Text>
        <Line k="Price before tax" v={inrFull(it.sell_subtotal)} /><Line k={`GST ${Number(it.gst_rate)}%`} v={inrFull(it.gst_amount)} />
        {Number(it.tcs_amount) > 0 && <Line k={`TCS ${Number(it.tcs_rate)}%`} v={inrFull(it.tcs_amount)} />}
        {Number(it.discount_amount) > 0 && <Line k="Discount given" v={'− ' + inrFull(it.discount_amount)} />}
        <Line k="Total" v={inrFull(it.grand_total)} bold />
        {it.margin_amount != null && <Line k="Your margin" v={inrFull(it.margin_amount)} muted />}
        {!locked ? (<View style={{ marginTop: sp.l }}>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <Input label="Markup %" value={markup} onChangeText={setMarkup} keyboardType="decimal-pad" placeholder={isFlatMarkup(it) ? 'flat' : '0'} style={{ flex: 1, marginBottom: 0 }} />
            <Input label="Discount ₹" value={discount} onChangeText={setDiscount} keyboardType="number-pad" placeholder="0" style={{ flex: 1, marginBottom: 0 }} />
          </View>
          {isFlatMarkup(it) && <Text style={[type.small, { marginTop: 8 }]}>This quote has a flat markup of {inr(it.markup_value)} (set in the web app). Leave Markup % blank to keep it, or type a percentage to replace it.</Text>}
          <Btn label="Update price" icon="calculator" kind="soft" onPress={reprice} busy={busy === 'price'} style={{ marginTop: sp.m }} />
        </View>) : <Text style={[type.small, { marginTop: 10 }]}>This quote is {it.status}, so the price is locked. Create a new version in the web app to change it.</Text>}
      </Card>

      {(it.days || []).map(d => (
        <Card key={d.id}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 6 }}>
            <View style={{ backgroundColor: c.primarySoft, borderRadius: 10, paddingHorizontal: 10, paddingVertical: 4 }}><Text style={{ color: c.primary, fontWeight: '800', fontSize: 12 }}>DAY {d.day_no}</Text></View>
            <Text style={[type.h3, { flex: 1 }]} numberOfLines={2}>{d.title}</Text>
          </View>
          {!!d.description && <Text style={[type.small, { marginBottom: 6, lineHeight: 18 }]} numberOfLines={3}>{d.description}</Text>}
          {(d.items || []).map(i => <ItemLine key={i.id} i={i} canCost={canCost} locked={locked} onPress={() => setSheet({ day: d, item: i })} />)}
          {!locked && <TouchableOpacity onPress={() => setSheet({ day: d, item: null })} style={{ flexDirection: 'row', alignItems: 'center', gap: 6, paddingTop: sp.m }}><Icon name="add-circle" size={20} color={c.primary} /><Text style={{ color: c.primary, fontWeight: '700' }}>Add service</Text></TouchableOpacity>}
        </Card>))}
      {!locked && <Btn label="Add a day" icon="calendar-outline" kind="soft" onPress={addDay} busy={busy === 'day'} />}
      {((it.extras || []).length > 0 || !locked) && (
        <Card>
          <Text style={[type.h3, { marginBottom: 4 }]}>Extras</Text>
          <Text style={[type.small, { marginBottom: 6 }]}>Services not tied to a day: visa, insurance, flights.</Text>
          {(it.extras || []).map(i => <ItemLine key={i.id} i={i} canCost={canCost} locked={locked} onPress={() => setSheet({ day: null, item: i })} />)}
          {!locked && <TouchableOpacity onPress={() => setSheet({ day: null, item: null })} style={{ flexDirection: 'row', alignItems: 'center', gap: 6, paddingTop: sp.m }}><Icon name="add-circle" size={20} color={c.primary} /><Text style={{ color: c.primary, fontWeight: '700' }}>Add an extra</Text></TouchableOpacity>}
        </Card>)}
      <Btn label="Send quote on WhatsApp" icon="logo-whatsapp" kind="green" onPress={send} busy={busy === 'send'} />
      <Btn label="More options on the web screen" icon="open-outline" kind="ghost" onPress={() => navigation.navigate('Web', { path: `/itineraries/${it.id}`, title: 'Itinerary' })} />
      <QuoteItemSheet visible={!!sheet} onClose={() => setSheet(null)} itin={it} day={sheet ? sheet.day : null} item={sheet ? sheet.item : null} canCost={canCost} onSaved={reload} />
    </Screen>)
}

const TYPE_ICON = { hotel: 'bed', flight: 'airplane', transfer: 'car', sightseeing: 'camera', activity: 'walk', visa: 'document-text', insurance: 'shield-checkmark', meal: 'restaurant' }

/** One service on a quote. Tap to edit (unless the quote is locked). Owners/admins also see what the line costs and whether it still needs a price. */
function ItemLine({ i, canCost, locked, onPress }) {
  const opt = Number(i.is_optional) === 1
  const unit = i.meta && i.meta.unit
  const working = canCost ? itemWorking(i, unit) : ''
  return (
    <TouchableOpacity activeOpacity={locked ? 1 : 0.7} disabled={locked} onPress={onPress} style={{ flexDirection: 'row', gap: 10, paddingVertical: 8, borderTopWidth: 0.5, borderColor: c.line, opacity: opt ? 0.75 : 1 }}>
      <Icon name={TYPE_ICON[i.type] || 'ellipse'} size={i.type ? 16 : 8} color={c.faint} style={{ marginTop: 3 }} />
      <View style={{ flex: 1 }}>
        <Text style={{ color: c.text, fontWeight: '600' }}>{i.title}{i.type === 'hotel' && Number(i.nights) > 1 ? ` · ${i.nights}N` : ''}{Number(i.quantity) > 1 ? ` · ×${Number(i.quantity)}` : ''}</Text>
        {!!working && <Text style={type.small}>{working}</Text>}
        {opt && <Text style={{ color: c.warn, fontSize: 12 }}>Optional · not in the total</Text>}
        {canCost && Number(i.cost_amount) === 0 && !opt && <Text style={{ color: c.warn, fontSize: 12 }}>Needs a price</Text>}
      </View>
      {canCost && <Text style={{ color: c.text, fontWeight: '700' }}>{inrFull(i.cost_amount)}</Text>}
      {!locked && <Icon name="chevron-forward" size={16} color={c.faint} style={{ marginTop: 3 }} />}
    </TouchableOpacity>)
}
