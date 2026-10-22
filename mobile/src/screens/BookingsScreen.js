import { useState, useCallback, useRef, useEffect, useMemo } from 'react'
import { View, Text, FlatList, TextInput, ScrollView, StyleSheet } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { biz } from '../api'
import { c, sp, radius, type } from '../theme'
import { Card, Chip, Pill, Icon, Progress, Empty, ErrorBox, SkeletonList, Kpi } from '../ui'
import { inrFull, inrShort, fmtDay, indiaToday, dueLabel, pctOf } from '../logic'
import OfflineBanner from '../OfflineBanner'

const FILTERS = [['all', 'All'], ['due', 'Money due'], ['soon', 'Leaving soon'], ['done', 'Fully paid'], ['cancelled', 'Cancelled']]

export default function BookingsScreen({ navigation }) {
  const [all, setAll] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [f, setF] = useState('all')
  const [refreshing, setRefreshing] = useState(false)
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])
  const today = indiaToday()

  const load = useCallback(async () => {
    try { setErr(''); const r = await biz.bookingsC(); if (!alive.current) return; setAll(r.data); setMeta({ stale: r.stale, at: r.at }) } catch (e) { if (alive.current) setErr(e.message) }
  }, [])
  useFocusEffect(useCallback(() => { load() }, [load]))

  const live = (all || []).filter(b => b.status !== 'cancelled')
  const owed = (b) => Math.max(0, Number(b.total_amount) - Number(b.paid_amount))
  const totals = useMemo(() => ({
    toCollect: live.reduce((n, b) => n + owed(b), 0),
    count: live.length,
    soon: live.filter(b => b.travel_start && b.travel_start >= today && b.travel_start <= new Date(Date.now() + 330 * 60000 + 14 * 86400000).toISOString().slice(0, 10)).length,
  }), [all]) // eslint-disable-line react-hooks/exhaustive-deps

  const rows = (all || []).filter(b => {
    if (f === 'cancelled') return b.status === 'cancelled'
    if (f !== 'cancelled' && b.status === 'cancelled') return f === 'all' ? false : false
    if (f === 'due' && !(owed(b) > 0)) return false
    if (f === 'done' && owed(b) > 0) return false
    if (f === 'soon' && !(b.travel_start && b.travel_start >= today && b.travel_start <= new Date(Date.now() + 330 * 60000 + 14 * 86400000).toISOString().slice(0, 10))) return false
    const t = q.trim().toLowerCase()
    return !t || String(b.title).toLowerCase().includes(t) || String(b.booking_ref).toLowerCase().includes(t)
  }).sort((a, b) => {
    // trips still to come first (soonest on top), then past trips (most recent on top)
    const ua = !a.travel_start || a.travel_start >= today, ub = !b.travel_start || b.travel_start >= today
    if (ua !== ub) return ua ? -1 : 1
    return ua ? String(a.travel_start || '9999').localeCompare(String(b.travel_start || '9999')) : String(b.travel_start).localeCompare(String(a.travel_start))
  })

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      <FlatList
        data={all ? rows : []} keyExtractor={b => String(b.id)} contentContainerStyle={{ paddingBottom: 40 }} showsVerticalScrollIndicator={false}
        onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing}
        ListHeaderComponent={<View style={{ padding: sp.l, paddingBottom: sp.s }}>
          <View style={{ flexDirection: 'row', gap: 12 }}>
            <Kpi icon="wallet" tone={c.danger} label="To collect" value={inrShort(totals.toCollect)} style={{ width: undefined, flex: 1 }} />
            <Kpi icon="airplane" tone={c.teal} label="Leaving in 14 days" value={String(totals.soon)} sub={`${totals.count} live bookings`} style={{ width: undefined, flex: 1 }} />
          </View>
          <View style={s.search}><Icon name="search" size={18} color={c.faint} /><TextInput value={q} onChangeText={setQ} placeholder="Search booking or customer" placeholderTextColor={c.faint} style={{ flex: 1, fontSize: 15, color: c.text, paddingVertical: 2 }} autoCapitalize="none" /></View>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingTop: sp.m }}>{FILTERS.map(([k, l]) => <Chip key={k} label={l} active={f === k} onPress={() => setF(k)} />)}</ScrollView>
          {!!err && <View style={{ marginTop: sp.m }}><ErrorBox text={err} onRetry={load} /></View>}
        </View>}
        ListEmptyComponent={!all && !err ? <SkeletonList n={4} /> : all ? <Empty icon="briefcase" title="No bookings here" text={q ? 'Nothing matches your search.' : f === 'all' ? 'Confirmed trips appear here once a quote is accepted and booked.' : 'Try another filter.'} /> : null}
        renderItem={({ item: b }) => {
          const due = owed(b), cancelled = b.status === 'cancelled'
          return (
            <Card onPress={() => navigation.navigate('Booking', { id: Number(b.id) })} style={{ marginHorizontal: sp.l, marginBottom: sp.m }}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                <Text style={{ fontSize: 12, fontWeight: '800', color: c.primary, letterSpacing: 0.4 }}>{b.booking_ref}</Text>
                <View style={{ flex: 1 }} /><Pill status={b.status} text={b.status} />
              </View>
              <Text style={[type.h3, { marginTop: 6 }]} numberOfLines={1}>{b.title}</Text>
              <Text style={[type.small, { marginTop: 3 }]}>{b.travel_start ? `${fmtDay(b.travel_start)}${b.travel_end ? ' → ' + fmtDay(b.travel_end) : ''} · ${dueLabel(b.travel_start, today)}` : 'Dates to be confirmed'}</Text>
              {!cancelled && <View style={{ marginTop: 12 }}>
                <Progress pct={pctOf(b.paid_amount, b.total_amount)} color={due > 0 ? c.orange : c.ok} height={7} />
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 7 }}>
                  <Text style={type.small}>Paid <Text style={{ fontWeight: '800', color: c.text }}>{inrFull(b.paid_amount)}</Text> of {inrFull(b.total_amount)}</Text>
                  <Text style={{ fontSize: 12.5, fontWeight: '800', color: due > 0 ? c.danger : c.ok }}>{due > 0 ? `${inrFull(due)} due` : 'Fully paid'}</Text>
                </View>
              </View>}
            </Card>)
        }} />
    </View>)
}
const s = StyleSheet.create({ search: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#fff', borderRadius: radius.l, paddingHorizontal: 14, paddingVertical: 11, borderWidth: 1, borderColor: c.border, marginTop: sp.m } })
