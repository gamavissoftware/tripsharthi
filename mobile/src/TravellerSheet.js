import { useEffect, useState } from 'react'
import { View, Text, ScrollView, Alert, TouchableOpacity } from 'react-native'
import { docsApi } from './api'
import { c, sp, type } from './theme'
import { Sheet, Input, Btn, Chip, ok } from './ui'
import DateField from './DateField'

const PAX = [['adult', 'Adult'], ['child', 'Child'], ['infant', 'Infant']]
const GENDER = [['male', 'Male'], ['female', 'Female'], ['other', 'Other']]
const VISA = [['not_required', 'Not needed'], ['pending', 'To apply'], ['applied', 'Applied'], ['approved', 'Approved'], ['rejected', 'Refused']]

/**
 * Add or edit one traveller (name, passport, visa, ...). The passport number is write-only here: the list shows only that one is on file, and
 * "Show the number" asks the server, which records every look in the audit log. The number hides itself again after 20 seconds.
 */
export default function TravellerSheet({ visible, onClose, bookingId, traveler, onSaved }) {
  const editing = !!traveler
  const blank = { full_name: '', pax_type: 'adult', gender: '', dob: '', nationality: 'Indian', passport_no: '', passport_expiry: '', visa_status: 'not_required', meal_pref: '' }
  const [f, setF] = useState(blank)
  const [errs, setErrs] = useState({})
  const [busy, setBusy] = useState('')
  const [shown, setShown] = useState('')

  useEffect(() => {
    if (!visible) return
    setErrs({}); setShown('')
    setF(editing ? { full_name: traveler.full_name || '', pax_type: traveler.pax_type || 'adult', gender: traveler.gender || '', dob: traveler.dob ? String(traveler.dob).slice(0, 10) : '', nationality: traveler.nationality || '',
      passport_no: '', passport_expiry: traveler.passport_expiry ? String(traveler.passport_expiry).slice(0, 10) : '', visa_status: traveler.visa_status || 'not_required', meal_pref: traveler.meal_pref || '' } : blank)
  }, [visible, traveler]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (!shown) return; const t = setTimeout(() => setShown(''), 20000); return () => clearTimeout(t) }, [shown])

  const set = (k) => (v) => { setF(s => ({ ...s, [k]: v })); setErrs(e => ({ ...e, [k]: undefined })) }

  async function save() {
    const e = {}
    if (!f.full_name.trim()) e.full_name = 'Enter the name as on the passport'
    const pn = f.passport_no.replace(/\s+/g, '').toUpperCase()
    if (pn && !/^[A-Za-z0-9]{5,20}$/.test(pn)) e.passport_no = 'A passport number is 5 to 20 letters and digits'
    setErrs(e); if (Object.keys(e).length) return
    setBusy('save')
    try {
      const body = { full_name: f.full_name.trim(), pax_type: f.pax_type, gender: f.gender || undefined, dob: f.dob || undefined, nationality: f.nationality.trim() || undefined, passport_expiry: f.passport_expiry || undefined, visa_status: f.visa_status, meal_pref: f.meal_pref.trim() || undefined, ...(pn ? { passport_no: pn } : {}) }
      if (editing) await docsApi.updateTraveler(traveler.id, { ...body, gender: f.gender || '', dob: f.dob || '', passport_expiry: f.passport_expiry || '' })
      else await docsApi.addTraveler(bookingId, body)
      ok(); onSaved()
    } catch (er) { Alert.alert('Could not save', er.message) } finally { setBusy('') }
  }
  async function reveal() {
    setBusy('show')
    try { setShown(await docsApi.passport(traveler.id)) } catch (er) { Alert.alert('Could not show it', er.message) } finally { setBusy('') }
  }
  function remove() {
    Alert.alert('Remove this traveller?', `${traveler.full_name} and their pending document list will be removed.`, [
      { text: 'Keep', style: 'cancel' },
      { text: 'Remove', style: 'destructive', onPress: async () => { setBusy('del'); try { await docsApi.deleteTraveler(traveler.id); ok(); onSaved() } catch (er) { Alert.alert('Could not remove', er.message) } finally { setBusy('') } } },
    ])
  }

  return (
    <Sheet visible={visible} onClose={onClose} title={editing ? 'Edit traveller' : 'Add a traveller'}>
      <Input label="Full name (as on the passport)" value={f.full_name} onChangeText={set('full_name')} error={errs.full_name} autoCapitalize="words" placeholder="Rohit Sharma" />
      <Text style={[type.label, { marginBottom: 8 }]}>Traveller type</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingBottom: sp.m }}>{PAX.map(([k, l]) => <Chip key={k} label={l} active={f.pax_type === k} onPress={() => set('pax_type')(k)} />)}</ScrollView>
      <Text style={[type.label, { marginBottom: 8 }]}>Gender</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingBottom: sp.m }}>{GENDER.map(([k, l]) => <Chip key={k} label={l} active={f.gender === k} onPress={() => set('gender')(f.gender === k ? '' : k)} />)}</ScrollView>
      <DateField label="Date of birth" value={f.dob} onChange={set('dob')} maximumDate={new Date()} initial="1990-01-01" clearable placeholder="Choose a date" />
      <Input label="Nationality" value={f.nationality} onChangeText={set('nationality')} autoCapitalize="words" placeholder="Indian" />

      <Input label={editing && traveler.has_passport ? 'Passport number (saved: type only to replace it)' : 'Passport number'} value={f.passport_no} onChangeText={set('passport_no')} error={errs.passport_no} autoCapitalize="characters" autoCorrect={false} placeholder={editing && traveler.has_passport ? '••••••••' : 'e.g. P1234567'} />
      {editing && traveler.has_passport && (shown
        ? <TouchableOpacity onPress={() => setShown('')} style={{ marginTop: -4, marginBottom: sp.m }}><Text style={{ color: c.text, fontWeight: '800', fontSize: 18, letterSpacing: 1 }}>{shown}</Text><Text style={type.small}>Tap to hide. It hides itself in 20 seconds.</Text></TouchableOpacity>
        : <TouchableOpacity onPress={reveal} disabled={busy === 'show'} style={{ marginTop: -4, marginBottom: sp.m }}><Text style={{ color: c.primary, fontWeight: '700' }}>{busy === 'show' ? 'Loading…' : 'Show the saved number'}</Text><Text style={type.small}>Every look is recorded in the audit log.</Text></TouchableOpacity>)}
      <DateField label="Passport expiry" value={f.passport_expiry} onChange={set('passport_expiry')} minimumDate={new Date(2000, 0, 1)} initial={new Date(Date.now() + 5 * 365 * 86400000).toISOString().slice(0, 10)} clearable placeholder="Choose a date" />
      <Text style={[type.label, { marginBottom: 8 }]}>Visa</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingBottom: sp.m }}>{VISA.map(([k, l]) => <Chip key={k} label={l} active={f.visa_status === k} onPress={() => set('visa_status')(k)} />)}</ScrollView>
      <Input label="Meal preference" value={f.meal_pref} onChangeText={set('meal_pref')} placeholder="Vegetarian, Jain, no beef…" />
      <Btn label={editing ? 'Save changes' : 'Add traveller'} icon="checkmark" onPress={save} busy={busy === 'save'} />
      {editing && <Btn label="Remove traveller" icon="trash" kind="danger" onPress={remove} busy={busy === 'del'} style={{ marginTop: sp.m }} />}
    </Sheet>)
}
