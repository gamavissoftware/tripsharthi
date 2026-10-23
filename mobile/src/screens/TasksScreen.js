import { useState, useCallback, useRef, useEffect } from 'react'
import { View, ScrollView, Alert } from 'react-native'
import { Text } from '../T'
import { useFocusEffect } from '@react-navigation/native'
import { biz } from '../api'
import { sendOrQueue, sendOrQueueRaw } from '../outbox'
import { c, sp, type } from '../theme'
import { Screen, Card, Chip, Empty, ErrorBox, SkeletonList, Fab, Sheet, Input, Btn, ok } from '../ui'
import { indiaToday } from '../logic'
import { TaskRow } from './HomeScreen'
import OfflineBanner from '../OfflineBanner'
import DateField from '../DateField'

const TYPES = [['call', 'Call', 'call'], ['whatsapp', 'WhatsApp', 'logo-whatsapp'], ['email', 'E-mail', 'mail'], ['meeting', 'Meeting', 'people'], ['todo', 'To-do', 'checkbox']]
const addDays = (n) => { const d = new Date(Date.now() + 330 * 60000 + n * 86400000); return d.toISOString().slice(0, 10) }
const WHEN = [['Today', 0], ['Tomorrow', 1], ['In 3 days', 3], ['Next week', 7]]

export default function TasksScreen({ route }) {
  const [tab, setTab] = useState('open')
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState({ stale: false, at: null })
  const [err, setErr] = useState('')
  const [refreshing, setRefreshing] = useState(false)
  const [sheet, setSheet] = useState(false)
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])
  const today = indiaToday()

  const load = useCallback(async () => {
    try { setErr(''); const r = await biz.tasksC(tab); if (!alive.current) return; setRows(r.data); setMeta({ stale: r.stale, at: r.at }) } catch (e) { if (alive.current) setErr(e.message) }
  }, [tab])
  useFocusEffect(useCallback(() => { load() }, [load]))
  useEffect(() => { if (route?.params?.new) setSheet(true) }, [route?.params?.new])

  const list = (rows || []).slice().sort((a, b) => String(a.due_at || '9999').localeCompare(String(b.due_at || '9999')))
  const late = list.filter(t => t.due_at && String(t.due_at).slice(0, 10) < today && t.status !== 'done').length

  async function done(t) {
    if (t.status === 'done') return
    setRows(r => r.filter(x => x.id !== t.id))                       // optimistic: it leaves the list at once
    try { await sendOrQueueRaw({ path: `/tasks/${t.id}/complete`, summary: `Done: ${t.title}`, kind: 'task_done' }); ok() } catch (e) { Alert.alert('Could not mark done', e.message); load() }
  }

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <View style={{ flexDirection: 'row', gap: 8, padding: sp.l, paddingBottom: sp.s }}>
        <Chip label="Open" active={tab === 'open'} onPress={() => { setRows(null); setTab('open') }} count={tab === 'open' && rows ? rows.length : undefined} icon="radio-button-off" />
        <Chip label="Done" active={tab === 'done'} onPress={() => { setRows(null); setTab('done') }} icon="checkmark-circle" />
        {late > 0 && tab === 'open' && <View style={{ marginLeft: 'auto', alignSelf: 'center' }}><Text style={{ color: c.danger, fontWeight: '700', fontSize: 12.5 }}>{late} overdue</Text></View>}
      </View>
      <OfflineBanner stale={meta.stale} at={meta.at} />
      <Screen onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false) }} refreshing={refreshing} contentStyle={{ paddingBottom: 110 }}>
        {!!err && <View style={{ padding: sp.l }}><ErrorBox text={err} onRetry={load} /></View>}
        {!rows && !err && <SkeletonList n={4} />}
        {rows && list.length === 0 && <Empty icon={tab === 'open' ? 'checkmark-done-circle' : 'list'} title={tab === 'open' ? 'No open tasks' : 'Nothing completed yet'} text={tab === 'open' ? 'Add a follow-up so no enquiry goes cold.' : 'Tasks you finish show up here.'} action={tab === 'open' ? 'Add a task' : undefined} onAction={() => setSheet(true)} />}
        {rows && list.length > 0 && (
          <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
            {list.map((t, i) => <TaskRow key={t.id} t={t} today={today} last={i === list.length - 1} onDone={() => done(t)} />)}
          </Card>)}
      </Screen>
      <Fab icon="add" label="New task" onPress={() => setSheet(true)} />
      <NewTask visible={sheet} onClose={() => setSheet(false)} onSaved={() => { setSheet(false); setTab('open'); load() }} />
    </View>)
}

function NewTask({ visible, onClose, onSaved, dealId }) {
  const [title, setTitle] = useState('')
  const [type, setType] = useState('call')
  const [when, setWhen] = useState(1)
  const [custom, setCustom] = useState('')          // a date picked from the calendar overrides the quick choices
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  async function save() {
    if (title.trim().length < 2) { setErr('Say what needs doing'); return }
    setBusy(true); setErr('')
    try {
      const r = await sendOrQueue('task', { title: title.trim(), type, priority: 'medium', due_at: `${custom || addDays(when)} 10:00:00`, ...(dealId ? { related_type: 'deal', related_id: dealId } : {}) }, title.trim())
      ok(); setTitle(''); setCustom(''); setWhen(1)
      if (r.queued) Alert.alert('Saved on your phone', 'No internet right now. This task will be added automatically as soon as you are back online.')
      onSaved()
    } catch (e) { setErr(e.message) } finally { setBusy(false) }
  }
  return (
    <Sheet visible={visible} onClose={onClose} title="New task">
      <Input label="What needs doing?" value={title} onChangeText={t => { setTitle(t); setErr('') }} placeholder="Call Rohit about the Bali quote" error={err} autoFocus />
      <Text style={[type.label, { marginBottom: 8 }]}>Type</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingBottom: sp.m }}>{TYPES.map(([k, l, i]) => <Chip key={k} label={l} icon={i} active={type === k} onPress={() => setType(k)} />)}</ScrollView>
      <Text style={[type.label, { marginBottom: 8 }]}>When</Text>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingBottom: sp.l }}>{WHEN.map(([l, n]) => <Chip key={l} label={l} active={!custom && when === n} onPress={() => { setCustom(''); setWhen(n) }} />)}</ScrollView>
      <DateField label="Or pick a date" value={custom} onChange={setCustom} minimumDate={new Date()} clearable placeholder="Choose from the calendar" />
      <Btn label="Add task" icon="add" onPress={save} busy={busy} />
    </Sheet>)
}
export { NewTask }
