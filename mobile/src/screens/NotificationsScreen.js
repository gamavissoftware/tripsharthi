import { useState, useCallback } from 'react'
import { View, Text, FlatList, TouchableOpacity, RefreshControl, StyleSheet } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { pushApi } from '../api'
import { c } from '../theme'
import { linkToTarget, openTarget } from '../nav'
import { setBadge } from '../push'

const ago = (s) => {
  const d = (Date.now() - new Date(String(s).replace(' ', 'T') + 'Z').getTime()) / 1000
  if (d < 60) return 'just now'
  if (d < 3600) return `${Math.floor(d / 60)}m ago`
  if (d < 86400) return `${Math.floor(d / 3600)}h ago`
  return `${Math.floor(d / 86400)}d ago`
}

export default function NotificationsScreen({ onUnread }) {
  const [rows, setRows] = useState(null)
  const [unread, setUnread] = useState(0)
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(async () => {
    try { setErr(''); const r = await pushApi.feed(); setRows(r.data); setUnread(r.unread); onUnread?.(r.unread); setBadge(r.unread) } catch (e) { setErr(e.message) }
  }, [onUnread])
  useFocusEffect(useCallback(() => { load() }, [load]))

  async function open(n) {
    if (!n.read_at) { setRows(rs => rs.map(x => x.id === n.id ? { ...x, read_at: 'now' } : x)); pushApi.markRead(n.id).then(load).catch(() => {}) }
    const t = linkToTarget(n.link)
    if (t) openTarget(t)
  }

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      {unread > 0 && (
        <TouchableOpacity style={s.bar} onPress={() => pushApi.markAllRead().then(load)}>
          <Text style={{ color: c.primary, fontWeight: '700' }}>Mark all {unread} as read</Text>
        </TouchableOpacity>)}
      {!!err && <Text style={{ color: c.danger, padding: 16 }}>{err}</Text>}
      <FlatList
        data={rows || []} keyExtractor={n => String(n.id)} contentContainerStyle={{ padding: 12, gap: 8 }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} />}
        ListEmptyComponent={rows ? <Text style={{ textAlign: 'center', color: c.sub, marginTop: 40 }}>Nothing yet. New leads, quote views and payments will show up here.</Text> : null}
        renderItem={({ item: n }) => (
          <TouchableOpacity onPress={() => open(n)} style={[s.card, !n.read_at && s.unread]}>
            {!n.read_at && <View style={s.dot} />}
            <View style={{ flex: 1 }}>
              <Text style={{ color: c.text, fontWeight: n.read_at ? '500' : '700' }}>{n.body}</Text>
              <Text style={{ color: c.sub, fontSize: 12, marginTop: 3 }}>{ago(n.created_at)}</Text>
            </View>
          </TouchableOpacity>)}
      />
    </View>
  )
}
const s = StyleSheet.create({
  bar: { padding: 12, alignItems: 'flex-end', backgroundColor: c.card, borderBottomWidth: 1, borderColor: c.border },
  card: { backgroundColor: c.card, borderRadius: 12, padding: 14, borderWidth: 1, borderColor: c.border, flexDirection: 'row', gap: 10, alignItems: 'flex-start' },
  unread: { borderColor: c.primary, backgroundColor: '#f5f5ff' },
  dot: { width: 9, height: 9, borderRadius: 5, backgroundColor: c.primary, marginTop: 5 },
})
