import { useState, useCallback, useEffect, useRef } from 'react'
import { View, Text, FlatList, TouchableOpacity, RefreshControl, StyleSheet } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { chatApi } from '../api'
import { c } from '../theme'
import OfflineBanner from '../OfflineBanner'

const when = (s) => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T') + 'Z'); const today = new Date(); return d.toDateString() === today.toDateString() ? d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) }

export default function ChatsScreen({ navigation, onUnread }) {
  const [res, setRes] = useState(null)
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)
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
  // Refresh on focus and every 15 s while this tab is showing.
  useFocusEffect(useCallback(() => { load(); const t = setInterval(load, 15000); return () => clearInterval(t) }, [load]))

  const rows = res?.data || []
  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <OfflineBanner stale={res?.stale} at={res?.at} />
      {!!err && <Text style={{ color: c.danger, padding: 16 }}>{err}</Text>}
      <FlatList
        data={rows} keyExtractor={x => String(x.id)}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} />}
        ListEmptyComponent={res ? <Text style={{ textAlign: 'center', color: c.sub, marginTop: 40 }}>No conversations yet.</Text> : null}
        contentContainerStyle={{ padding: 12, gap: 8 }}
        renderItem={({ item: x }) => {
          const open = x.send_mode === 'free_form'
          return (
            <TouchableOpacity style={s.card} onPress={() => navigation.navigate('Chat', { id: x.id, name: x.contact_name || x.wa_number })}>
              <View style={s.row}>
                <Text style={[s.name, Number(x.unread_count) > 0 && { fontWeight: '800' }]} numberOfLines={1}>{x.contact_name || x.wa_number}</Text>
                <Text style={s.time}>{when(x.last_message_at)}</Text>
              </View>
              <View style={s.row}>
                <Text style={s.sub} numberOfLines={1}>{x.wa_number}{x.status === 'resolved' ? ' · resolved' : ''}{x.assigned_agent_name ? ` · ${x.assigned_agent_name}` : ''}</Text>
                <View style={{ flexDirection: 'row', gap: 6, alignItems: 'center' }}>
                  <Text style={{ fontSize: 11, color: open ? c.ok : c.sub }}>{open ? '● reply open' : '○ templates only'}</Text>
                  {Number(x.unread_count) > 0 && <Text style={s.badge}>{x.unread_count}</Text>}
                </View>
              </View>
            </TouchableOpacity>)
        }}
      />
    </View>
  )
}
const s = StyleSheet.create({
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border, gap: 4 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8 },
  name: { fontSize: 16, fontWeight: '600', color: c.text, flex: 1 }, time: { color: c.sub, fontSize: 12 }, sub: { color: c.sub, flex: 1 },
  badge: { backgroundColor: c.primary, color: '#fff', fontWeight: '800', fontSize: 12, paddingHorizontal: 7, paddingVertical: 2, borderRadius: 999, overflow: 'hidden' },
})
