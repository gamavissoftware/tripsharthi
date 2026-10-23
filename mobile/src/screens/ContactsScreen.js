import { useState, useCallback, useRef, useEffect } from 'react'
import { View, FlatList, TextInput, TouchableOpacity, StyleSheet, Platform, Alert } from 'react-native'
import { Text } from '../T'
import { sendOrQueue } from '../outbox'
import * as Contacts from 'expo-contacts'
import { useFocusEffect } from '@react-navigation/native'
import { contactApi } from '../api'
import { c, sp, radius, type } from '../theme'
import { Card, Row, Pill, Icon, Empty, ErrorBox, SkeletonList, Fab, Sheet, Input, Btn, Kpi, ok } from '../ui'
import { validPhone } from '../logic'
import OfflineBanner from '../OfflineBanner'

const STAGE_TONE = { lead: 'info', prospect: 'warn', customer: 'ok', won: 'ok', lost: 'danger' }

export default function ContactsScreen({ navigation, route }) {
  const [q, setQ] = useState('')
  const [rows, setRows] = useState(null)
  const [page, setPage] = useState({ cur: 1, count: 1, total: 0 })
  const [sum, setSum] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [err, setErr] = useState('')
  const [more, setMore] = useState(false)
  const [sheet, setSheet] = useState(false)
  const alive = useRef(true), seq = useRef(0)
  useEffect(() => () => { alive.current = false }, [])

  const load = useCallback(async (query, p = 1) => {
    const my = ++seq.current                                           // a slow older search must never overwrite a newer one
    try {
      setErr(''); const r = await contactApi.listC(query, p)
      if (!alive.current || my !== seq.current) return
      setRows(prev => (p > 1 && prev ? [...prev, ...r.data.rows] : r.data.rows)); setMeta({ stale: r.stale, at: r.at })
      setPage({ cur: Number(r.data.pager?.currentPage || 1), count: Number(r.data.pager?.pageCount || 1), total: Number(r.data.pager?.total || r.data.rows.length) })
    } catch (e) { if (alive.current && my === seq.current) setErr(e.message) }
  }, [])
  useFocusEffect(useCallback(() => { load(q); contactApi.summaryC().then(r => alive.current && setSum(r.data)).catch(() => {}) }, [])) // eslint-disable-line react-hooks/exhaustive-deps
  async function importFromPhone() {
    try {
      const p = await Contacts.presentContactPickerAsync()
      if (!p) return
      const num = (p.phoneNumbers || []).map(x => x.number || x.digits).find(Boolean)
      const name = p.name || [p.firstName, p.lastName].filter(Boolean).join(' ') || ''
      if (!num) { Alert.alert('No phone number', 'That contact has no phone number saved, so it cannot be added.'); return }
      const d = String(num).replace(/\D/g, '').replace(/^0+/, '')
      const wa = String(num).trim().startsWith('+') ? d : (d.length === 10 ? '91' + d : d)
      const email = (p.emails || []).map(x => x.email || x.address).find(Boolean)
      const r = await sendOrQueue('contact', { name: name.trim() || num, wa_number: wa, email: email || undefined, source: 'manual' }, name || num)
      if (r.queued) Alert.alert('Saved on your phone', 'No internet right now. This contact will be added automatically when you are back online.')
      else { load(q.trim()); if (r.data && r.data.id) navigation.navigate('Contact', { id: Number(r.data.id) }) }
    } catch (e) { Alert.alert('Could not import', e.message) }
  }
  useEffect(() => { if (route?.params?.new) setSheet(true) }, [route?.params?.new])           // the Home shortcut opens the form straight away
  useEffect(() => { const t = setTimeout(() => load(q.trim()), 300); return () => clearTimeout(t) }, [q, load])   // search as you type

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <View style={{ padding: sp.l, paddingBottom: sp.s }}>
        <View style={s.search}>
          <Icon name="search" size={18} color={c.faint} />
          <TextInput value={q} onChangeText={setQ} placeholder="Search name, phone or e-mail" placeholderTextColor={c.faint} style={{ flex: 1, fontSize: 15, color: c.text, paddingVertical: 2 }} autoCapitalize="none" returnKeyType="search" />
          {!!q && <TouchableOpacity onPress={() => setQ('')} hitSlop={10}><Icon name="close-circle" size={18} color={c.faint} /></TouchableOpacity>}
        </View>
        {sum && !q && <View style={{ flexDirection: 'row', gap: 10, marginTop: sp.m }}>
          <Mini label="Contacts" value={sum.total} /><Mini label="New (30 days)" value={sum.new_30d} /><Mini label="Opted in" value={sum.opted_in} />
        </View>}
      </View>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      {!!err && <View style={{ padding: sp.l }}><ErrorBox text={err} onRetry={() => load(q.trim())} /></View>}
      {!rows && !err && <SkeletonList n={5} />}
      {rows && (
        <FlatList keyboardShouldPersistTaps="handled" keyboardDismissMode={Platform.OS === 'ios' ? 'on-drag' : 'on-drag'} data={rows} keyExtractor={x => String(x.id)} contentContainerStyle={{ paddingHorizontal: sp.l, paddingBottom: 110 }} showsVerticalScrollIndicator={false}
          ListEmptyComponent={<Empty icon="people" title={q ? 'No one found' : 'No contacts yet'} text={q ? 'Try a different name or number.' : 'Add the people who enquire with you.'} action={q ? undefined : 'Add a contact'} onAction={() => setSheet(true)} />}
          ListFooterComponent={page.cur < page.count ? <Btn label={more ? 'Loading…' : `Load more (${rows.length} of ${page.total})`} kind="ghost" small busy={more} style={{ marginTop: sp.m }} onPress={async () => { setMore(true); await load(q.trim(), page.cur + 1); setMore(false) }} /> : null}
          renderItem={({ item: x, index }) => (
            <Card pad={false} style={{ marginBottom: sp.s, paddingHorizontal: sp.l }}>
              <Row avatar={x.name || x.wa_number} title={x.name || x.wa_number} last sub={[x.wa_number, x.city].filter(Boolean).join(' · ')} onPress={() => navigation.navigate('Contact', { id: Number(x.id) })}
                right={<View style={{ alignItems: 'flex-end', gap: 4 }}>{!!x.lifecycle_stage && <Pill tone={STAGE_TONE[x.lifecycle_stage] || 'neutral'} text={x.lifecycle_stage} />}{String(x.opt_in) === '0' && <Text style={{ fontSize: 10.5, color: c.danger, fontWeight: '700' }}>opted out</Text>}</View>} />
            </Card>)} />)}
      <TouchableOpacity onPress={importFromPhone} activeOpacity={0.85} accessibilityLabel="Import from phone" style={{ position: 'absolute', right: 22, bottom: 94, width: 48, height: 48, borderRadius: 24, backgroundColor: c.card, borderWidth: 1, borderColor: c.border, alignItems: 'center', justifyContent: 'center' }}><Icon name="download" size={22} color={c.primary} /></TouchableOpacity>
      <Fab icon="person-add" label="New contact" onPress={() => setSheet(true)} />
      <NewContact visible={sheet} onClose={() => setSheet(false)} onSaved={(ct) => { setSheet(false); load(q.trim()); if (ct?.id) navigation.navigate('Contact', { id: Number(ct.id) }) }} />
    </View>)
}

function Mini({ label, value }) {
  return <View style={[s.mini]}><Text style={{ fontSize: 19, fontWeight: '800', color: c.text }}>{Number(value || 0).toLocaleString('en-IN')}</Text><Text style={type.small}>{label}</Text></View>
}

function NewContact({ visible, onClose, onSaved }) {
  const [f, setF] = useState({ name: '', phone: '', email: '' })
  const [errs, setErrs] = useState({})
  const [busy, setBusy] = useState(false)
  const set = (k) => (v) => { setF(x => ({ ...x, [k]: v })); setErrs(e => ({ ...e, [k]: undefined })) }
  async function save() {
    const e = {}
    if (!f.name.trim()) e.name = 'Enter a name'
    if (!validPhone(f.phone)) e.phone = 'Enter a valid mobile number'
    if (f.email.trim() && !/^\S+@\S+\.\S+$/.test(f.email.trim())) e.email = 'That e-mail does not look right'
    setErrs(e); if (Object.keys(e).length) return
    setBusy(true)
    try {
      const digits = f.phone.replace(/\D/g, '').replace(/^0+/, '')
      const wa = f.phone.trim().startsWith('+') ? digits : (digits.length === 10 ? '91' + digits : digits)
      const r = await sendOrQueue('contact', { name: f.name.trim(), wa_number: wa, email: f.email.trim() || undefined, source: 'manual' }, f.name.trim())
      ok(); setF({ name: '', phone: '', email: '' })
      if (r.queued) Alert.alert('Saved on your phone', 'No internet right now. This contact will be added automatically as soon as you are back online.')
      onSaved(r.queued ? null : r.data)
    } catch (er) { setErrs({ phone: er.message }) } finally { setBusy(false) }
  }
  return (
    <Sheet visible={visible} onClose={onClose} title="New contact">
      <Input label="Name" value={f.name} onChangeText={set('name')} placeholder="Customer name" error={errs.name} autoCapitalize="words" />
      <Input label="Mobile / WhatsApp number" value={f.phone} onChangeText={set('phone')} placeholder="98765 43210" keyboardType="phone-pad" error={errs.phone} />
      <Input label="E-mail (optional)" value={f.email} onChangeText={set('email')} placeholder="name@example.com" keyboardType="email-address" autoCapitalize="none" error={errs.email} />
      <Btn label="Save contact" icon="checkmark" onPress={save} busy={busy} />
    </Sheet>)
}

const s = StyleSheet.create({
  search: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c.card, borderRadius: radius.l, paddingHorizontal: 14, paddingVertical: 11, borderWidth: 1, borderColor: c.border },
  mini: { flex: 1, backgroundColor: c.card, borderRadius: radius.m, padding: 12, borderWidth: StyleSheet.hairlineWidth, borderColor: c.border },
})
