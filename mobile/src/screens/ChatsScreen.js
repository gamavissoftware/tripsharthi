import { useState, useCallback, useEffect, useRef } from 'react'
import { View, FlatList, TouchableOpacity, TextInput, StyleSheet, Platform } from 'react-native'
import { Text } from '../T'
import { useFocusEffect } from '@react-navigation/native'
import { chatApi } from '../api'
import { c, sp, radius, type } from '../theme'
import { Avatar, Icon, Empty, ErrorBox, SkeletonList, Pill, Chip } from '../ui'
import OfflineBanner from '../OfflineBanner'

const parse = (s) => new Date(String(s).replace(' ', 'T') + 'Z')
const when = (s) => {
  if (!s) return ''
  const d = parse(s), now = new Date()
  if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })
  const y = new Date(now.getTime() - 86400000)
  if (d.toDateString() === y.toDateString()) return 'Yesterday'
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
}

export default function ChatsScreen({ navigation, onUnread }) {
  const [res, setRes] = useState(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [only, setOnly] = useState('all')
  const [refreshing, setRefreshing] = useState(false)
  const [hits, setHits] = useState(null)             // messages that contain what was typed (3+ characters)
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])

  const load = useCallback(async () => {
    try {
      const r = await chatApi.list()
      if (!alive.current) return
      setRes(r); setErr('')
      onUnread?.(r.stale ? 0 : r.data.reduce((n, x) => n + Number(x.unread_count || 0), 0))
    } catch (e) { if (alive.current) setErr(e.message) }
  }, [onUnread])
  useEffect(() => {
    const t = q.trim()
    if (t.length < 3) { setHits(null); return }
    let dead = false
    const timer = setTimeout(() => { chatApi.search(t).then(r => { if (!dead && alive.current) setHits(r) }).catch(() => { if (!dead) setHits([]) }) }, 450)
    return () => { dead = true; clearTimeout(timer) }
  }, [q])
  // Refresh on focus and every 15 s while this tab is showing.
  useFocusEffect(useCallback(() => { load(); const t = setInterval(load, 15000); return () => clearInterval(t) }, [load]))

  const all = res?.data || []
  const unread = all.filter(x => Number(x.unread_count) > 0).length
  const rows = all.filter(x => {
    if (only === 'unread' && !(Number(x.unread_count) > 0)) return false
    if (only === 'open' && x.send_mode !== 'free_form') return false
    const t = q.trim().toLowerCase()
    return !t || String(x.contact_name || '').toLowerCase().includes(t) || String(x.wa_number || '').includes(t)
  })
  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <View style={{ padding: sp.l, paddingBottom: 0 }}>
        <View style={s.search}><Icon name="search" size={18} color={c.faint} /><TextInput value={q} onChangeText={setQ} placeholder="Search chats and messages" placeholderTextColor={c.faint} style={{ flex: 1, fontSize: 15, color: c.text, paddingVertical: 2 }} autoCapitalize="none" /></View>
        <View style={{ flexDirection: 'row', gap: 8, paddingVertical: sp.m }}>
          <Chip label="All" active={only === 'all'} onPress={() => setOnly('all')} count={all.length} />
          <Chip label="Unread" active={only === 'unread'} onPress={() => setOnly('unread')} count={unread} />
          <Chip label="Can reply now" active={only === 'open'} onPress={() => setOnly('open')} />
        </View>
      </View>
      <OfflineBanner stale={res?.stale} at={res?.at} />
      {!!err && <View style={{ paddingHorizontal: sp.l }}><ErrorBox text={err} onRetry={load} /></View>}
      {!res && !err ? <SkeletonList n={5} /> : (
        <FlatList keyboardShouldPersistTaps="handled" keyboardDismissMode={Platform.OS === 'ios' ? 'on-drag' : 'on-drag'} data={rows} keyExtractor={x => String(x.id)} contentContainerStyle={{ paddingBottom: 40 }} showsVerticalScrollIndicator={false}
          onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing}
          ListFooterComponent={hits && hits.length > 0 ? (
            <View style={{ marginTop: sp.m }}>
              <Text style={[type.label, { paddingHorizontal: sp.l, marginBottom: 6 }]}>Matching messages</Text>
              {hits.map(h => (
                <TouchableOpacity key={h.message_id} activeOpacity={0.7} style={s.row} onPress={() => navigation.navigate('Chat', { id: Number(h.conversation_id), name: h.contact_name || h.wa_number })}>
                  <Avatar name={h.contact_name || h.wa_number} size={40} color={c.sub} />
                  <View style={{ flex: 1 }}>
                    <View style={{ flexDirection: 'row' }}><Text style={s.name} numberOfLines={1}>{h.contact_name || h.wa_number}</Text><Text style={s.time}>{when(h.created_at)}</Text></View>
                    <Text style={[type.small, { marginTop: 3 }]} numberOfLines={2}>{h.direction === 'out' ? 'You: ' : ''}{h.snippet}</Text>
                  </View>
                </TouchableOpacity>))}
            </View>) : null}
          ListEmptyComponent={res ? <Empty icon="chatbubbles" title={q || only !== 'all' ? 'No chats match' : 'No conversations yet'} text={q || only !== 'all' ? 'Try another filter.' : 'When customers message your WhatsApp number, their chats appear here.'} /> : null}
          renderItem={({ item: x }) => {
            const open = x.send_mode === 'free_form', un = Number(x.unread_count) > 0, name = x.contact_name || x.wa_number
            return (
              <TouchableOpacity activeOpacity={0.7} style={s.row} onPress={() => navigation.navigate('Chat', { id: Number(x.id), name })}>
                <Avatar name={name} size={48} color={un ? c.primary : c.sub} />
                <View style={{ flex: 1 }}>
                  <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                    <Text style={[s.name, un && { fontWeight: '800' }]} numberOfLines={1}>{name}</Text>
                    <Text style={[s.time, un && { color: c.primary, fontWeight: '700' }]}>{when(x.last_message_at)}</Text>
                  </View>
                  <View style={{ flexDirection: 'row', alignItems: 'center', marginTop: 4, gap: 8 }}>
                    <Text style={[type.small, { flex: 1 }, un && { color: c.text }]} numberOfLines={1}>{x.status === 'resolved' ? 'Resolved · ' : ''}{x.assigned_agent_name ? `${x.assigned_agent_name} · ` : ''}{x.wa_number}</Text>
                    {!open && <Icon name="time-outline" size={14} color={c.faint} />}
                    {un && <View style={s.badge}><Text style={{ color: '#fff', fontWeight: '800', fontSize: 11.5 }}>{x.unread_count}</Text></View>}
                  </View>
                </View>
              </TouchableOpacity>)
          }} />)}
    </View>)
}
const s = StyleSheet.create({
  search: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c.card, borderRadius: radius.l, paddingHorizontal: 14, paddingVertical: 11, borderWidth: 1, borderColor: c.border },
  row: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 13, paddingHorizontal: sp.l, backgroundColor: c.card, borderBottomWidth: StyleSheet.hairlineWidth, borderColor: c.line },
  name: { flex: 1, fontSize: 16, fontWeight: '600', color: c.text }, time: { fontSize: 12, color: c.faint, marginLeft: 8 },
  badge: { backgroundColor: c.primary, minWidth: 22, height: 22, borderRadius: 11, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6 },
})
