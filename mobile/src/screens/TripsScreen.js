import { useState, useCallback } from 'react'
import { View, Text, FlatList, TouchableOpacity, RefreshControl, ScrollView, StyleSheet } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { api, inr } from '../api'
import { c, STATUS } from '../theme'
import OfflineBanner from '../OfflineBanner'

const FILTERS = ['all', 'enquiry', 'quoted', 'negotiating', 'booked']

export function StatusPill({ status }) {
  const [bg, fg] = STATUS[status] || ['#f1f5f9', '#475569']
  return <Text style={{ backgroundColor: bg, color: fg, paddingHorizontal: 9, paddingVertical: 3, borderRadius: 999, fontSize: 12, fontWeight: '700', overflow: 'hidden' }}>{status}</Text>
}

export default function TripsScreen({ navigation }) {
  const [filter, setFilter] = useState('all')
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(async (f = filter) => {
    try { setErr(''); const r = await api.tripsC(f); setRows(r.data); setMeta({ stale: r.stale, at: r.at }) } catch (e) { setErr(e.message) }
  }, [filter])
  useFocusEffect(useCallback(() => { load() }, [load]))

  return (
    <View style={s.wrap}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ flexGrow: 0 }} contentContainerStyle={{ padding: 12, gap: 8 }}>
        {FILTERS.map(f => (
          <TouchableOpacity key={f} onPress={() => { setFilter(f); load(f) }} style={[s.chip, filter === f && { backgroundColor: c.primary }]}>
            <Text style={{ color: filter === f ? '#fff' : c.text, fontWeight: '600' }}>{f === 'all' ? 'All' : f}</Text>
          </TouchableOpacity>
        ))}
      </ScrollView>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      {!!err && <Text style={{ color: c.danger, padding: 16 }}>{err}</Text>}
      <FlatList
        data={rows || []}
        keyExtractor={t => String(t.id)}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} />}
        ListEmptyComponent={rows ? <Text style={{ textAlign: 'center', color: c.sub, marginTop: 40 }}>No trips here yet.</Text> : null}
        contentContainerStyle={{ padding: 12, gap: 10 }}
        renderItem={({ item: t }) => (
          <TouchableOpacity style={s.card} onPress={() => navigation.navigate('Trip', { id: t.id })}>
            <View style={s.row}><Text style={s.title} numberOfLines={1}>{t.title}</Text><StatusPill status={t.status} /></View>
            <Text style={s.meta}>{t.destination_text || '—'}{Number(t.is_international) ? ' 🌍' : ''} · {t.adults}A{Number(t.children) ? ` ${t.children}C` : ''}{t.nights ? ` · ${t.nights}N` : ''}</Text>
            <Text style={s.meta}>{t.start_date || 'Dates TBD'}{t.budget_max ? ` · budget ${inr(t.budget_max)}` : ''}</Text>
          </TouchableOpacity>
        )}
      />
      <TouchableOpacity style={s.fab} onPress={() => navigation.navigate('NewEnquiry')} accessibilityLabel="New enquiry"><Text style={{ color: '#fff', fontSize: 28, marginTop: -2 }}>+</Text></TouchableOpacity>
    </View>
  )
}
const s = StyleSheet.create({
  fab: { position: 'absolute', right: 18, bottom: 22, width: 56, height: 56, borderRadius: 28, backgroundColor: c.primary, alignItems: 'center', justifyContent: 'center', elevation: 4, shadowColor: '#000', shadowOpacity: 0.25, shadowRadius: 6, shadowOffset: { width: 0, height: 3 } },
  wrap: { flex: 1, backgroundColor: c.bg },
  chip: { backgroundColor: c.card, borderWidth: 1, borderColor: c.border, paddingHorizontal: 14, paddingVertical: 8, borderRadius: 999 },
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8 },
  title: { fontSize: 16, fontWeight: '700', color: c.text, flex: 1 },
  meta: { color: c.sub, marginTop: 4 },
})
