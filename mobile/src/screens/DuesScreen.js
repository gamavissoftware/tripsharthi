import { useState, useCallback } from 'react'
import { View, Text, FlatList, TouchableOpacity, RefreshControl, StyleSheet } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { api, inr } from '../api'
import { c } from '../theme'
import OfflineBanner from '../OfflineBanner'

export default function DuesScreen({ navigation }) {
  const [dash, setDash] = useState(null)
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [refreshing, setRefreshing] = useState(false)
  const load = useCallback(async () => {
    const r = await api.duesC()
    setDash(r.data.dash); setMeta({ stale: r.stale, at: r.at })
    setRows(r.data.bookings.filter(x => Number(x.total_amount) > Number(x.paid_amount) && x.status !== 'cancelled'))
  }, [])
  useFocusEffect(useCallback(() => { load().catch(() => {}) }, [load]))

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      {dash && (
        <View style={{ flexDirection: 'row', gap: 10, padding: 12 }}>
          <View style={[s.kpi, { borderColor: c.danger }]}><Text style={s.kl}>Overdue</Text><Text style={[s.kv, { color: c.danger }]}>{inr(dash.receivables?.overdue)}</Text></View>
          <View style={s.kpi}><Text style={s.kl}>Upcoming</Text><Text style={s.kv}>{inr(dash.receivables?.upcoming)}</Text></View>
          <View style={s.kpi}><Text style={s.kl}>Departing 30d</Text><Text style={s.kv}>{dash.departures_30d.length}</Text></View>
        </View>)}
      <FlatList
        data={rows || []} keyExtractor={b => String(b.id)} contentContainerStyle={{ padding: 12, gap: 10 }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load().catch(() => {}); setRefreshing(false) }} />}
        ListEmptyComponent={rows ? <Text style={{ textAlign: 'center', color: c.sub, marginTop: 40 }}>Nothing pending — all collected 🎉</Text> : null}
        renderItem={({ item: b }) => (
          <TouchableOpacity style={s.card} onPress={() => navigation.navigate('Booking', { id: b.id })}>
            <Text style={{ fontWeight: '800', color: c.text }}>{b.booking_ref} · {b.title}</Text>
            <Text style={{ color: c.sub, marginTop: 4 }}>Paid {inr(b.paid_amount)} of {inr(b.total_amount)} · due <Text style={{ color: c.danger, fontWeight: '700' }}>{inr(Number(b.total_amount) - Number(b.paid_amount))}</Text></Text>
          </TouchableOpacity>)}
      />
    </View>
  )
}
const s = StyleSheet.create({
  kpi: { flex: 1, backgroundColor: c.card, borderRadius: 12, padding: 12, borderWidth: 1, borderColor: c.border },
  kl: { color: c.sub, fontSize: 11, textTransform: 'uppercase' }, kv: { fontSize: 18, fontWeight: '800', marginTop: 2, color: c.text },
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border },
})
