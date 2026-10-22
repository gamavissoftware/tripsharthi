import { useState, useCallback, useRef, useEffect } from 'react'
import { View, Text, FlatList, TextInput, TouchableOpacity, ScrollView, StyleSheet, Platform } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { api, biz } from '../api'
import { c, sp, radius, type } from '../theme'
import { Card, Chip, Pill, Icon, Avatar, Empty, ErrorBox, SkeletonList, Fab } from '../ui'
import { inrShort, fmtDay } from '../logic'
import OfflineBanner from '../OfflineBanner'

const FILTERS = [['all', 'All'], ['enquiry', 'New'], ['quoted', 'Quoted'], ['negotiating', 'Negotiating'], ['booked', 'Booked'], ['lost', 'Lost']]

export function StatusPill({ status }) { return <Pill status={status} text={status} /> }

export default function TripsScreen({ navigation }) {
  const [filter, setFilter] = useState('all')
  const [q, setQ] = useState('')
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')
  const alive = useRef(true), seq = useRef(0)
  useEffect(() => () => { alive.current = false }, [])

  const load = useCallback(async (f = filter, query = q.trim()) => {
    const my = ++seq.current                                            // an older, slower answer must never replace a newer one
    try {
      setErr('')
      const r = query.length >= 2 ? await biz.tripsSearchC(query, f) : await api.tripsC(f)
      if (!alive.current || my !== seq.current) return
      setRows(r.data); setMeta({ stale: r.stale, at: r.at })
    } catch (e) { if (alive.current && my === seq.current) setErr(e.message) }
  }, [filter, q])
  useFocusEffect(useCallback(() => { load() }, [load]))
  useEffect(() => { const t = setTimeout(() => load(filter, q.trim()), 300); return () => clearTimeout(t) }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const counts = rows && !q ? rows.reduce((m, t) => { m[t.status] = (m[t.status] || 0) + 1; return m }, {}) : {}
  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <View style={{ padding: sp.l, paddingBottom: 0 }}>
        <View style={s.search}>
          <Icon name="search" size={18} color={c.faint} />
          <TextInput value={q} onChangeText={setQ} placeholder="Search destination or customer" placeholderTextColor={c.faint} style={{ flex: 1, fontSize: 15, color: c.text, paddingVertical: 2 }} autoCapitalize="none" />
          {!!q && <TouchableOpacity onPress={() => setQ('')} hitSlop={10}><Icon name="close-circle" size={18} color={c.faint} /></TouchableOpacity>}
        </View>
      </View>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ flexGrow: 0, flexShrink: 0, height: 62 }} contentContainerStyle={{ padding: sp.l, paddingVertical: sp.m, gap: 8, alignItems: 'center' }}>
        {FILTERS.map(([k, l]) => <Chip key={k} label={l} active={filter === k} count={filter === 'all' && k !== 'all' && counts[k] ? counts[k] : undefined} onPress={() => { setFilter(k); setRows(null); load(k) }} />)}
      </ScrollView>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      {!!err && <View style={{ paddingHorizontal: sp.l }}><ErrorBox text={err} onRetry={() => load()} /></View>}
      {!rows && !err ? <SkeletonList n={4} /> : (
        <FlatList keyboardShouldPersistTaps="handled" keyboardDismissMode={Platform.OS === 'ios' ? 'on-drag' : 'on-drag'} data={rows || []} keyExtractor={t => String(t.id)} contentContainerStyle={{ paddingHorizontal: sp.l, paddingBottom: 110 }} showsVerticalScrollIndicator={false}
          onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing}
          ListEmptyComponent={rows ? <Empty icon="airplane" title={q ? 'No enquiries found' : 'No enquiries here yet'} text={q ? 'Try another name or destination.' : 'Tap + to add a customer enquiry in about 20 seconds.'} action={q ? undefined : 'New enquiry'} onAction={() => navigation.navigate('NewEnquiry')} /> : null}
          renderItem={({ item: t }) => (
            <Card onPress={() => navigation.navigate('Trip', { id: Number(t.id) })} style={{ marginBottom: sp.m }}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                <Avatar name={(t.title || '').split('–').pop().trim() || t.title} size={42} color={c.primary} />
                <View style={{ flex: 1 }}>
                  <Text style={type.h3} numberOfLines={1}>{t.title}</Text>
                  <Text style={[type.small, { marginTop: 2 }]} numberOfLines={1}>{t.destination_text || 'Destination not set'}{Number(t.is_international) ? ' · international' : ''}</Text>
                </View>
                <Pill status={t.status} text={t.status} />
              </View>
              <View style={s.facts}>
                <Fact icon="people" text={`${t.adults}${Number(t.children) ? ` + ${t.children}` : ''}`} />
                {!!t.nights && <Fact icon="moon" text={`${t.nights}N`} />}
                <Fact icon="calendar" text={t.start_date ? fmtDay(t.start_date) : 'Dates TBD'} />
                {!!Number(t.budget_max) && <Fact icon="wallet" text={inrShort(t.budget_max)} />}
              </View>
            </Card>)} />)}
      <Fab icon="add" label="New enquiry" onPress={() => navigation.navigate('NewEnquiry')} />
    </View>)
}

function Fact({ icon, text }) { return <View style={{ flexDirection: 'row', alignItems: 'center', gap: 5 }}><Icon name={icon} size={14} color={c.faint} /><Text style={{ color: c.sub, fontSize: 12.5, fontWeight: '600' }}>{text}</Text></View> }

const s = StyleSheet.create({
  search: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c.card, borderRadius: radius.l, paddingHorizontal: 14, paddingVertical: 11, borderWidth: 1, borderColor: c.border },
  facts: { flexDirection: 'row', flexWrap: 'wrap', gap: 14, marginTop: 12, paddingTop: 12, borderTopWidth: StyleSheet.hairlineWidth, borderColor: c.line },
})
