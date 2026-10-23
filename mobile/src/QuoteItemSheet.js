import { useEffect, useMemo, useState } from 'react'
import { View, TouchableOpacity, ScrollView, Switch, Alert } from 'react-native'
import { Text } from './T'
import { api } from './api'
import { c, sp, radius, type } from './theme'
import { Sheet, Input, Btn, Chip, Icon, Pill, ok } from './ui'
import { ITEM_TYPES, UNIT_LABEL, itemTypeOf, suggestQty, rateState, itemTotal, rupeesToPaise, validateItem, inrFull } from './logic'

const TYPE_ICON = { hotel: 'bed', flight: 'airplane', transfer: 'car', sightseeing: 'camera', activity: 'walk', visa: 'document-text', insurance: 'shield-checkmark', meal: 'restaurant', other: 'ellipse' }
const money = (minor, cur) => (cur && cur !== 'INR' ? `${cur} ${(Number(minor) / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 })}` : inrFull(minor))
const qtyLabel = (unit) => (unit === 'per_pax' ? 'People' : unit === 'per_room' || unit === 'per_night' ? 'Rooms' : unit === 'per_vehicle' ? 'Vehicles' : 'Quantity')

/**
 * Add a service to a quote, or edit / remove one. `day` = the day to add to (null = an extra outside any day), `item` = the line being edited.
 * Owners and admins also see and set the price (what WE pay); agents pick a rate card and the server prices the line from it, so an agent
 * never sees or types a cost. The server recomputes every total: the figure shown here is only a preview.
 */
export default function QuoteItemSheet({ visible, onClose, itin, day, item, canCost, onSaved }) {
  const editing = !!item
  const blank = { type: 'sightseeing', title: '', quantity: '1', nights: '1', price: '', optional: false, rate: null, unit: '' }
  const [f, setF] = useState(blank)
  const [errs, setErrs] = useState({})
  const [busy, setBusy] = useState('')
  const [picking, setPicking] = useState(false)
  const [rates, setRates] = useState(null)
  const [q, setQ] = useState('')
  const [ft, setFt] = useState('')
  const foreignLine = editing && item.cost_currency && item.cost_currency !== 'INR'

  useEffect(() => {
    if (!visible) return
    setErrs({}); setPicking(false); setQ(''); setFt('')
    if (editing) {
      setF({ type: item.type || 'other', title: item.title || '', quantity: String(Number(item.quantity) || 1), nights: String(Number(item.nights) || 1), price: canCost && !foreignLine ? String(Number(item.unit_cost || 0) / 100) : '', optional: Number(item.is_optional) === 1, rate: null, unit: (item.meta && item.meta.unit) || '' })
    } else setF(blank)
  }, [visible, item]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { if (visible && !editing && rates === null) api.rateCards().then(r => setRates(r.data)).catch(() => setRates([])) }, [visible, editing, rates])

  const shown = useMemo(() => {
    const t = q.trim().toLowerCase()
    return (rates || [])
      .filter(r => (!ft || r.service_type === ft) && (!t || `${r.supplier_name} ${r.service_name} ${r.service_type}`.toLowerCase().includes(t)))
      .map(r => ({ r, st: rateState(r), here: itin.destination_id && String(r.destination_id) === String(itin.destination_id) }))
      .sort((a, b) => (b.here - a.here) || ((a.st === 'expired') - (b.st === 'expired')) || (a.r.supplier_name + a.r.service_name).localeCompare(b.r.supplier_name + b.r.service_name))
      .slice(0, 40)
  }, [rates, q, ft, itin.destination_id])

  function pick(r) {
    const t = itemTypeOf(r.service_type), foreign = r.currency && r.currency !== 'INR'
    setF(s => ({ ...s, rate: r, type: t, title: r.service_name, unit: r.unit || '', quantity: String(suggestQty(r.unit, itin)), nights: t === 'hotel' ? String(Number(itin.nights) || 1) : '1',
      price: canCost && !foreign ? String(Number(r.cost_amount || 0) / 100) : '' }))
    setErrs({}); setPicking(false)
  }
  const foreignRate = !editing && f.rate && f.rate.currency && f.rate.currency !== 'INR'
  const priceLocked = foreignLine || foreignRate
  const set = (k) => (v) => { setF(s => ({ ...s, [k]: v })); setErrs(e => ({ ...e, [k]: undefined })) }
  const preview = canCost && !priceLocked && rupeesToPaise(f.price) !== null ? itemTotal(f.type, rupeesToPaise(f.price), f.quantity, f.nights) : null
  const paxWarn = f.unit === 'per_pax' && Number(f.quantity) === 1 && (Number(itin.adults) || 0) + (Number(itin.children) || 0) > 1

  async function save() {
    const e = validateItem({ ...f, priceLocked }, { canCost })
    setErrs(e); if (Object.keys(e).length) return
    setBusy('save')
    try {
      const base = { title: f.title.trim(), quantity: Number(f.quantity), nights: f.type === 'hotel' ? Number(f.nights) : 1, is_optional: f.optional ? 1 : 0 }
      if (editing) {
        await api.editItem(itin.id, item.id, { ...base, ...(canCost && !priceLocked ? { unit_cost: rupeesToPaise(f.price) } : {}) })
      } else {
        const r = f.rate
        const price = !canCost ? {} : foreignRate ? { cost_currency: r.currency, unit_cost_fx: Number(r.cost_amount) } : { unit_cost: rupeesToPaise(f.price) }
        await api.addItem(itin.id, { ...base, type: f.type, ...(day ? { day_id: day.id } : {}), ...price, ...(r ? { rate_id: r.id, supplier_id: r.supplier_id } : {}), ...(f.unit ? { meta: { unit: f.unit } } : {}) })
      }
      ok(); onSaved()
    } catch (er) { Alert.alert('Could not save', er.message) } finally { setBusy('') }
  }
  function remove() {
    Alert.alert('Remove this service?', `“${item.title}” will be taken out of the quote.`, [
      { text: 'Keep it', style: 'cancel' },
      { text: 'Remove', style: 'destructive', onPress: async () => { setBusy('del'); try { await api.removeItem(itin.id, item.id); ok(); onSaved() } catch (er) { Alert.alert('Could not remove', er.message) } finally { setBusy('') } } },
    ])
  }

  const title = editing ? 'Edit service' : day ? `Add to day ${day.day_no}` : 'Add an extra'
  return (
    <Sheet visible={visible} onClose={onClose} title={title}>
      {picking ? (
        <View>
          <Input label="Search rate cards" value={q} onChangeText={setQ} placeholder={rates && rates.length ? `${rates.length} rate cards: hotel, city, service…` : 'Hotel, transfer, city…'} autoFocus />
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingBottom: sp.m }}>
            <Chip label="All" active={!ft} onPress={() => setFt('')} />
            {ITEM_TYPES.map(t => <Chip key={t} label={t} active={ft === t} onPress={() => setFt(t)} />)}
          </ScrollView>
          {rates === null && <Text style={type.small}>Loading rate cards…</Text>}
          {rates && rates.length === 0 && <Text style={type.small}>No rate cards yet. Add them under Suppliers and Rates, or go back and type the service yourself.</Text>}
          {rates && rates.length > 0 && shown.length === 0 && <Text style={type.small}>Nothing matches. Try another word, or go back and type the service yourself.</Text>}
          {shown.map(({ r, st }) => (
            <TouchableOpacity key={r.id} activeOpacity={0.7} onPress={() => pick(r)} style={{ paddingVertical: 11, borderBottomWidth: 0.5, borderColor: c.line, flexDirection: 'row', alignItems: 'center', gap: 10 }}>
              <Icon name={TYPE_ICON[itemTypeOf(r.service_type)]} size={18} color={c.primary} />
              <View style={{ flex: 1 }}>
                <Text style={{ color: c.text, fontWeight: '700' }} numberOfLines={2}>{r.service_name}</Text>
                <Text style={type.small} numberOfLines={1}>{[r.supplier_name, UNIT_LABEL[r.unit] || r.unit].filter(Boolean).join(' · ')}</Text>
              </View>
              {st === 'expired' && <Pill tone="danger" text="expired" />}
              {st === 'future' && <Pill tone="warn" text="not started" />}
              {r.cost_amount != null && <Text style={{ color: c.text, fontWeight: '700' }}>{money(r.cost_amount, r.currency)}</Text>}
            </TouchableOpacity>))}
          <Btn label="Back" icon="arrow-back" kind="ghost" onPress={() => setPicking(false)} style={{ marginTop: sp.l }} />
        </View>
      ) : (
        <View>
          {!editing && <Btn label={f.rate ? `Rate card: ${f.rate.supplier_name || f.rate.service_name}` : 'Pick from your rate cards'} icon="search" kind="soft" onPress={() => setPicking(true)} style={{ marginBottom: sp.m }} />}
          {!editing && !canCost && !f.rate && <Text style={[type.small, { marginBottom: sp.m, lineHeight: 18 }]}>A service typed by hand has no price yet: the owner prices it. Pick a rate card to fill the price in automatically.</Text>}
          <Text style={[type.label, { marginBottom: 8 }]}>Type</Text>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingBottom: sp.m }}>
            {ITEM_TYPES.map(t => <Chip key={t} label={t} icon={TYPE_ICON[t]} active={f.type === t} onPress={() => set('type')(t)} />)}
          </ScrollView>
          <Input label="Title" value={f.title} onChangeText={set('title')} error={errs.title} placeholder="Airport transfer, Ubud villa…" maxLength={200} />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <Input label={qtyLabel(f.unit)} value={f.quantity} onChangeText={set('quantity')} error={errs.quantity} keyboardType="decimal-pad" style={{ flex: 1 }} />
            {f.type === 'hotel' && <Input label="Nights" value={f.nights} onChangeText={set('nights')} error={errs.nights} keyboardType="number-pad" maxLength={3} style={{ flex: 1 }} />}
          </View>
          {paxWarn && <Text style={{ color: c.warn, fontSize: 12.5, marginBottom: sp.m }}>This is a per-person rate with quantity 1. Change it if the price is for everyone.</Text>}
          {canCost && !priceLocked && <Input label="Price per unit ₹ (what you pay)" value={f.price} onChangeText={set('price')} error={errs.price} keyboardType="decimal-pad" placeholder="0" />}
          {canCost && priceLocked && <Text style={[type.small, { marginBottom: sp.m, lineHeight: 18 }]}>{foreignLine ? `This service is priced in ${item.cost_currency} (${money(item.unit_cost_fx, item.cost_currency)} per unit). Change its price on the web screen; quantity and nights can be changed here.` : `This rate is in ${f.rate.currency} (${money(f.rate.cost_amount, f.rate.currency)} per unit). The server converts it at your exchange rate.`}</Text>}
          {preview !== null && <Text style={{ color: c.text, fontWeight: '700', marginBottom: sp.m }}>Line total: {inrFull(preview)}</Text>}
          <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: sp.l }}>
            <View style={{ flex: 1, paddingRight: 12 }}><Text style={{ color: c.text, fontWeight: '600' }}>Optional extra</Text><Text style={type.small}>Shown to the customer but not added to the total.</Text></View>
            <Switch value={f.optional} onValueChange={set('optional')} trackColor={{ true: c.primary, false: c.border }} thumbColor="#fff" />
          </View>
          <Btn label={editing ? 'Save changes' : 'Add to quote'} icon="checkmark" onPress={save} busy={busy === 'save'} />
          {editing && <Btn label="Remove from quote" icon="trash" kind="danger" onPress={remove} busy={busy === 'del'} style={{ marginTop: sp.m }} />}
        </View>)}
    </Sheet>)
}
