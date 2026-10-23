import { useState, useEffect, useCallback, useRef } from 'react'
import { View, FlatList, TextInput, TouchableOpacity, Platform, Alert, StyleSheet, ActivityIndicator, Image, Linking } from 'react-native'
import { Text } from '../T'
import * as ImagePicker from 'expo-image-picker'
import * as DocumentPicker from 'expo-document-picker'
import { chatApi, biz } from '../api'
import { c, sp, radius, type } from '../theme'
import { composerMode, windowLabel, templateVarCount, mediaKind, fileSize } from '../logic'
import { Icon, Sheet, Btn, Input, Empty, tap, useKeyboardHeight, Row, ok } from '../ui'
import OfflineBanner from '../OfflineBanner'
import VoiceBar from '../VoiceBar'
import VoicePlayer from '../VoicePlayer'
import { sendOrQueueRaw, outboxItems, onOutboxChange } from '../outbox'

const TICK = { sent: 'checkmark', delivered: 'checkmark-done', read: 'checkmark-done', failed: 'alert-circle', queued: 'time-outline' }
const parse = (s) => new Date(String(s).replace(' ', 'T') + 'Z')
const tm = (s) => { const d = parse(s); return isNaN(d) ? '' : d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) }
const dayLabel = (s) => { const d = parse(s), n = new Date(); if (d.toDateString() === n.toDateString()) return 'Today'; if (d.toDateString() === new Date(n.getTime() - 86400000).toDateString()) return 'Yesterday'; return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'long' }) }

function TemplateSheet({ visible, onClose, onSend }) {
  const [tpls, setTpls] = useState(null)
  const [pick, setPick] = useState(null)
  const [vars, setVars] = useState([])
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (visible && !tpls) chatApi.approvedTemplates().then(r => setTpls(r.data)).catch(e => { Alert.alert('Could not load templates', e.message); onClose() }) }, [visible, tpls, onClose])
  const n = pick ? templateVarCount(pick.body) : 0
  const choose = (t) => { setPick(t); setVars(Array(templateVarCount(t.body)).fill('')) }
  const ready = pick && vars.every(v => v.trim() !== '')
  const close = () => { setPick(null); onClose() }
  return (
    <Sheet visible={visible} onClose={close} title={pick ? (pick.display_name || pick.name) : 'Send a template'}>
      {!pick ? (<>
        <Text style={[type.small, { marginBottom: sp.m }]}>The 24-hour window is closed, so WhatsApp only allows an approved template. When the customer replies, the window opens again.</Text>
        {!tpls && <Text style={type.small}>Loading…</Text>}
        {tpls && tpls.length === 0 && <Empty icon="document-text" title="No approved templates" text="Create and submit one from the web app (Templates). Meta usually approves within minutes to a day." />}
        {tpls && tpls.map(t => (
          <TouchableOpacity key={t.id} style={s.tpl} activeOpacity={0.8} onPress={() => choose(t)}>
            <Text style={{ fontWeight: '700', color: c.text }}>{t.display_name || t.name}</Text><Text style={[type.small, { marginTop: 4 }]} numberOfLines={3}>{t.body}</Text>
          </TouchableOpacity>))}
      </>) : (<>
        <View style={[s.tpl, { backgroundColor: '#eaf6ee', borderColor: '#cfe9d8' }]}><Text style={{ color: c.text, lineHeight: 20 }}>{pick.body}</Text></View>
        {Array.from({ length: n }).map((_, i) => <Input key={i} label={`Value for {{${i + 1}}}`} value={vars[i]} onChangeText={v => setVars(a => a.map((x, j) => (j === i ? v : x)))} />)}
        <Btn label="Send template" icon="send" kind="green" disabled={!ready} busy={busy} onPress={async () => { setBusy(true); try { await onSend(pick, vars); close() } catch (e) { Alert.alert('Not sent', e.message) } finally { setBusy(false) } }} />
        <Btn label="Choose another" kind="ghost" small style={{ marginTop: sp.s }} onPress={() => setPick(null)} />
      </>)}
    </Sheet>)
}

export default function ChatScreen({ route, navigation }) {
  const { id, name } = route.params
  const [conv, setConv] = useState(null)
  const [msgs, setMsgs] = useState([])
  const [stale, setStale] = useState(null)
  const [loaded, setLoaded] = useState(false)
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const [sheet, setSheet] = useState(false)
  const [menu, setMenu] = useState(false)          // assign / resolve / open on the web
  const [attach, setAttach] = useState(false)       // choose photo / camera / document
  const [pending, setPending] = useState(null)      // a picked file waiting to be sent: { uri, name, mimeType, size, type }
  const [me, setMe] = useState(null)
  const [recording, setRecording] = useState(false)
  const [waiting, setWaiting] = useState([])           // replies saved on the phone while offline, for THIS chat
  // a voice note is being recorded
  const listRef = useRef(null)
  const kb = useKeyboardHeight()
  const alive = useRef(true)
  useEffect(() => { navigation.setOptions({ title: name }); return () => { alive.current = false } }, [navigation, name])
  useEffect(() => { biz.me().then(setMe).catch(() => {}) }, [])
  useEffect(() => {
    navigation.setOptions({ headerRight: () => <TouchableOpacity onPress={() => setMenu(true)} hitSlop={12} accessibilityLabel="Conversation options"><Icon name="ellipsis-vertical" size={22} color={c.primary} /></TouchableOpacity> })
  }, [navigation])

  const merge = useCallback((incoming) => setMsgs(cur => { const m = new Map(cur.map(x => [x.id, x])); incoming.forEach(x => m.set(x.id, x)); return [...m.values()].sort((a, b) => a.id - b.id) }), [])

  const loadAll = useCallback(async () => {
    try {
      const [m, k] = await Promise.all([chatApi.messagesC(id), chatApi.one(id).catch(() => null)])
      if (!alive.current) return
      setMsgs(m.data); setStale(m.stale ? m.at : null); setLoaded(true); if (k) setConv(k)
      chatApi.markRead(id).catch(() => {})
    } catch (e) { if (alive.current) { setLoaded(true); Alert.alert('Could not load chat', e.message) } }
  }, [id])
  useEffect(() => { loadAll() }, [loadAll])

  // Poll for new messages every 6 s: only what is newer than the last message we hold, by id (a timestamp would skip same-second messages).
  useEffect(() => {
    const t = setInterval(async () => {
      const last = msgs[msgs.length - 1]
      try {
        const [m, k] = await Promise.all([last ? chatApi.messages(id, last.id) : chatApi.messages(id), chatApi.one(id).catch(() => null)])
        if (!alive.current) return
        if (m.length) { merge(m); chatApi.markRead(id).catch(() => {}) }
        if (k) setConv(k)
        setStale(null)
      } catch { /* a missed poll is fine; the next one retries */ }
    }, 6000)
    return () => clearInterval(t)
  }, [id, msgs, merge])
  useEffect(() => { if (msgs.length) setTimeout(() => listRef.current?.scrollToEnd({ animated: false }), 50) }, [msgs.length, kb])

  useEffect(() => {
    const read = () => outboxItems().then(a => setWaiting(a.filter(x => x.kind === 'chat' && x.path === `/inbox/${id}/messages`))).catch(() => {})
    read(); return onOutboxChange(read)
  }, [id])
  const mode = composerMode(conv)
  async function sendText() {
    const body = text.trim()
    if (!body || sending) return
    setSending(true)
    try {
      const r = await sendOrQueueRaw({ path: `/inbox/${id}/messages`, body: { type: 'text', body }, summary: `Chat reply: ${body.slice(0, 40)}`, kind: 'chat' })
      setText('')
      if (!r.queued) merge(await chatApi.messages(id, msgs[msgs.length - 1]?.id))
    } catch (e) {
      if (e.code === 'WINDOW_CLOSED') { Alert.alert('Window closed', 'The 24-hour reply window just closed. Send a template instead.'); chatApi.one(id).then(setConv).catch(() => {}) }
      else Alert.alert('Not sent', e.message)
    } finally { setSending(false) }
  }
  // ---- attachments ----
  const take = (a) => setPending({ uri: a.uri, name: a.fileName || a.name || 'file', mimeType: a.mimeType || a.type || 'application/octet-stream', size: a.fileSize || a.size, type: mediaKind(a.mimeType || a.type) })
  async function pickPhoto(camera) {
    setAttach(false)
    try {
      const perm = camera ? await ImagePicker.requestCameraPermissionsAsync() : { granted: true }
      if (!perm.granted) { Alert.alert('Camera is off', 'Allow the camera for TripSarthi in your phone settings to take a photo.'); return }
      const r = camera ? await ImagePicker.launchCameraAsync({ quality: 0.7 }) : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images', 'videos'], quality: 0.7 })
      if (!r.canceled && r.assets && r.assets[0]) take(r.assets[0])
    } catch (e) { Alert.alert('Could not open it', e.message) }
  }
  async function pickDocument() {
    setAttach(false)
    try { const r = await DocumentPicker.getDocumentAsync({ copyToCacheDirectory: true }); if (!r.canceled && r.assets && r.assets[0]) take(r.assets[0]) } catch (e) { Alert.alert('Could not open it', e.message) }
  }
  async function sendFile() {
    if (!pending || sending) return
    if (pending.size && pending.size > 16 * 1048576) { Alert.alert('File is too big', 'WhatsApp allows files up to 16 MB. Choose a smaller one.'); return }
    setSending(true)
    try {
      const up = await chatApi.uploadMedia(id, pending)
      await chatApi.sendMedia(id, { type: pending.type, media_id: up.media_id, caption: text.trim(), filename: up.filename || pending.name })
      setPending(null); setText(''); ok()
      merge(await chatApi.messages(id, msgs[msgs.length - 1]?.id))
    } catch (e) {
      if (e.code === 'WINDOW_CLOSED') { Alert.alert('Window closed', 'The 24-hour reply window just closed. Send a template instead.'); chatApi.one(id).then(setConv).catch(() => {}) }
      else Alert.alert('Not sent', e.message)
    } finally { setSending(false) }
  }
  async function sendVoice(file) {
    setRecording(false); setSending(true)
    try {
      const up = await chatApi.uploadMedia(id, file)
      await chatApi.sendMedia(id, { type: 'audio', media_id: up.media_id, filename: up.filename || file.name })
      ok(); merge(await chatApi.messages(id, msgs[msgs.length - 1]?.id))
    } catch (e) {
      if (e.code === 'WINDOW_CLOSED') { Alert.alert('Window closed', 'The 24-hour reply window just closed. Send a template instead.'); chatApi.one(id).then(setConv).catch(() => {}) }
      else Alert.alert('Voice note not sent', e.message)
    } finally { setSending(false) }
  }
  // ---- conversation actions ----
  async function act(fn, done) { setMenu(false); try { await fn(); ok(); chatApi.one(id).then(setConv).catch(() => {}); if (done) Alert.alert(done) } catch (e) { Alert.alert('Could not do that', e.message) } }
  async function sendTemplate(tpl, vars) { await chatApi.sendTemplate(id, tpl, vars); merge(await chatApi.messages(id, msgs[msgs.length - 1]?.id)); chatApi.one(id).then(setConv).catch(() => {}) }

  // day separators between messages from different days
  const data = msgs.map((m, i) => ({ ...m, _day: i === 0 || parse(msgs[i - 1].created_at).toDateString() !== parse(m.created_at).toDateString() ? dayLabel(m.created_at) : null }))
  const winOpen = mode === 'text'

  return (
    <View style={{ flex: 1, backgroundColor: '#eaf0f7', paddingBottom: kb }}>
      <OfflineBanner stale={!!stale} at={stale} />
      {conv && (
        <View style={[s.win, { backgroundColor: winOpen ? c.okSoft : c.warnSoft }]}>
          <Icon name={winOpen ? 'checkmark-circle' : 'time'} size={16} color={winOpen ? c.ok : c.warn} />
          <Text style={{ color: winOpen ? '#0f7a54' : '#b45309', fontSize: 12.5, fontWeight: '600', flex: 1 }}>{windowLabel(conv.seconds_remaining)}</Text>
          {!!conv.contact_id && <TouchableOpacity onPress={() => navigation.navigate('Contact', { id: Number(conv.contact_id) })} hitSlop={8}><Text style={{ color: c.primary, fontSize: 12.5, fontWeight: '800' }}>Contact →</Text></TouchableOpacity>}
        </View>)}
      <FlatList ref={listRef} data={data} keyExtractor={m => String(m.id)} contentContainerStyle={{ padding: 12, gap: 4 }} showsVerticalScrollIndicator={false}
        ListFooterComponent={waiting.length ? <View>{waiting.map(w => (
          <View key={w.id} style={[s.bubble, s.out, { opacity: 0.7, marginTop: 4 }]}>
            <Text style={{ color: '#fff', fontSize: 15, lineHeight: 20 }}>{w.body && w.body.body}</Text>
            <View style={{ flexDirection: 'row', alignSelf: 'flex-end', alignItems: 'center', gap: 4, marginTop: 3 }}><Icon name="time-outline" size={13} color="#cfe2f8" /><Text style={{ fontSize: 10.5, color: '#cfe2f8' }}>Waiting to send</Text></View>
          </View>))}</View> : null}
        ListEmptyComponent={loaded ? <Empty icon="chatbubble-ellipses" title="No messages yet" text="Say hello once the customer writes in." /> : <View style={{ padding: sp.xl }}><ActivityIndicator color={c.primary} /></View>}
        renderItem={({ item: m }) => {
          const out = m.direction === 'out'
          return (
            <View>
              {!!m._day && <View style={s.day}><Text style={{ color: c.sub, fontSize: 11.5, fontWeight: '700' }}>{m._day}</Text></View>}
              <View style={[s.bubble, out ? s.out : s.in]}>
                {m.type === 'template' && <Text style={{ fontSize: 10, fontWeight: '800', letterSpacing: 0.6, color: out ? '#d4e6fa' : c.faint, marginBottom: 3 }}>TEMPLATE</Text>}
                {m.media_url && m.type === 'image' && (
                  <TouchableOpacity activeOpacity={0.85} onPress={() => Linking.openURL(chatApi.mediaUrl(m.media_url)).catch(() => {})} accessibilityLabel="Open photo">
                    <Image source={{ uri: chatApi.mediaUrl(m.media_url) }} style={{ width: 220, height: 220, borderRadius: 12, backgroundColor: '#dfe6f1', marginBottom: m.body ? 6 : 0 }} resizeMode="cover" />
                  </TouchableOpacity>)}
                {m.media_url && m.type === 'audio' && <VoicePlayer uri={chatApi.mediaUrl(m.media_url)} out={out} />}
                {m.media_url && m.type !== 'image' && m.type !== 'audio' && m.type !== 'text' && m.type !== 'template' && (
                  <TouchableOpacity activeOpacity={0.8} onPress={() => Linking.openURL(chatApi.mediaUrl(m.media_url)).catch(() => {})} style={{ flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 4 }} accessibilityLabel={`Open ${m.type}`}>
                    <View style={{ width: 40, height: 40, borderRadius: 12, backgroundColor: out ? 'rgba(255,255,255,0.2)' : c.primarySoft, alignItems: 'center', justifyContent: 'center' }}><Icon name={m.type === 'audio' ? 'play' : m.type === 'video' ? 'videocam' : 'document-text'} size={20} color={out ? '#fff' : c.primary} /></View>
                    <Text style={{ color: out ? '#fff' : c.text, fontWeight: '600', flexShrink: 1 }} numberOfLines={2}>{m.type === 'audio' ? 'Voice message' : m.type === 'video' ? 'Video' : (m.filename || m.body || 'Document')}</Text>
                  </TouchableOpacity>)}
                {m.type !== 'text' && m.type !== 'template' && !m.body && !m.media_url && <Text style={{ color: out ? '#fff' : c.sub, fontStyle: 'italic' }}>[{m.type}]</Text>}
                {!!m.body && <Text style={{ color: out ? '#fff' : c.text, fontSize: 15, lineHeight: 20 }}>{m.body}</Text>}
                <View style={{ flexDirection: 'row', alignSelf: 'flex-end', alignItems: 'center', gap: 4, marginTop: 3 }}>
                  <Text style={{ fontSize: 10.5, color: out ? '#cfe2f8' : c.faint }}>{tm(m.created_at)}</Text>
                  {out && <Icon name={TICK[m.status] || 'checkmark'} size={13} color={m.status === 'read' ? '#7fe3ff' : m.status === 'failed' ? '#ffb3ba' : '#cfe2f8'} />}
                </View>
                {m.status === 'failed' && !!m.error && <Text style={{ fontSize: 11, color: '#ffd5d9', marginTop: 2 }} numberOfLines={2}>{m.error}</Text>}
              </View>
            </View>)
        }} />
      {mode === 'text' && (
        <View style={{ backgroundColor: c.card, borderTopWidth: StyleSheet.hairlineWidth, borderColor: c.border }}>
          {pending && (
            <View style={s.pend}>
              <View style={{ width: 40, height: 40, borderRadius: 10, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center' }}><Icon name={pending.type === 'image' ? 'image' : pending.type === 'video' ? 'videocam' : 'document-text'} size={20} color={c.primary} /></View>
              <View style={{ flex: 1 }}><Text style={{ color: c.text, fontWeight: '600' }} numberOfLines={1}>{pending.name}</Text><Text style={type.small}>{fileSize(pending.size) || pending.type}. Add a message below, or send as it is.</Text></View>
              <TouchableOpacity onPress={() => setPending(null)} hitSlop={12} accessibilityLabel="Remove the attachment"><Icon name="close-circle" size={24} color={c.faint} /></TouchableOpacity>
            </View>)}
          {recording ? <VoiceBar onDone={sendVoice} onCancel={() => setRecording(false)} sending={sending} /> : (
          <View style={[s.bar, { borderTopWidth: 0 }]}>
            <TouchableOpacity onPress={() => setAttach(true)} disabled={!!stale || sending} hitSlop={8} style={s.clip} accessibilityLabel="Attach a photo or document"><Icon name="attach" size={24} color={c.sub} /></TouchableOpacity>
            <TextInput style={s.msg} placeholder={pending ? 'Add a caption (optional)' : 'Type a reply'} placeholderTextColor={c.faint} value={text} onChangeText={setText} multiline maxLength={4000} />
            {!text.trim() && !pending && !sending ? (
              <TouchableOpacity disabled={!!stale} style={[s.send, !!stale && { opacity: 0.45 }]} onPress={() => { tap(); setRecording(true) }} accessibilityLabel="Record a voice note">
                <Icon name="mic" size={21} color="#fff" />
              </TouchableOpacity>
            ) : (
              <TouchableOpacity disabled={sending} style={[s.send, sending && { opacity: 0.45 }]} onPress={() => { tap(); pending ? sendFile() : sendText() }} accessibilityLabel="Send">
                {sending ? <ActivityIndicator color="#fff" size="small" /> : <Icon name="send" size={19} color="#fff" />}
              </TouchableOpacity>)}
          </View>
          )}
        </View>)}
      {mode === 'template' && <View style={s.bar}><Btn label="Send an approved template" icon="document-text" onPress={() => setSheet(true)} disabled={!!stale} style={{ flex: 1 }} /></View>}
      {mode === 'resolved' && <View style={s.bar}><Text style={{ color: c.sub, flex: 1, textAlign: 'center' }}>This conversation is resolved. Reopen it from the web app to reply.</Text></View>}
      <TemplateSheet visible={sheet} onClose={() => setSheet(false)} onSend={sendTemplate} />
      <Sheet visible={attach} onClose={() => setAttach(false)} title="Send a file">
        <Row icon="images" tone={c.primary} title="Photo or video" sub="From your gallery" onPress={() => pickPhoto(false)} />
        <Row icon="camera" tone={c.teal} title="Take a photo" sub="Use the camera" onPress={() => pickPhoto(true)} />
        <Row icon="document-text" tone={c.orange} title="Document" sub="PDF, Word, Excel and more (up to 16 MB)" onPress={pickDocument} last />
      </Sheet>
      <Sheet visible={menu} onClose={() => setMenu(false)} title="This conversation">
        {conv && conv.assigned_user_id && me && String(conv.assigned_user_id) === String(me.id)
          ? <Row icon="person-remove" tone={c.warn} title="Unassign from me" onPress={() => act(() => chatApi.assign(id, 0), 'Unassigned.')} />
          : <Row icon="person-add" tone={c.primary} title="Assign to me" sub="So your team knows you are handling it" onPress={() => me && act(() => chatApi.assign(id, Number(me.id)), 'Assigned to you.')} />}
        {conv && conv.status === 'resolved'
          ? <Row icon="refresh-circle" tone={c.ok} title="Reopen" sub="Move it back to open chats" onPress={() => act(() => chatApi.reopen(id), 'Reopened.')} />
          : <Row icon="checkmark-circle" tone={c.ok} title="Mark as resolved" sub="Done with this customer for now" onPress={() => act(() => chatApi.resolve(id), 'Marked as resolved.')} />}
        {!!conv?.contact_id && <Row icon="person-circle" tone={c.purple} title="Open the contact" onPress={() => { setMenu(false); navigation.navigate('Contact', { id: Number(conv.contact_id) }) }} />}
        <Row icon="open-outline" tone={c.navy} title="Full inbox (notes, AI, more)" sub="The complete WhatsApp inbox, inside the app" onPress={() => { setMenu(false); navigation.navigate('Web', { path: '/inbox', title: 'WhatsApp inbox' }) }} last />
      </Sheet>
    </View>)
}
const s = StyleSheet.create({
  win: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 14, paddingVertical: 9 },
  day: { alignSelf: 'center', backgroundColor: 'rgba(255,255,255,0.8)', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 4, marginVertical: 10 },
  bubble: { maxWidth: '82%', paddingVertical: 8, paddingHorizontal: 12, borderRadius: 18 },
  in: { alignSelf: 'flex-start', backgroundColor: c.card, borderBottomLeftRadius: 5 }, out: { alignSelf: 'flex-end', backgroundColor: c.primary, borderBottomRightRadius: 5 },
  bar: { flexDirection: 'row', gap: 10, padding: 10, backgroundColor: c.card, borderTopWidth: StyleSheet.hairlineWidth, borderColor: c.border, alignItems: 'flex-end' },
  msg: { flex: 1, maxHeight: 120, borderWidth: 1, borderColor: c.border, borderRadius: 22, paddingHorizontal: 16, paddingVertical: 10, backgroundColor: c.bg, fontSize: 15, color: c.text },
  clip: { width: 42, height: 46, alignItems: 'center', justifyContent: 'center' },
  pend: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 10, paddingBottom: 2 },
  send: { backgroundColor: c.primary, width: 46, height: 46, borderRadius: 23, alignItems: 'center', justifyContent: 'center' },
  tpl: { backgroundColor: c.bg, borderRadius: radius.m, padding: 14, borderWidth: 1, borderColor: c.border, marginBottom: sp.s },
})
