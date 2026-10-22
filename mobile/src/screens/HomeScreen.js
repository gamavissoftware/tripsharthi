import { useState, useCallback, useEffect, useRef } from 'react'
import { View, Text, ScrollView, TouchableOpacity, StyleSheet, Platform } from 'react-native'
import { useFocusEffect } from '@react-navigation/native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { biz } from '../api'
import { c, sp, radius, type } from '../theme'
import { Screen, GradientHeader, Card, Section, Kpi, Pill, Row, Progress, Bars, Icon, Chip, TargetBar, ErrorBox, Skeleton, Empty, ok } from '../ui'
import { inrShort, inrFull, fmtDay, dueLabel, indiaToday, greeting, pctOf } from '../logic'
import OfflineBanner from '../OfflineBanner'
import { UpdateBanner } from '../components'

const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const monthLabel = (ym) => MONTH[Number(String(ym).slice(5, 7)) - 1] || ''
const SOURCE = { google_lead_forms: 'Google lead forms', meta_lead_ads: 'Meta lead ads', whatsapp: 'WhatsApp', manual: 'Added by hand', web_form: 'Website form', portal: 'Travel portals', import: 'Imports', email: 'E-mail' }
const srcName = (k) => SOURCE[k] || String(k || 'Other').replace(/_/g, ' ').replace(/^./, m => m.toUpperCase())
const STAGE = { enquiry: c.primary, quoted: c.orange, negotiating: c.purple, booked: c.ok }

export default function HomeScreen({ navigation, unread = 0, user, update }) {
  const insets = useSafeAreaInsets()
  const [d, setD] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)
  const [focus, setFocus] = useState(null)               // managers: one person's id; null = everyone
  const [hideUpdate, setHideUpdate] = useState(false)
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])

  const load = useCallback(async () => {
    try { setErr(''); const r = await biz.homeC(focus); if (!alive.current) return; setD(r.data); setMeta({ stale: r.stale, at: r.at }) }
    catch (e) { if (alive.current) setErr(e.message) }
  }, [focus])
  // refresh whenever the tab is showing, and every two minutes like the web dashboard
  useFocusEffect(useCallback(() => { load(); const t = setInterval(load, 120000); return () => clearInterval(t) }, [load]))

  const today = indiaToday()
  const manager = !!d?.scope?.manager
  const k = d?.kpis
  const go = (screen, params) => () => navigation.navigate(screen, params)

  const header = (
    <GradientHeader style={{ paddingTop: insets.top + (Platform.OS === 'web' ? 14 : 8) }}>
      <View style={{ flexDirection: 'row', alignItems: 'center' }}>
        <View style={{ flex: 1 }}>
          <Text style={{ color: '#b7cdee', fontSize: 13, fontWeight: '600' }}>{greeting()}</Text>
          <Text style={{ color: '#fff', fontSize: 24, fontWeight: '800', letterSpacing: -0.4, marginTop: 1 }} numberOfLines={1}>{(user?.name || 'there').split(' ')[0]}</Text>
        </View>
        <TouchableOpacity onPress={go('Notifications')} style={s.bell} accessibilityLabel="Notifications">
          <Icon name="notifications-outline" size={22} color="#fff" />
          {unread > 0 && <View style={s.badge}><Text style={{ color: '#fff', fontSize: 10, fontWeight: '800' }}>{unread > 99 ? '99+' : unread}</Text></View>}
        </TouchableOpacity>
      </View>
      <Text style={{ color: '#9db8e0', marginTop: 12, fontSize: 12.5 }}>
        {d ? `${d.month} · day ${d.month_progress.day} of ${d.month_progress.days_in_month} · ${d.month_progress.days_left} days left` : ' '}
      </Text>
      <View style={{ flexDirection: 'row', gap: 10, marginTop: 16 }}>
        <QuickAction icon="add-circle" label="New enquiry" onPress={() => navigation.navigate('NewEnquiry')} />
        <QuickAction icon="alarm" label="New task" onPress={() => navigation.navigate('Tasks', { new: Date.now() })} />
        <QuickAction icon="person-add" label="New contact" onPress={() => navigation.navigate('Contacts', { new: Date.now() })} />
      </View>
    </GradientHeader>
  )

  if (!d) {
    return (
      <Screen header={header} onRefresh={load} refreshing={refreshing}>
        <View style={{ padding: sp.l, gap: sp.m }}>
          {err ? <ErrorBox text={err} onRetry={load} /> : <>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: '3.6%' }}>{[0, 1, 2, 3].map(i => <View key={i} style={{ width: '48.2%', marginBottom: sp.m }}><Skeleton h={112} r={16} /></View>)}</View>
            <Skeleton h={120} r={16} /><Skeleton h={160} r={16} /></>}
        </View>
      </Screen>)
  }

  const a = d.attention || {}
  const attention = [
    a.overdue_tasks > 0 && { icon: 'alarm', tone: c.danger, text: `${a.overdue_tasks} overdue task${a.overdue_tasks > 1 ? 's' : ''}`, to: go('Tasks') },
    a.payments_overdue?.count > 0 && { icon: 'cash', tone: c.danger, text: `${a.payments_overdue.count} payment${a.payments_overdue.count > 1 ? 's' : ''} overdue`, sub: inrFull(a.payments_overdue.amount) + ' to collect', to: go('Bookings') },
    a.tasks_today > 0 && { icon: 'today', tone: c.orange, text: `${a.tasks_today} task${a.tasks_today > 1 ? 's' : ''} due today`, to: go('Tasks') },
    a.payments_week?.count > 0 && { icon: 'calendar', tone: c.orange, text: `${a.payments_week.count} payment${a.payments_week.count > 1 ? 's' : ''} due this week`, sub: inrFull(a.payments_week.amount), to: go('Bookings') },
    a.quotes_to_chase > 0 && { icon: 'eye', tone: c.purple, text: `${a.quotes_to_chase} quote${a.quotes_to_chase > 1 ? 's' : ''} viewed, not accepted`, sub: 'Worth a call', to: (d.quotes_to_chase || []).length === 1 ? go('Trip', { id: Number(d.quotes_to_chase[0].trip_id) }) : go('Enquiries') },
    a.no_activity > 0 && { icon: 'chatbubble-ellipses', tone: c.primary, text: `${a.no_activity} enquir${a.no_activity > 1 ? 'ies' : 'y'} with no next step`, to: go('Enquiries') },
    a.departures_7d > 0 && { icon: 'airplane', tone: c.teal, text: `${a.departures_7d} departure${a.departures_7d > 1 ? 's' : ''} in 7 days`, to: go('Departures') },
    a.docs_pending_7d > 0 && { icon: 'document-text', tone: c.orange, text: `${a.docs_pending_7d} departure${a.docs_pending_7d > 1 ? 's' : ''} with documents pending`, to: go('Departures') },
  ].filter(Boolean)

  const maxPipe = Math.max(1, ...(d.pipeline || []).map(p => Number(p.count)))
  const maxSrc = Math.max(1, ...(d.sources || []).map(p => Number(p.enquiries)))
  const trendAll = (d.trend || []).map(t => ({ label: monthLabel(t.month), a: t.revenue, b: t.collected }))
  const trend = trendAll.some(t => Number(t.a) > 0 || Number(t.b) > 0) ? trendAll : []     // an all-zero chart is just noise

  return (
    <Screen header={header} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing}>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      {update?.state === 'available' && !hideUpdate && <UpdateBanner link={update.link} version={update.latest} onDismiss={() => setHideUpdate(true)} />}
      {!!err && <View style={{ padding: sp.l }}><ErrorBox text={err} onRetry={load} /></View>}

      {manager && d.team?.length > 1 && (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ padding: sp.l, paddingBottom: 0, gap: 8 }}>
          <Chip label="Everyone" active={!focus} onPress={() => setFocus(null)} icon="people" />
          {d.team.map(m => <Chip key={m.id} label={m.name.split(' ')[0]} active={focus === m.id} onPress={() => setFocus(m.id)} />)}
        </ScrollView>)}

      {/* headline numbers, this month against last month */}
      <View style={s.grid}>
        <Kpi icon="chatbubbles" tone={c.primary} label="Enquiries" value={String(k.enquiries.value)} delta={{ value: k.enquiries.value, prev: k.enquiries.prev }} onPress={go('Enquiries')} />
        <Kpi icon="paper-plane" tone={c.orange} label="Quotes sent" value={String(k.quotes_sent.value)} delta={{ value: k.quotes_sent.value, prev: k.quotes_sent.prev }} onPress={go('Enquiries')} />
        <Kpi icon="checkmark-done-circle" tone={c.ok} label="Bookings" value={String(k.bookings.value)} delta={{ value: k.bookings.value, prev: k.bookings.prev }} onPress={go('Bookings')} />
        <Kpi icon="trending-up" tone={c.teal} label="Revenue (ex-tax)" value={inrShort(k.revenue.value)} delta={{ value: k.revenue.value, prev: k.revenue.prev }} />
        <Kpi icon="wallet" tone={c.purple} label="Cash collected" value={inrShort(k.collected.value)} delta={{ value: k.collected.value, prev: k.collected.prev }} />
        {k.margin ? <Kpi icon="pie-chart" tone={c.navy} label="Margin" value={inrShort(k.margin.value)} sub={`${k.margin.pct}% of revenue`} />
          : <Kpi icon="airplane" tone={c.navy} label="Open trips" value={String((d.pipeline || []).reduce((n, p) => n + Number(p.count), 0))} onPress={go('Enquiries')} />}
      </View>
      {k.bookings_without_cost?.value > 0 && <Text style={[type.small, { paddingHorizontal: sp.l, marginTop: -4 }]}>⚠ {k.bookings_without_cost.value} booking{k.bookings_without_cost.value > 1 ? 's have' : ' has'} no supplier cost entered, so margin looks higher than it is.</Text>}

      {/* target */}
      <Section title={focus && manager ? 'Target this month' : 'My target this month'} action={manager ? 'All targets' : undefined} onAction={go('Targets')}>
        <Card style={{ marginHorizontal: sp.l }}>
          {d.target ? (<>
            <TargetBar label="Revenue (ex-tax)" p={d.target.revenue} daysLeft={d.month_progress.days_left} />
            <TargetBar label="Bookings" p={d.target.bookings} money={false} daysLeft={d.month_progress.days_left} />
            <Text style={[type.small, { marginTop: 10 }]}>{d.target.source === 'carried' ? 'Carried over from an earlier month. ' : ''}The tick shows where you would be today on an even pace.</Text>
          </>) : <Text style={type.small}>{manager ? 'No target set yet. Set targets on the web app, Settings → Sales targets.' : 'No target set for you yet. Ask your manager.'}</Text>}
        </Card>
      </Section>

      {/* what needs doing */}
      <Section title="Needs your attention" hint={attention.length ? undefined : undefined}>
        <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
          {attention.length === 0
            ? <View style={{ padding: sp.l, flexDirection: 'row', alignItems: 'center', gap: 10 }}><Icon name="checkmark-circle" size={24} color={c.ok} /><Text style={{ color: c.text, fontWeight: '600' }}>All clear. Nothing urgent right now.</Text></View>
            : attention.map((x, i) => <Row key={x.text} icon={x.icon} tone={x.tone} title={x.text} sub={x.sub} onPress={x.to} last={i === attention.length - 1} />)}
        </Card>
      </Section>

      {/* pipeline */}
      <Section title="Pipeline" action="All enquiries" onAction={go('Enquiries')}>
        <Card style={{ marginHorizontal: sp.l, gap: 14 }}>
          {(d.pipeline || []).length === 0 && <Text style={type.small}>No open enquiries yet.</Text>}
          {(d.pipeline || []).map(p => (
            <TouchableOpacity key={p.status} activeOpacity={0.7} onPress={go('Enquiries')}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 }}>
                <Text style={{ fontWeight: '700', color: c.text, textTransform: 'capitalize' }}>{p.status} <Text style={{ color: c.faint, fontWeight: '600' }}>· {p.count}</Text></Text>
                <Text style={{ color: c.sub, fontSize: 13 }}>{inrShort(p.value)}{Number(p.stale) > 0 ? <Text style={{ color: c.warn }}>  · {p.stale} untouched 7d</Text> : null}</Text>
              </View>
              <Progress pct={pctOf(p.count, maxPipe)} color={STAGE[p.status] || c.primary} height={9} />
            </TouchableOpacity>))}
        </Card>
      </Section>

      {/* money to collect */}
      <Section title="Payments to collect" action="All bookings" onAction={go('Bookings')}>
        <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
          {(d.collections || []).length === 0 && <Text style={[type.small, { padding: sp.l }]}>Nothing pending. All collected 🎉</Text>}
          {(d.collections || []).slice(0, 6).map((p, i, arr) => (
            <Row key={p.id} avatar={p.customer} tone={p.overdue ? c.danger : c.orange} title={p.customer || p.title} last={i === arr.length - 1}
              sub={`${p.label} · ${p.booking_ref}`} onPress={go('Booking', { id: Number(p.booking_id) })}
              right={<View style={{ alignItems: 'flex-end' }}><Text style={{ fontWeight: '800', color: c.text }}>{inrFull(p.amount)}</Text><Text style={{ fontSize: 11.5, fontWeight: '700', color: p.overdue ? c.danger : c.warn, marginTop: 2 }}>{p.overdue ? 'overdue · ' : ''}{dueLabel(p.due_date, today)}</Text></View>} />))}
        </Card>
      </Section>

      {/* departures */}
      {(d.departures || []).length > 0 && (
        <Section title="Leaving soon" hint="Next 14 days" action="All departures" onAction={go('Departures')}>
          <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
            {d.departures.slice(0, 5).map((x, i, arr) => (
              <Row key={x.id} icon="airplane" tone={c.teal} title={x.customer || x.title} last={i === arr.length - 1}
                sub={`${fmtDay(x.travel_start)} (${dueLabel(x.travel_start, today)}) · ${x.title}`} onPress={go('Booking', { id: Number(x.id) })}
                right={<View style={{ alignItems: 'flex-end', gap: 4 }}>{Number(x.docs_pending) > 0 ? <Pill tone="warn" text={`${x.docs_pending} docs pending`} /> : <Pill tone="ok" text="docs ready" />}{Number(x.total_amount) > Number(x.paid_amount) && <Text style={{ fontSize: 11.5, color: c.danger, fontWeight: '700' }}>{inrShort(Number(x.total_amount) - Number(x.paid_amount))} due</Text>}</View>} />))}
          </Card>
        </Section>)}

      {/* quotes viewed but not accepted */}
      {(d.quotes_to_chase || []).length > 0 && (
        <Section title="Quotes to chase" hint="Opened by the customer, not yet accepted">
          <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
            {d.quotes_to_chase.map((q, i, arr) => (
              <Row key={q.trip_id} icon="eye" tone={c.purple} title={q.title} last={i === arr.length - 1} sub={`Viewed ${fmtDay(q.viewed_at)} · ${inrFull(q.total)}`} onPress={go('Trip', { id: Number(q.trip_id) })} />))}
          </Card>
        </Section>)}

      {/* tasks */}
      <Section title="Tasks" action="All tasks" onAction={go('Tasks')}>
        <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
          {(d.tasks || []).length === 0 && <Text style={[type.small, { padding: sp.l }]}>No open tasks. Nice.</Text>}
          {(d.tasks || []).slice(0, 5).map((t, i, arr) => (
            <TaskRow key={t.id} t={t} today={today} last={i === arr.length - 1} onDone={async () => { try { await biz.completeTask(t.id); ok(); load() } catch (e) { setErr(e.message) } }} onOpen={t.trip_id ? go('Trip', { id: Number(t.trip_id) }) : undefined} />))}
        </Card>
      </Section>

      {/* trend */}
      {trend.length > 0 && (
        <Section title="Last 6 months" hint="Revenue (ex-tax) and cash collected">
          <Card style={{ marginHorizontal: sp.l }}>
            <Bars data={trend} />
            <View style={{ flexDirection: 'row', gap: 16, marginTop: 10 }}>
              <Legend color={c.primary} text="Revenue" /><Legend color={c.teal} text="Collected" />
            </View>
          </Card>
        </Section>)}

      {/* where leads come from */}
      {(d.sources || []).length > 0 && (
        <Section title="Where enquiries come from" hint="This month · enquiries and how many booked">
          <Card style={{ marginHorizontal: sp.l, gap: 14 }}>
            {d.sources.map(x => (
              <View key={x.source}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 }}>
                  <Text style={{ fontWeight: '700', color: c.text }}>{srcName(x.source)}</Text>
                  <Text style={{ color: c.sub, fontSize: 13 }}>{x.enquiries} enquir{x.enquiries === 1 ? 'y' : 'ies'} · <Text style={{ color: c.ok, fontWeight: '700' }}>{x.booked} booked</Text></Text>
                </View>
                <Progress pct={pctOf(x.enquiries, maxSrc)} color={c.teal} height={9} />
              </View>))}
          </Card>
        </Section>)}

      {/* owners / admins only: what we owe suppliers, and the team */}
      {manager && d.payables && (
        <Section title="Owed to suppliers">
          <View style={s.grid}>
            <Kpi icon="business" tone={c.navy} label="Outstanding" value={inrShort(d.payables.outstanding)} />
            <Kpi icon="warning" tone={c.danger} label="Overdue" value={inrShort(d.payables.overdue)} />
            <Kpi icon="time" tone={c.orange} label="Due in 7 days" value={inrShort(d.payables.due_7d)} wide />
          </View>
        </Section>)}

      {manager && (d.team || []).length > 0 && !focus && (
        <Section title="Team this month">
          <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
            {d.team.map((m, i, arr) => (
              <Row key={m.id} avatar={m.name} tone={c.primary} title={m.name} last={i === arr.length - 1}
                sub={`${m.enquiries} enquiries · ${m.bookings} bookings · ${m.open_trips} open${m.overdue_tasks ? ` · ${m.overdue_tasks} overdue tasks` : ''}`}
                right={<Text style={{ fontWeight: '800', color: c.text }}>{inrShort(m.revenue)}</Text>} />))}
          </Card>
        </Section>)}
    </Screen>)
}

/** One of the three big shortcuts under the greeting: the things an agent does all day. */
function QuickAction({ icon, label, onPress }) {
  return (
    <TouchableOpacity activeOpacity={0.8} onPress={onPress} accessibilityRole="button" accessibilityLabel={label} style={{ flex: 1, alignItems: 'center', paddingVertical: 11, borderRadius: 16, backgroundColor: 'rgba(255,255,255,0.15)' }}>
      <Icon name={icon} size={24} color="#fff" />
      <Text style={{ color: '#fff', fontWeight: '700', fontSize: 12.5, marginTop: 5 }}>{label}</Text>
    </TouchableOpacity>)
}

function Legend({ color, text }) { return <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}><View style={{ width: 10, height: 10, borderRadius: 3, backgroundColor: color }} /><Text style={type.small}>{text}</Text></View> }

/** One task with a tick-box. Shared by Home and the Tasks screen. */
export function TaskRow({ t, today, last, onDone, onOpen }) {
  const late = t.due_at && String(t.due_at).slice(0, 10) < today
  return (
    <View style={[s.task, !last && { borderBottomWidth: StyleSheet.hairlineWidth, borderColor: c.line }]}>
      <TouchableOpacity onPress={onDone} hitSlop={10} accessibilityLabel={`Mark ${t.title} done`} style={[s.tick, t.status === 'done' && { backgroundColor: c.ok, borderColor: c.ok }]}>
        {t.status === 'done' && <Icon name="checkmark" size={14} color="#fff" />}
      </TouchableOpacity>
      <TouchableOpacity style={{ flex: 1 }} activeOpacity={onOpen ? 0.7 : 1} onPress={onOpen}>
        <Text style={{ color: c.text, fontWeight: '600', fontSize: 14.5, textDecorationLine: t.status === 'done' ? 'line-through' : 'none' }} numberOfLines={2}>{t.title}</Text>
        <Text style={[type.small, { marginTop: 2 }]} numberOfLines={1}>
          {t.type ? String(t.type).replace(/_/g, ' ') : 'task'}{t.trip_title ? ` · ${t.trip_title}` : ''}
        </Text>
      </TouchableOpacity>
      {!!t.due_at && <Text style={{ fontSize: 12, fontWeight: '700', color: late && t.status !== 'done' ? c.danger : c.sub }}>{late && t.status !== 'done' ? 'overdue · ' : ''}{dueLabel(t.due_at, today)}</Text>}
    </View>)
}

const s = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', paddingHorizontal: sp.l, paddingTop: sp.l, rowGap: sp.m },
  bell: { width: 42, height: 42, borderRadius: 21, backgroundColor: 'rgba(255,255,255,0.14)', alignItems: 'center', justifyContent: 'center' },
  badge: { position: 'absolute', top: -2, right: -2, minWidth: 18, height: 18, borderRadius: 9, backgroundColor: c.orange, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4, borderWidth: 2, borderColor: c.navy },
  task: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
  tick: { width: 24, height: 24, borderRadius: 12, borderWidth: 2, borderColor: c.faint, alignItems: 'center', justifyContent: 'center' },
})
