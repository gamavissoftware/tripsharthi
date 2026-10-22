import { useState, useCallback, useRef, useEffect } from 'react'
import { View, Text, FlatList, TouchableOpacity, StyleSheet, Platform } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { pushApi } from '../api'
import { c, sp, type } from '../theme'
import { Icon, Empty, ErrorBox, SkeletonList, Btn } from '../ui'
import { linkToTarget, openTarget } from '../nav'
import { setBadge } from '../push'

const ago = (s) => {
  const d = (Date.now() - new Date(String(s).replace(' ', 'T') + 'Z').getTime()) / 1000
  if (d < 60) return 'just now'
  if (d < 3600) return `${Math.floor(d / 60)}m ago`
  if (d < 86400) return `${Math.floor(d / 3600)}h ago`
  return `${Math.floor(d / 86400)}d ago`
}
// what kind of event it is -> icon and colour
const KIND = [
  [/lead|enquiry/i, 'person-add', c.primary], [/reply|message|whatsapp/i, 'chatbubble-ellipses', c.ok], [/quote/i, 'eye', c.purple], [/payment|paid|overdue|instal/i, 'cash', c.orange],
  [/booking/i, 'checkmark-done-circle', c.ok], [/task|mention/i, 'alarm', c.teal], [/ad |campaign/i, 'megaphone', c.danger], [/target/i, 'flag', c.purple],
]
const look = (n) => { const k = KIND.find(([re]) => re.test(`${n.type || ''} ${n.body || ''}`)); return k ? [k[1], k[2]] : ['notifications', c.primary] }

export default function NotificationsScreen({ onUnread }) {
  const [rows, setRows] = useState(null)
  const [unread, setUnread] = useState(0)
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])

  const load = useCallback(async () => {
    try { setErr(''); const r = await pushApi.feed(); if (!alive.current) return; setRows(r.data); setUnread(r.unread); onUnread?.(r.unread); setBadge(r.unread) } catch (e) { if (alive.current) setErr(e.message) }
  }, [onUnread])
  useFocusEffect(useCallback(() => { load() }, [load]))

  async function open(n) {
    if (!n.read_at) { setRows(rs => rs.map(x => (x.id === n.id ? { ...x, read_at: 'now' } : x))); pushApi.markRead(n.id).then(load).catch(() => {}) }
    const t = linkToTarget(n.link)
    if (t) openTarget(t)
  }
  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      {unread > 0 && <View style={{ padding: sp.l, paddingBottom: 0, alignItems: 'flex-end' }}><Btn label={`Mark all ${unread} as read`} icon="checkmark-done" kind="soft" small onPress={() => pushApi.markAllRead().then(load)} /></View>}
      {!!err && <View style={{ padding: sp.l }}><ErrorBox text={err} onRetry={load} /></View>}
      {!rows && !err ? <SkeletonList n={5} /> : (
        <FlatList keyboardShouldPersistTaps="handled" keyboardDismissMode={Platform.OS === 'ios' ? 'on-drag' : 'on-drag'} data={rows || []} keyExtractor={n => String(n.id)} contentContainerStyle={{ padding: sp.l, gap: sp.s, paddingBottom: 40 }} showsVerticalScrollIndicator={false}
          onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing}
          ListEmptyComponent={rows ? <Empty icon="notifications" title="Nothing yet" text="New leads, customer replies, quote views and payments will show up here." /> : null}
          renderItem={({ item: n }) => {
            const [icon, color] = look(n)
            return (
              <TouchableOpacity activeOpacity={0.75} onPress={() => open(n)} style={[s.card, !n.read_at && s.unread]}>
                <View style={[s.ico, { backgroundColor: color + '1c' }]}><Icon name={icon} size={19} color={color} /></View>
                <View style={{ flex: 1 }}>
                  <Text style={{ color: c.text, fontWeight: n.read_at ? '500' : '700', fontSize: 14.5, lineHeight: 20 }}>{n.body}</Text>
                  <Text style={[type.small, { marginTop: 4 }]}>{ago(n.created_at)}</Text>
                </View>
                {!n.read_at && <View style={s.dot} />}
              </TouchableOpacity>)
          }} />)}
    </View>)
}
const s = StyleSheet.create({
  card: { backgroundColor: '#fff', borderRadius: 16, padding: 14, borderWidth: StyleSheet.hairlineWidth, borderColor: c.border, flexDirection: 'row', gap: 12, alignItems: 'flex-start' },
  unread: { backgroundColor: '#f5f9ff', borderColor: '#cfe0f5' }, ico: { width: 38, height: 38, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: c.primary, marginTop: 6 },
})
