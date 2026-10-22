import { useState, useCallback, useRef, useEffect } from 'react'
import { View, Text, TouchableOpacity } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { api } from '../api'
import { c, sp, type } from '../theme'
import { Screen, Card, Section, Row, Avatar, Btn, TargetBar, ErrorBox, Skeleton, Icon, Empty } from '../ui'
import { hasTarget, targetStatus, inrShort } from '../logic'
import OfflineBanner from '../OfflineBanner'

const FILL = { ok: c.ok, info: c.primary, bad: c.danger }

export default function TargetsScreen() {
  const [focus, setFocus] = useState(null)         // managers: a person's id to drill into; null = the whole team
  const [data, setData] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])

  const load = useCallback(async () => {
    try { setErr(''); const r = await api.targetsC(focus); if (!alive.current) return; setData(r.data); setMeta({ stale: r.stale, at: r.at }) } catch (e) { if (alive.current) setErr(e.message) }
  }, [focus])
  useFocusEffect(useCallback(() => { load() }, [load]))

  const left = data?.month_progress?.days_left
  const who = data?.team?.find(m => m.id === focus)?.name
  const showTeam = !!data?.team
  const none = data && !data.target && !data.team

  return (
    <Screen onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing} contentStyle={{ padding: sp.l, gap: sp.m }}>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      {!!err && <ErrorBox text={err} onRetry={load} />}
      {!data && !err && <><Skeleton h={30} w="50%" /><Skeleton h={150} r={16} /></>}
      {data && <View><Text style={type.h1}>{data.month}</Text><Text style={[type.small, { marginTop: 3 }]}>{left > 0 ? `${left} day${left === 1 ? '' : 's'} left this month` : 'Last day of the month'}</Text></View>}

      {data?.scope?.manager && focus !== null && <Btn label="Whole team" icon="arrow-back" kind="ghost" small onPress={() => { setData(null); setFocus(null) }} style={{ alignSelf: 'flex-start' }} />}

      {data?.target && (
        <Card>
          <Text style={type.h3}>{focus !== null && who ? who : data.scope?.manager ? 'Target' : 'My target'}</Text>
          <TargetBar label="Revenue (ex-tax)" p={data.target.revenue} daysLeft={left} />
          <TargetBar label="Bookings" p={data.target.bookings} money={false} daysLeft={left} />
          <Text style={[type.small, { marginTop: 14, lineHeight: 17 }]}>{data.target.source === 'carried' && data.target.from ? 'Carried over from an earlier month. ' : ''}The thin line marks where you would be today on an even pace.</Text>
        </Card>)}

      {none && <Empty icon="flag" title="No target set yet" text={data.scope?.manager ? 'Set targets on the web app under Settings → Sales targets.' : 'Your manager can set one for you on the web app under Settings → Sales targets.'} />}

      {showTeam && (<>
        <Card>
          <Text style={type.h3}>Team</Text>
          {data.team_target ? (<>
            <TargetBar label={`Revenue (${data.team_target.people} with a target)`} p={data.team_target.revenue} daysLeft={left} />
            <TargetBar label="Bookings" p={data.team_target.bookings} money={false} daysLeft={left} />
          </>) : <Text style={[type.small, { marginTop: 6, lineHeight: 19 }]}>No targets set yet. Add them on the web app under Settings → Sales targets and the bars appear here.</Text>}
        </Card>
        <Text style={[type.h2, { marginTop: sp.s }]}>People</Text>
        {data.team.map(m => {
          const has = hasTarget(m.target?.revenue)
          const st = has ? targetStatus(m.target.revenue.status) : null
          return (
            <Card key={m.id} onPress={() => { setData(null); setFocus(m.id) }}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                <Avatar name={m.name} size={42} />
                <View style={{ flex: 1 }}><Text style={type.h3}>{m.name}</Text><Text style={[type.small, { marginTop: 2 }]}>{inrShort(m.revenue)} revenue · {m.bookings} booking{m.bookings === 1 ? '' : 's'}</Text></View>
                <Text style={{ color: st ? FILL[st.tone] : c.faint, fontWeight: '800', fontSize: 13 }}>{has ? `${m.target.revenue.pct}%` : 'no target'}</Text>
              </View>
              {has && <TargetBar compact label={m.name} p={m.target.revenue} />}
            </Card>)
        })}
      </>)}
    </Screen>)
}
