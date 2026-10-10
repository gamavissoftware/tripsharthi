import { useState, useCallback } from 'react'
import { View, Text, ScrollView, TouchableOpacity, RefreshControl, StyleSheet } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { api, inr } from '../api'
import { c } from '../theme'
import OfflineBanner from '../OfflineBanner'
import { clampPct, targetStatus, toGoText, hasTarget } from '../logic'

const FILL = { ok: c.ok, info: c.primary, bad: '#d6542d' }

/** One progress bar towards a target, with a tick for where you would be today on an even pace through the month. */
function TargetBar({ label, p, money = true, daysLeft, compact = false }) {
  if (!hasTarget(p)) return null
  const fmt = money ? inr : String
  const st = targetStatus(p.status)
  const color = FILL[st?.tone] || c.primary
  return (
    <View style={{ marginTop: compact ? 6 : 12 }}>
      {!compact && (
        <View style={s.rowBetween}>
          <Text style={s.barLabel}>{label}</Text>
          <Text style={s.barNums}><Text style={{ fontWeight: '800', color: c.text }}>{fmt(p.actual)}</Text> of {fmt(p.target)} · {p.pct}%</Text>
        </View>)}
      <View style={[s.track, compact && { height: 8 }]} accessibilityRole="progressbar" accessibilityLabel={`${label || 'Progress'}: ${p.pct}% of target`}>
        <View style={{ width: `${clampPct(p.pct)}%`, height: '100%', backgroundColor: color, borderRadius: 6 }} />
        {p.status !== 'achieved' && <View style={[s.tick, { left: `${clampPct(p.expected_pct)}%` }]} />}
      </View>
      {!compact && (
        <View style={[s.rowBetween, { marginTop: 6 }]}>
          <Text style={{ color, fontWeight: '800', fontSize: 12.5 }}>{st?.label}</Text>
          <Text style={{ color: c.sub, fontSize: 12.5 }}>{toGoText(p, fmt, daysLeft)}</Text>
        </View>)}
    </View>
  )
}

export default function TargetsScreen() {
  const [focus, setFocus] = useState(null)         // managers: a person's id to drill into; null = the whole team
  const [data, setData] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(async () => {
    try { setErr(''); const r = await api.targetsC(focus); setData(r.data); setMeta({ stale: r.stale, at: r.at }) } catch (e) { setErr(e.message) }
  }, [focus])
  useFocusEffect(useCallback(() => { load() }, [load]))

  const left = data?.month_progress?.days_left
  const who = data?.team?.find(m => m.id === focus)?.name
  const showTeam = !!data?.team
  const none = data && !data.target && !data.team

  return (
    <ScrollView style={{ flex: 1, backgroundColor: c.bg }} contentContainerStyle={{ padding: 12, paddingBottom: 32 }}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} />}>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      {!!err && <Text style={{ color: c.danger, padding: 8 }}>{err}</Text>}
      {!data && !err && <Text style={{ textAlign: 'center', color: c.sub, marginTop: 40 }}>Loading…</Text>}

      {data && (
        <View style={{ marginBottom: 10 }}>
          <Text style={s.title}>{data.month}</Text>
          <Text style={{ color: c.sub }}>{left > 0 ? `${left} day${left === 1 ? '' : 's'} left this month` : 'Last day of the month'}</Text>
        </View>)}

      {data?.scope?.manager && focus !== null && (
        <TouchableOpacity onPress={() => { setData(null); setFocus(null) }} style={s.back}><Text style={{ color: c.primary, fontWeight: '700' }}>← Whole team</Text></TouchableOpacity>)}

      {data?.target && (
        <View style={s.card}>
          <Text style={s.cardTitle}>{focus !== null && who ? who : data.scope?.manager ? 'Target' : 'My target'}</Text>
          <TargetBar label="Revenue (ex-tax)" p={data.target.revenue} daysLeft={left} />
          <TargetBar label="Bookings" p={data.target.bookings} money={false} daysLeft={left} />
          <Text style={s.note}>{data.target.source === 'carried' && data.target.from ? 'Carried over from an earlier month. ' : ''}The thin line marks where you would be today on an even pace.</Text>
        </View>)}

      {none && (
        <View style={s.card}>
          <Text style={s.cardTitle}>No target set yet</Text>
          <Text style={{ color: c.sub, marginTop: 6, lineHeight: 20 }}>{data.scope?.manager ? 'Set targets on the web app under Settings → Sales targets.' : 'Your manager can set one for you under Settings → Sales targets on the web app.'}</Text>
        </View>)}

      {showTeam && (<>
        <View style={s.card}>
          <Text style={s.cardTitle}>Team</Text>
          {data.team_target ? (<>
            <TargetBar label={`Revenue (${data.team_target.people} with a target)`} p={data.team_target.revenue} daysLeft={left} />
            <TargetBar label="Bookings" p={data.team_target.bookings} money={false} daysLeft={left} />
          </>) : <Text style={{ color: c.sub, marginTop: 6, lineHeight: 20 }}>No targets set yet. Add them on the web app under Settings → Sales targets and the bars appear here.</Text>}
        </View>
        <View style={s.card}>
          <Text style={s.cardTitle}>People</Text>
          {data.team.map(m => {
            const ok = hasTarget(m.target?.revenue)
            const st = ok ? targetStatus(m.target.revenue.status) : null
            return (
              <TouchableOpacity key={m.id} style={s.person} onPress={() => { setData(null); setFocus(m.id) }} accessibilityRole="button" accessibilityLabel={`Open ${m.name}`}>
                <View style={s.rowBetween}>
                  <Text style={{ fontWeight: '700', color: c.text, flexShrink: 1 }}>{m.name} <Text style={{ color: c.sub, fontWeight: '400' }}>{m.role}</Text></Text>
                  <Text style={{ color: st ? FILL[st.tone] : c.sub, fontWeight: '700', fontSize: 12.5 }}>{ok ? `${m.target.revenue.pct}%` : 'no target'}</Text>
                </View>
                <Text style={{ color: c.sub, fontSize: 12.5, marginTop: 2 }}>{inr(m.revenue)} revenue · {m.bookings} booking{m.bookings === 1 ? '' : 's'}</Text>
                <TargetBar compact label={m.name} p={m.target?.revenue} />
              </TouchableOpacity>)
          })}
        </View>
      </>)}
    </ScrollView>
  )
}

const s = StyleSheet.create({
  title: { fontSize: 20, fontWeight: '800', color: c.text },
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border, marginBottom: 12 },
  cardTitle: { fontWeight: '800', color: c.text, fontSize: 15 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline', gap: 8 },
  barLabel: { color: c.text, fontWeight: '600' },
  barNums: { color: c.sub, fontSize: 13 },
  track: { height: 12, backgroundColor: '#eceef1', borderRadius: 6, marginTop: 6 },
  tick: { position: 'absolute', top: -3, bottom: -3, width: 2, backgroundColor: '#26292c', opacity: 0.55, borderRadius: 1 },
  note: { color: c.sub, fontSize: 12, marginTop: 12, lineHeight: 17 },
  back: { paddingVertical: 8, marginBottom: 4 },
  person: { paddingVertical: 10, borderTopWidth: 1, borderTopColor: c.border, marginTop: 8 },
})
