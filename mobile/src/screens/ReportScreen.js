import { useState, useCallback, useRef, useEffect } from 'react'
import { View } from 'react-native'
import { Text } from '../T'
import { useFocusEffect } from '@react-navigation/native'
import { biz } from '../api'
import { c, sp, type } from '../theme'
import { Screen, Card, Section, Kpi, Chip, Row, Progress, Bars, ErrorBox, SkeletonList } from '../ui'
import { inrShort, inrFull, pctOf } from '../logic'
import OfflineBanner from '../OfflineBanner'

const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const iso = (d) => d.toISOString().slice(0, 10)
function ranges() {
  const n = new Date(Date.now() + 330 * 60000), y = n.getUTCFullYear(), m = n.getUTCMonth()
  const first = (yy, mm) => new Date(Date.UTC(yy, mm, 1)), last = (yy, mm) => new Date(Date.UTC(yy, mm + 1, 0))
  return [
    ['this', 'This month', iso(first(y, m)), iso(last(y, m))],
    ['last', 'Last month', iso(first(y, m - 1)), iso(last(y, m - 1))],
    ['q', 'Last 3 months', iso(first(y, m - 2)), iso(last(y, m))],
    ['fy', 'This year', iso(first(y, 0)), iso(last(y, 11))],
  ]
}

/** Owner / admin only: the business report (the web app's Business report page) in a phone-sized form. */
export default function ReportScreen() {
  const R = ranges()
  const [pick, setPick] = useState('this')
  const [d, setD] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])
  const rg = R.find(r => r[0] === pick)
  const load = useCallback(async () => { try { setErr(''); const r = await biz.reportC(rg[2], rg[3]); if (!alive.current) return; setD(r.data); setMeta({ stale: r.stale, at: r.at }) } catch (e) { if (alive.current) setErr(e.message) } }, [pick]) // eslint-disable-line react-hooks/exhaustive-deps
  useFocusEffect(useCallback(() => { load() }, [load]))

  const maxDest = Math.max(1, ...(d?.destinations || []).map(x => Number(x.revenue)))
  const maxSrc = Math.max(1, ...(d?.sources || []).map(x => Number(x.enquiries)))
  return (
    <Screen onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, padding: sp.l, paddingBottom: 0 }}>{R.map(r => <Chip key={r[0]} label={r[1]} active={pick === r[0]} onPress={() => { setD(null); setPick(r[0]) }} />)}</View>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      {!!err && <View style={{ padding: sp.l }}><ErrorBox text={err} onRetry={load} /></View>}
      {!d && !err && <SkeletonList n={3} />}
      {d && (<>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', paddingHorizontal: sp.l, paddingTop: sp.l, rowGap: sp.m }}>
          <Kpi icon="trending-up" tone={c.teal} label="Revenue (ex-tax)" value={inrShort(d.money.revenue)} sub={`${d.money.bookings} bookings`} />
          <Kpi icon="pie-chart" tone={c.navy} label="Margin" value={inrShort(d.money.margin)} sub={`${d.money.margin_pct}% of revenue`} />
          <Kpi icon="receipt" tone={c.purple} label="Billed (with GST)" value={inrShort(d.money.billed)} sub={`GST ${inrShort(d.money.gst)}`} />
          <Kpi icon="pricetag" tone={c.orange} label="Average booking" value={inrShort(d.money.avg_booking)} />
        </View>
        {d.money.bookings_without_cost > 0 && <Text style={[type.small, { padding: sp.l, paddingBottom: 0 }]}>⚠ {d.money.bookings_without_cost} booking(s) have no supplier cost, so margin is overstated.</Text>}

        <Section title="Enquiry funnel">
          <Card style={{ marginHorizontal: sp.l, gap: 14 }}>
            <Step label="Enquiries" n={d.funnel.enquiries} pct={100} color={c.primary} />
            <Step label="Quoted" n={d.funnel.quoted} pct={pctOf(d.funnel.quoted, d.funnel.enquiries)} color={c.orange} note={`${d.funnel.quote_rate}% of enquiries`} />
            <Step label="Booked" n={d.funnel.booked} pct={pctOf(d.funnel.booked, d.funnel.enquiries)} color={c.ok} note={`${d.funnel.win_rate}% win rate`} />
            {d.funnel.lost > 0 && <Text style={type.small}>{d.funnel.lost} lost · {d.cancelled} bookings cancelled</Text>}
          </Card>
        </Section>

        <Section title="Cash position" hint="Money in and out, right now">
          <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
            <Row icon="arrow-down-circle" tone={c.danger} title="Overdue from customers" right={<Text style={{ fontWeight: '800', color: c.danger }}>{inrFull(d.cash.receivable_overdue)}</Text>} />
            <Row icon="time" tone={c.orange} title="Coming up from customers" right={<Text style={{ fontWeight: '800' }}>{inrFull(d.cash.receivable_upcoming)}</Text>} />
            <Row icon="arrow-up-circle" tone={c.purple} title="You owe suppliers" sub={`${inrFull(d.cash.payable_overdue)} of it overdue`} right={<Text style={{ fontWeight: '800' }}>{inrFull(d.cash.payable_outstanding)}</Text>} />
            <Row icon="swap-vertical" tone={d.cash.net_position < 0 ? c.danger : c.ok} title="Net position" last right={<Text style={{ fontWeight: '800', color: d.cash.net_position < 0 ? c.danger : c.ok }}>{inrFull(d.cash.net_position)}</Text>} />
          </Card>
        </Section>

        {(d.destinations || []).length > 0 && <Section title="Top destinations">
          <Card style={{ marginHorizontal: sp.l, gap: 14 }}>
            {d.destinations.slice(0, 6).map(x => (<View key={x.name}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 }}><Text style={{ fontWeight: '700', color: c.text }}>{x.name} <Text style={{ color: c.faint }}>· {x.bookings}</Text></Text><Text style={type.small}>{inrShort(x.revenue)} · margin {inrShort(x.margin)}</Text></View>
              <Progress pct={pctOf(x.revenue, maxDest)} color={c.teal} height={9} /></View>))}
          </Card>
        </Section>}

        {(d.sources || []).length > 0 && <Section title="Lead sources">
          <Card style={{ marginHorizontal: sp.l, gap: 14 }}>
            {d.sources.map(x => (<View key={x.source}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 }}><Text style={{ fontWeight: '700', color: c.text, textTransform: 'capitalize' }}>{String(x.source).replace(/_/g, ' ')}</Text><Text style={type.small}>{x.enquiries} enquir{Number(x.enquiries) === 1 ? 'y' : 'ies'} · <Text style={{ color: c.ok, fontWeight: '700' }}>{x.booked} booked</Text></Text></View>
              <Progress pct={pctOf(x.enquiries, maxSrc)} color={c.primary} height={9} /></View>))}
          </Card>
        </Section>}

        {(d.agents || []).length > 0 && <Section title="Agents">
          <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
            {d.agents.map((a, i, arr) => <Row key={a.name} avatar={a.name} title={a.name} last={i === arr.length - 1} sub={`${a.enquiries} enquiries · ${a.booked} booked · ${a.win_rate}% win`} right={<Text style={{ fontWeight: '800' }}>{inrShort(a.revenue)}</Text>} />)}
          </Card>
        </Section>}

        {(d.trend || []).length > 0 && <Section title="Six-month trend" hint="Revenue and margin">
          <Card style={{ marginHorizontal: sp.l }}><Bars data={d.trend.map(t => ({ label: MONTH[Number(String(t.month).slice(5, 7)) - 1], a: t.revenue, b: t.margin }))} colorB={c.orange} />
            <View style={{ flexDirection: 'row', gap: 16, marginTop: 10 }}><Text style={{ color: c.primary, fontWeight: '700', fontSize: 12 }}>■ Revenue</Text><Text style={{ color: c.orange, fontWeight: '700', fontSize: 12 }}>■ Margin</Text></View></Card>
        </Section>}

        {d.forecast && <Section title="Forecast" hint="A rough guide, not a promise">
          <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
            <Row icon="airplane" tone={c.teal} title="Departures in 90 days" sub={`${d.forecast.departures_90d.count} trips`} right={<Text style={{ fontWeight: '800' }}>{inrShort(d.forecast.departures_90d.revenue)}</Text>} />
            <Row icon="cash" tone={c.ok} title="Collections in 30 days" right={<Text style={{ fontWeight: '800' }}>{inrShort(d.forecast.collections_30d)}</Text>} />
            <Row icon="paper-plane" tone={c.orange} title="Open quotes" last sub={`${d.forecast.open_quotes.count} quotes worth ${inrShort(d.forecast.open_quotes.value)}`} right={<Text style={{ fontWeight: '800' }}>{inrShort(d.forecast.open_quotes.weighted_value)}</Text>} />
          </Card>
        </Section>}
      </>)}
    </Screen>)
}

function Step({ label, n, pct, color, note }) {
  return (<View>
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 }}><Text style={{ fontWeight: '700', color: c.text }}>{label}</Text><Text style={type.small}><Text style={{ fontWeight: '800', color: c.text }}>{n}</Text>{note ? ` · ${note}` : ''}</Text></View>
    <Progress pct={pct} color={color} height={9} /></View>)
}
