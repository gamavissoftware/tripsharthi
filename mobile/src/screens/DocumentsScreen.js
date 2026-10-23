import { useState, useEffect, useCallback, useRef } from 'react'
import { View, Alert, Linking, Image, TouchableOpacity, Share } from 'react-native'
import { Text } from '../T'
import * as ImagePicker from 'expo-image-picker'
import * as DocumentPicker from 'expo-document-picker'
import { api, docsApi, contactApi } from '../api'
import { c, sp, type, radius } from '../theme'
import { Screen, Card, Pill, Btn, Progress, Sheet, Input, Icon, Skeleton, ErrorBox, Empty, ok } from '../ui'
import { docStatus, groupChecklist, fmtDay, fileSize, digits } from '../logic'

/**
 * A booking's document and visa checklist, per traveller: tick items off, attach a photo or PDF (camera, gallery or file; it counts as received at
 * once), review what the customer uploaded through their portal link, and ask the customer to upload by sending that link on WhatsApp.
 */
export default function DocumentsScreen({ route, navigation }) {
  const { bookingId } = route.params
  const [b, setB] = useState(null)
  const [list, setList] = useState(null)
  const [err, setErr] = useState('')
  const [item, setItem] = useState(null)
  const [busy, setBusy] = useState('')
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])
  const load = useCallback(async () => {
    try { const [bk, cl] = await Promise.all([api.booking(bookingId), docsApi.checklist(bookingId)]); if (alive.current) { setErr(''); setB(bk); setList(cl) } } catch (e) { if (alive.current) setErr(e.message) }
  }, [bookingId])
  useEffect(() => { load() }, [load])

  if (!list || !b) return <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>{err ? <ErrorBox text={err} onRetry={load} /> : <><Skeleton h={90} r={16} /><Skeleton h={220} r={16} /></>}</Screen>

  const groups = groupChecklist(list.items, b.travelers)
  const p = list.progress
  async function askCustomer() {
    setBusy('ask')
    try {
      const l = await docsApi.portalLink(bookingId)
      const missing = list.items.filter(i => i.status === 'pending' && i.required).length
      const msg = `Hi, please upload your travel documents for ${b.title || 'your trip'} here: ${l.url}${missing ? ` (${missing} still needed)` : ''}`
      const phone = b.contact_id ? (await contactApi.get(b.contact_id).catch(() => null))?.wa_number : null
      if (phone) await Linking.openURL(`https://wa.me/${digits(phone)}?text=${encodeURIComponent(msg)}`); else await Share.share({ message: msg })
    } catch (e) { Alert.alert('Could not create the link', e.message) } finally { setBusy('') }
  }

  return (
    <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>
      <Card>
        <Text style={type.h2}>{b.booking_ref} · documents</Text>
        <Text style={[type.small, { marginTop: 4 }]}>{p.total ? `${p.done} of ${p.total} required items done` : 'Nothing required yet. Add the travellers first.'}</Text>
        {p.total > 0 && <View style={{ marginTop: sp.m }}><Progress pct={p.percent} color={p.ready ? c.ok : c.orange} height={10} /></View>}
        {p.ready && <Text style={{ color: c.ok, fontWeight: '700', marginTop: 8 }}>Documents ready</Text>}
        <Btn label="Ask the customer to upload" icon="logo-whatsapp" kind="green" small onPress={askCustomer} busy={busy === 'ask'} style={{ marginTop: sp.m }} />
      </Card>

      {groups.length === 0 && <Empty icon="document-text" title="No checklist yet" text="Add the travellers on the booking page and their document list appears here." />}
      {groups.map((g, gi) => (
        <Card key={gi} pad={false} style={{ paddingHorizontal: sp.l, paddingTop: sp.m }}>
          <Text style={[type.h3, { marginBottom: 2 }]}>{g.name}</Text>
          {g.items.map((i, k) => {
            const [label, tone] = docStatus(i)
            return (
              <TouchableOpacity key={i.id} activeOpacity={0.7} onPress={() => setItem(i)} style={{ flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12, borderTopWidth: 0.5, borderColor: c.line }}>
                <View style={{ flex: 1 }}>
                  <Text style={{ color: c.text, fontWeight: '600' }}>{i.label}{i.required ? '' : ' (optional)'}</Text>
                  {!!i.due_date && i.status === 'pending' && <Text style={type.small}>Needed by {fmtDay(i.due_date)}</Text>}
                  {!!i.notes && i.status === 'pending' && <Text style={{ color: c.warn, fontSize: 12 }} numberOfLines={2}>{i.notes}</Text>}
                </View>
                <Pill tone={tone} text={label} />
                <Icon name="chevron-forward" size={16} color={c.faint} />
              </TouchableOpacity>)
          })}
        </Card>))}
      <Btn label="Open on the web screen" icon="open-outline" kind="ghost" onPress={() => navigation.navigate('Web', { path: `/bookings/${bookingId}`, title: b.booking_ref })} />
      <ItemSheet item={item} onClose={() => setItem(null)} onChanged={() => { setItem(null); load() }} navigation={navigation} bookingId={bookingId} />
    </Screen>)
}

function ItemSheet({ item, onClose, onChanged, navigation, bookingId }) {
  const [files, setFiles] = useState(null)
  const [busy, setBusy] = useState('')
  const [reject, setReject] = useState(null)       // the upload being rejected
  const [reason, setReason] = useState('')
  const load = useCallback(() => { if (item) docsApi.uploads(item.id).then(setFiles).catch(() => setFiles([])) }, [item])
  useEffect(() => { setFiles(null); setReject(null); setReason(''); load() }, [item]) // eslint-disable-line react-hooks/exhaustive-deps

  async function attach(file) {
    setBusy('up')
    try { await docsApi.attach(item.id, file); ok(); onChanged() } catch (e) { Alert.alert('Could not attach it', e.message) } finally { setBusy('') }
  }
  async function photo(camera) {
    try {
      const perm = camera ? await ImagePicker.requestCameraPermissionsAsync() : { granted: true }
      if (!perm.granted) { Alert.alert('Camera is off', 'Allow the camera for TripSarthi in your phone settings to photograph a document.'); return }
      const r = camera ? await ImagePicker.launchCameraAsync({ quality: 0.6 }) : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.6 })
      if (!r.canceled && r.assets && r.assets[0]) { const a = r.assets[0]; await attach({ uri: a.uri, name: a.fileName || 'photo.jpg', mimeType: a.mimeType || 'image/jpeg' }) }
    } catch (e) { Alert.alert('Could not open it', e.message) }
  }
  async function pdf() {
    try { const r = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/*'], copyToCacheDirectory: true }); if (!r.canceled && r.assets && r.assets[0]) { const a = r.assets[0]; await attach({ uri: a.uri, name: a.name, mimeType: a.mimeType }) } } catch (e) { Alert.alert('Could not open it', e.message) }
  }
  async function setStatus(status) {
    setBusy(status)
    try { await docsApi.setItem(item.id, status); ok(); onChanged() } catch (e) { Alert.alert('Could not update', e.message) } finally { setBusy('') }
  }
  async function review(u, accept) {
    if (!accept && !reason.trim()) { Alert.alert('Say why', 'Tell the customer what is wrong, for example "the photo is blurry".'); return }
    setBusy('rv')
    try { await docsApi.review(u.id, accept, reason.trim()); ok(); onChanged() } catch (e) { Alert.alert('Could not save', e.message) } finally { setBusy('') }
  }
  if (!item) return null
  const done = item.status === 'received' || item.status === 'not_applicable'
  return (
    <Sheet visible onClose={onClose} title={item.label}>
      <Text style={[type.small, { marginBottom: sp.m }]}>{done ? (item.status === 'received' ? 'Received.' : 'Marked as not needed.') : item.status === 'uploaded' ? 'The customer uploaded something. Check it below.' : 'Not received yet.'}</Text>

      {(files || []).map(u => (
        <View key={u.id} style={{ borderWidth: 1, borderColor: c.border, borderRadius: radius.m, padding: sp.m, marginBottom: sp.m }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
            <Icon name={u.mime === 'application/pdf' ? 'document-text' : 'image'} size={18} color={c.primary} />
            <Text style={{ flex: 1, color: c.text, fontWeight: '600' }} numberOfLines={1}>{u.name}</Text>
            <Pill tone={u.status === 'accepted' ? 'ok' : u.status === 'rejected' ? 'danger' : 'info'} text={u.status === 'pending' ? 'to check' : u.status} />
          </View>
          <Text style={[type.small, { marginTop: 2 }]}>{fileSize(u.size)} · {fmtDay(String(u.uploaded_at).slice(0, 10))}{u.reject_reason ? ` · ${u.reject_reason}` : ''}</Text>
          {u.mime !== 'application/pdf'
            ? <Image source={docsApiSource(u.id)} resizeMode="contain" style={{ width: '100%', height: 220, marginTop: sp.m, borderRadius: radius.s, backgroundColor: c.bg }} />
            : <Btn label="Open the PDF on the web screen" icon="open-outline" kind="ghost" small onPress={() => { onClose(); navigation.navigate('Web', { path: `/bookings/${bookingId}`, title: 'Documents' }) }} style={{ marginTop: sp.m }} />}
          {u.status === 'pending' && (reject && reject.id === u.id ? (
            <View style={{ marginTop: sp.m }}>
              <Input label="Why is it not good enough? (the customer sees this)" value={reason} onChangeText={setReason} placeholder="The photo is blurry" />
              <View style={{ flexDirection: 'row', gap: 10 }}><Btn label="Cancel" kind="ghost" small onPress={() => setReject(null)} style={{ flex: 1 }} /><Btn label="Reject" kind="danger" small busy={busy === 'rv'} onPress={() => review(u, false)} style={{ flex: 1 }} /></View>
            </View>
          ) : (
            <View style={{ flexDirection: 'row', gap: 10, marginTop: sp.m }}>
              <Btn label="Reject" icon="close" kind="danger" small onPress={() => { setReject(u); setReason('') }} style={{ flex: 1 }} />
              <Btn label="Accept" icon="checkmark" kind="ok" small busy={busy === 'rv'} onPress={() => review(u, true)} style={{ flex: 1 }} />
            </View>))}
        </View>))}
      {files && files.length === 0 && !done && <Text style={[type.small, { marginBottom: sp.m }]}>No file attached yet.</Text>}

      {!done && <>
        <Text style={[type.label, { marginBottom: 8 }]}>Attach a copy</Text>
        <View style={{ flexDirection: 'row', gap: 8, marginBottom: sp.s }}>
          <Btn label="Camera" icon="camera" kind="soft" small onPress={() => photo(true)} busy={busy === 'up'} style={{ flex: 1 }} />
          <Btn label="Gallery" icon="image" kind="soft" small onPress={() => photo(false)} busy={busy === 'up'} style={{ flex: 1 }} />
          <Btn label="PDF / file" icon="document" kind="soft" small onPress={pdf} busy={busy === 'up'} style={{ flex: 1 }} />
        </View>
        <Text style={[type.small, { marginBottom: sp.m }]}>Photos, PDFs up to 5 MB. Kept encrypted. Attaching marks the item as received.</Text>
      </>}
      <View style={{ flexDirection: 'row', gap: 8 }}>
        {item.status !== 'received' && <Btn label="Mark received" icon="checkmark-circle" kind="ok" small onPress={() => setStatus('received')} busy={busy === 'received'} style={{ flex: 1 }} />}
        {item.status !== 'not_applicable' && <Btn label="Not needed" icon="remove-circle-outline" kind="ghost" small onPress={() => setStatus('not_applicable')} busy={busy === 'not_applicable'} style={{ flex: 1 }} />}
        {(item.status === 'received' || item.status === 'not_applicable' || item.status === 'uploaded') && <Btn label="Back to missing" icon="refresh" kind="ghost" small onPress={() => setStatus('pending')} busy={busy === 'pending'} style={{ flex: 1 }} />}
      </View>
    </Sheet>)
}
const docsApiSource = (id) => docsApi.fileSource(id)
