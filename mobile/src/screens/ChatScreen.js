import { useState, useEffect, useCallback, useRef } from 'react'
import { View, Text, FlatList, TextInput, TouchableOpacity, Modal, ScrollView, KeyboardAvoidingView, Platform, Alert, StyleSheet } from 'react-native'
import { chatApi } from '../api'
import { c } from '../theme'
import { composerMode, windowLabel, templateVarCount } from '../logic'
import OfflineBanner from '../OfflineBanner'

const TICK = { sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠', queued: '…' }
const tm = (s) => { const d = new Date(String(s).replace(' ', 'T') + 'Z'); return isNaN(d) ? '' : d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) }

function TemplateSheet({ visible, onClose, onSend }) {
  const [tpls, setTpls] = useState(null)
  const [pick, setPick] = useState(null)
  const [vars, setVars] = useState([])
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (visible && !tpls) chatApi.approvedTemplates().then(r => setTpls(r.data)).catch(e => { Alert.alert('Could not load templates', e.message); onClose() }) }, [visible, tpls, onClose])
  const n = pick ? templateVarCount(pick.body) : 0
  const choose = (t) => { setPick(t); setVars(Array(templateVarCount(t.body)).fill('')) }
  const ready = pick && vars.every(v => v.trim() !== '')
  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: 50 }}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', padding: 14 }}><Text style={{ fontSize: 18, fontWeight: '800', color: c.text }}>{pick ? pick.display_name || pick.name : 'Send a template'}</Text><TouchableOpacity onPress={pick ? () => setPick(null) : onClose}><Text style={{ color: c.primary, fontWeight: '700' }}>{pick ? 'Back' : 'Close'}</Text></TouchableOpacity></View>
        {!pick ? (
          <ScrollView contentContainerStyle={{ padding: 14, gap: 10 }}>
            <Text style={{ color: c.sub }}>The 24-hour window is closed, so WhatsApp only allows an approved template. Replies you receive reopen the window.</Text>
            {!tpls && <Text>Loading…</Text>}
            {tpls && tpls.length === 0 && <Text style={{ color: c.sub }}>No approved templates yet. Create and submit one from the web app (Templates).</Text>}
            {tpls && tpls.map(t => <TouchableOpacity key={t.id} style={s.tpl} onPress={() => choose(t)}><Text style={{ fontWeight: '700', color: c.text }}>{t.display_name || t.name}</Text><Text style={{ color: c.sub, marginTop: 4 }} numberOfLines={3}>{t.body}</Text></TouchableOpacity>)}
          </ScrollView>
        ) : (
          <ScrollView contentContainerStyle={{ padding: 14, gap: 10 }} keyboardShouldPersistTaps="handled">
            <View style={s.tpl}><Text style={{ color: c.text }}>{pick.body}</Text></View>
            {Array.from({ length: n }).map((_, i) => <TextInput key={i} style={s.input} placeholder={`Value for {{${i + 1}}}`} value={vars[i]} onChangeText={v => setVars(a => a.map((x, j) => j === i ? v : x))} />)}
            <TouchableOpacity disabled={!ready || busy} style={[s.send, (!ready || busy) && { opacity: 0.5 }]} onPress={async () => { setBusy(true); try { await onSend(pick, vars); setPick(null); onClose() } catch (e) { Alert.alert('Not sent', e.message) } finally { setBusy(false) } }}>
              <Text style={{ color: '#fff', fontWeight: '800' }}>{busy ? 'Sending…' : 'Send template'}</Text></TouchableOpacity>
          </ScrollView>)}
      </View>
    </Modal>)
}

export default function ChatScreen({ route, navigation }) {
  const { id, name } = route.params
  const [conv, setConv] = useState(null)
  const [msgs, setMsgs] = useState([])
  const [stale, setStale] = useState(null)
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const [sheet, setSheet] = useState(false)
  const listRef = useRef(null)
  const alive = useRef(true)
  useEffect(() => { navigation.setOptions({ title: name }); return () => { alive.current = false } }, [navigation, name])

  const merge = useCallback((incoming) => setMsgs(cur => { const m = new Map(cur.map(x => [x.id, x])); incoming.forEach(x => m.set(x.id, x)); return [...m.values()].sort((a, b) => a.id - b.id) }), [])

  const loadAll = useCallback(async () => {
    try {
      const [m, k] = await Promise.all([chatApi.messagesC(id), chatApi.one(id).catch(() => null)])
      if (!alive.current) return
      setMsgs(m.data); setStale(m.stale ? m.at : null); if (k) setConv(k)
      chatApi.markRead(id).catch(() => {})
    } catch (e) { if (alive.current) Alert.alert('Could not load chat', e.message) }
  }, [id])
  useEffect(() => { loadAll() }, [loadAll])

  // Poll for new messages every 6 s (only what is newer than the last one we hold).
  useEffect(() => {
    const t = setInterval(async () => {
      const last = msgs[msgs.length - 1]
      try {
        const [m, k] = await Promise.all([last ? chatApi.messages(id, last.created_at) : chatApi.messages(id), chatApi.one(id).catch(() => null)])
        if (!alive.current) return
        if (m.length) { merge(m); chatApi.markRead(id).catch(() => {}) }
        if (k) setConv(k)
        setStale(null)
      } catch { /* a missed poll is fine; the next one retries */ }
    }, 6000)
    return () => clearInterval(t)
  }, [id, msgs, merge])
  useEffect(() => { if (msgs.length) setTimeout(() => listRef.current?.scrollToEnd({ animated: false }), 50) }, [msgs.length])

  const mode = composerMode(conv)
  async function sendText() {
    const body = text.trim()
    if (!body || sending) return
    setSending(true)
    try { await chatApi.sendText(id, body); setText(''); const m = await chatApi.messages(id, msgs[msgs.length - 1]?.created_at); merge(m) }
    catch (e) {
      if (e.code === 'WINDOW_CLOSED') { Alert.alert('Window closed', 'The 24-hour reply window just closed. Send a template instead.'); chatApi.one(id).then(setConv).catch(() => {}) }
      else Alert.alert('Not sent', e.message)
    } finally { setSending(false) }
  }
  async function sendTemplate(tpl, vars) { await chatApi.sendTemplate(id, tpl, vars); merge(await chatApi.messages(id, msgs[msgs.length - 1]?.created_at)); chatApi.one(id).then(setConv).catch(() => {}) }

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: c.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={90}>
      <OfflineBanner stale={!!stale} at={stale} />
      {conv && <View style={s.win}><Text style={{ color: mode === 'text' ? c.ok : c.warn, fontSize: 12.5 }}>{windowLabel(conv.seconds_remaining)}</Text>
        {!!conv.contact_id && <TouchableOpacity onPress={() => navigation.navigate('Contact', { id: conv.contact_id })}><Text style={{ color: c.primary, fontSize: 12.5, fontWeight: '700' }}>Contact →</Text></TouchableOpacity>}</View>}
      <FlatList ref={listRef} data={msgs} keyExtractor={m => String(m.id)} contentContainerStyle={{ padding: 12, gap: 6 }}
        renderItem={({ item: m }) => {
          const out = m.direction === 'out'
          return (
            <View style={[s.bubble, out ? s.out : s.in]}>
              {m.type === 'template' && <Text style={{ fontSize: 10.5, color: out ? '#e0e7ff' : c.sub, marginBottom: 2 }}>TEMPLATE</Text>}
              {m.type !== 'text' && m.type !== 'template' && !m.body && <Text style={{ color: out ? '#fff' : c.sub, fontStyle: 'italic' }}>[{m.type}]</Text>}
              {!!m.body && <Text style={{ color: out ? '#fff' : c.text }}>{m.body}</Text>}
              <Text style={{ fontSize: 10.5, color: out ? '#e0e7ff' : c.sub, alignSelf: 'flex-end', marginTop: 2 }}>{tm(m.created_at)}{out ? ` ${TICK[m.status] || ''}` : ''}</Text>
              {m.status === 'failed' && !!m.error && <Text style={{ fontSize: 11, color: '#fecaca' }} numberOfLines={2}>{m.error}</Text>}
            </View>)
        }} />
      {mode === 'text' && (
        <View style={s.bar}>
          <TextInput style={s.msg} placeholder="Type a reply" value={text} onChangeText={setText} multiline maxLength={4000} editable={!stale} />
          <TouchableOpacity disabled={!text.trim() || sending || !!stale} style={[s.send, (!text.trim() || sending || !!stale) && { opacity: 0.5 }]} onPress={sendText}><Text style={{ color: '#fff', fontWeight: '800' }}>{sending ? '…' : 'Send'}</Text></TouchableOpacity>
        </View>)}
      {mode === 'template' && <View style={s.bar}><TouchableOpacity style={[s.send, { flex: 1 }]} disabled={!!stale} onPress={() => setSheet(true)}><Text style={{ color: '#fff', fontWeight: '800' }}>Send an approved template</Text></TouchableOpacity></View>}
      {mode === 'resolved' && <View style={s.bar}><Text style={{ color: c.sub, flex: 1, textAlign: 'center' }}>This conversation is resolved. Reopen it from the web app to reply.</Text></View>}
      <TemplateSheet visible={sheet} onClose={() => setSheet(false)} onSend={sendTemplate} />
    </KeyboardAvoidingView>
  )
}
const s = StyleSheet.create({
  win: { flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: 12, paddingVertical: 6, backgroundColor: c.card, borderBottomWidth: StyleSheet.hairlineWidth, borderColor: c.border },
  bubble: { maxWidth: '82%', paddingVertical: 7, paddingHorizontal: 11, borderRadius: 14 },
  in: { alignSelf: 'flex-start', backgroundColor: c.card, borderWidth: 1, borderColor: c.border }, out: { alignSelf: 'flex-end', backgroundColor: c.primary },
  bar: { flexDirection: 'row', gap: 8, padding: 10, backgroundColor: c.card, borderTopWidth: StyleSheet.hairlineWidth, borderColor: c.border, alignItems: 'flex-end' },
  msg: { flex: 1, maxHeight: 110, borderWidth: 1, borderColor: c.border, borderRadius: 18, paddingHorizontal: 14, paddingVertical: 8, backgroundColor: '#fff' },
  send: { backgroundColor: c.primary, borderRadius: 18, paddingHorizontal: 18, paddingVertical: 11, alignItems: 'center' },
  tpl: { backgroundColor: c.card, borderRadius: 12, padding: 12, borderWidth: 1, borderColor: c.border },
  input: { backgroundColor: '#fff', borderWidth: 1, borderColor: c.border, borderRadius: 10, padding: 10 },
})
