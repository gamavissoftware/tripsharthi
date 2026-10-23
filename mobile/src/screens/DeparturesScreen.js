import { useState, useCallback, useRef, useEffect } from 'react'
import { View, FlatList, Platform } from 'react-native'
import { Text } from '../T'
import { useFocusEffect } from '@react-navigation/native'
import { biz } from '../api'
import { c, sp, type } from '../theme'
import { Card, Pill, Progress, Empty, ErrorBox, SkeletonList } from '../ui'
import { inrFull, fmtDay, indiaToday, dueLabel, pctOf } from '../logic'
import OfflineBanner from '../OfflineBanner'

/** Group departures: dates, seats sold, and whether the departure is at risk of not reaching its minimum. */
export default function DeparturesScreen() {
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])
  const today = indiaToday()
  const load = useCallback(async () => { try { setErr(''); const r = await biz.departuresC(); if (!alive.current) return; setRows(r.data); setMeta({ stale: r.stale, at: r.at }) } catch (e) { if (alive.current) setErr(e.message) } }, [])
  useFocusEffect(useCallback(() => { load() }, [load]))
  const list = (rows || []).slice().sort((a, b) => String(a.start_date).localeCompare(String(b.start_date)))
  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      <FlatList keyboardShouldPersistTaps="handled" keyboardDismissMode={Platform.OS === 'ios' ? 'on-drag' : 'on-drag'} data={list} keyExtractor={x => String(x.id)} contentContainerStyle={{ padding: sp.l, paddingBottom: 40 }} showsVerticalScrollIndicator={false}
        onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing}
        ListHeaderComponent={!!err && <View style={{ marginBottom: sp.m }}><ErrorBox text={err} onRetry={load} /></View>}
        ListEmptyComponent={!rows && !err ? <SkeletonList n={3} /> : rows ? <Empty icon="airplane" title="No group departures" text="Fixed-date group tours you create on the web app appear here with live seat counts." /> : null}
        renderItem={({ item: x }) => {
          const sold = Number(x.confirmed) + Number(x.held)
          return (
            <Card style={{ marginBottom: sp.m }}>
              <View style={{ flexDirection: 'row', alignItems: 'flex-start', gap: 8 }}>
                <View style={{ flex: 1 }}><Text style={type.h3} numberOfLines={2}>{x.title}</Text><Text style={[type.small, { marginTop: 3 }]}>{fmtDay(x.start_date)} → {fmtDay(x.end_date)} · {dueLabel(x.start_date, today)}</Text></View>
                {x.at_risk ? <Pill tone="danger" icon="alert-circle" text="At risk" /> : <Pill status={x.state === 'open' ? 'confirmed' : x.state} text={x.state} />}
              </View>
              <View style={{ marginTop: 14 }}>
                <Progress pct={pctOf(sold, x.total_seats)} color={x.at_risk ? c.orange : c.teal} height={9} tick={pctOf(x.min_pax, x.total_seats)} />
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 8 }}>
                  <Text style={type.small}><Text style={{ fontWeight: '800', color: c.text }}>{x.confirmed}</Text> confirmed{Number(x.held) > 0 ? ` · ${x.held} held` : ''} · {x.available} left of {x.total_seats}</Text>
                  <Text style={type.small}>min {x.min_pax}</Text>
                </View>
              </View>
              <Text style={{ marginTop: 10, color: c.text, fontWeight: '800', fontSize: 15 }}>{inrFull(x.price_pax)} <Text style={type.small}>per person + {Number(x.gst_rate)}% GST</Text></Text>
            </Card>)
        }} />
    </View>)
}
