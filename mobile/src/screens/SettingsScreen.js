import { useState, useEffect, useCallback } from 'react'
import { View, Text, Switch, TextInput, Linking, Alert, AppState, Platform } from 'react-native'
import { pushApi } from '../api'
import { c, sp, radius, type } from '../theme'
import { Screen, Card, Section, Btn, Pill, Icon, Skeleton, ErrorBox } from '../ui'
import { permissionState, registerForPush } from '../push'
import { lockEnabled, lockAvailable, setLockEnabled } from '../lock'

const REASON = {
  category_off: 'You turned this category off', notifications_off: 'Notifications are off', quiet_hours: 'Quiet hours', throttled: 'Too many at once, summarised',
  no_device: 'No phone registered then', staff_only: 'Owners & admins only', DeviceNotRegistered: 'Phone no longer reachable', duplicate: 'Duplicate',
}
const STATUS_TONE = { sent: 'ok', delivered: 'ok', queued: 'warn', suppressed: 'neutral', error: 'danger', failed: 'danger' }
const hhmm = (v) => /^([01]\d|2[0-3]):[0-5]\d$/.test(v)

function Toggle({ title, hint, value, onChange, disabled }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', paddingVertical: 11, opacity: disabled ? 0.45 : 1 }}>
      <View style={{ flex: 1, paddingRight: 12 }}><Text style={{ color: c.text, fontWeight: '600', fontSize: 14.5 }}>{title}</Text>{!!hint && <Text style={[type.small, { marginTop: 2, lineHeight: 17 }]}>{hint}</Text>}</View>
      <Switch value={!!value} onValueChange={onChange} disabled={disabled} trackColor={{ true: c.primary, false: '#d5dce8' }} thumbColor="#fff" />
    </View>)
}

export default function SettingsScreen() {
  const [perm, setPerm] = useState('undetermined')
  const [reg, setReg] = useState(null)
  const [prefs, setPrefs] = useState(null)
  const [cats, setCats] = useState([])
  const [devices, setDevices] = useState([])
  const [log, setLog] = useState([])
  const [quiet, setQuiet] = useState({ start: '', end: '' })
  const [busy, setBusy] = useState('')
  const [err, setErr] = useState('')
  const [lock, setLock] = useState({ on: false, can: true })
  useEffect(() => { Promise.all([lockEnabled(), lockAvailable()]).then(([on, can]) => setLock({ on, can })) }, [])
  async function toggleLock(v) { try { await setLockEnabled(v); setLock(l => ({ ...l, on: v })) } catch (e) { Alert.alert('App lock', e.message) } }

  const load = useCallback(async () => {
    setPerm(Platform.OS === 'web' ? 'undetermined' : await permissionState())
    try {
      setErr('')
      const [p, d, l] = await Promise.all([pushApi.preferences(), pushApi.devices(), pushApi.log()])
      setPrefs(p.preferences); setCats(p.categories); setDevices(d); setLog(l)
      setQuiet({ start: p.preferences.quiet_start || '', end: p.preferences.quiet_end || '' })
    } catch (e) { setErr(e.message) }
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { const sub = AppState.addEventListener('change', st => { if (st === 'active') load() }); return () => sub.remove() }, [load])

  async function save(patch) {
    setPrefs(p => ({ ...p, ...patch, categories: { ...p.categories, ...(patch.categories || {}) } }))
    try { setPrefs(await pushApi.savePreferences(patch)) } catch (e) { Alert.alert('Not saved', e.message); load() }
  }
  async function enable() {
    setBusy('enable')
    const r = await registerForPush({ ask: true })
    setReg(r); setBusy(''); load()
    if (r.state === 'denied') Alert.alert('Notifications are blocked', 'Turn them on for TripSarthi in your phone settings.', [{ text: 'Open settings', onPress: () => Linking.openSettings() }, { text: 'Cancel' }])
  }
  async function test() {
    setBusy('test')
    try { const r = await pushApi.test(); Alert.alert('Sent', `Test sent to ${r.devices} phone${r.devices > 1 ? 's' : ''}. It should arrive in a few seconds.`); setTimeout(load, 2500) }
    catch (e) { Alert.alert('Could not send a test', e.message) } finally { setBusy('') }
  }
  function saveQuiet() {
    if (!quiet.start && !quiet.end) return save({ quiet_start: null, quiet_end: null })
    if (!hhmm(quiet.start) || !hhmm(quiet.end)) return Alert.alert('Use 24-hour times', 'For example 22:00 and 07:00.')
    save({ quiet_start: quiet.start, quiet_end: quiet.end })
  }

  if (!prefs) return <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>{err ? <ErrorBox text={err} onRetry={load} /> : <><Skeleton h={90} r={16} /><Skeleton h={160} r={16} /></>}</Screen>

  const on = perm === 'granted' && devices.length > 0
  const statusText = perm === 'granted' ? (devices.length ? `On: ${devices.length} phone${devices.length > 1 ? 's' : ''} registered` : 'Allowed, but this phone is not registered yet') : perm === 'denied' ? 'Blocked in phone settings' : 'Not turned on'

  return (
    <Screen contentStyle={{ padding: sp.l, gap: sp.m }}>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <View style={{ width: 42, height: 42, borderRadius: 13, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center' }}><Icon name="finger-print" size={22} color={c.primary} /></View>
          <View style={{ flex: 1 }}><Text style={type.h3}>Lock the app</Text><Text style={[type.small, { marginTop: 2, lineHeight: 17 }]}>{lock.can ? 'Ask for Face ID, fingerprint or your phone passcode when you open TripSarthi, so your customers’ details stay private.' : 'Set up Face ID, a fingerprint or a screen lock on this phone first.'}</Text></View>
          <Switch value={lock.on} disabled={!lock.can && !lock.on} onValueChange={toggleLock} trackColor={{ true: c.primary, false: '#d5dce8' }} thumbColor="#fff" />
        </View>
      </Card>

      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
          <Icon name={on ? 'notifications' : 'notifications-off'} size={22} color={on ? c.ok : c.faint} />
          <View style={{ flex: 1 }}><Text style={type.h3}>Push notifications</Text><Text style={{ color: on ? c.ok : c.sub, marginTop: 2, fontWeight: '600', fontSize: 13 }}>{statusText}</Text></View>
        </View>
        {reg?.state === 'simulator' && <Text style={[type.small, { backgroundColor: c.warnSoft, padding: 10, borderRadius: 10, marginTop: sp.m }]}>This is a simulator. Push works on real phones only.</Text>}
        {reg?.state === 'no_project' && <Text style={[type.small, { backgroundColor: c.warnSoft, padding: 10, borderRadius: 10, marginTop: sp.m }]}>This build has no Expo project ID, so it cannot get a push token.</Text>}
        {reg?.state === 'no_fcm' && <Text style={[type.small, { backgroundColor: c.warnSoft, padding: 10, borderRadius: 10, marginTop: sp.m, lineHeight: 18 }]}>Notifications are not set up in this version of the app yet. Install the latest build from your TripSarthi team to turn them on.</Text>}
        {reg?.state === 'error' && <Text style={[type.small, { color: c.danger, backgroundColor: c.dangerSoft, padding: 10, borderRadius: 10, marginTop: sp.m }]}>{reg.error}</Text>}
        <View style={{ marginTop: sp.m }}>
          {!on
            ? <Btn label={perm === 'denied' ? 'Open phone settings' : 'Turn on notifications'} icon="notifications" busy={busy === 'enable'} onPress={perm === 'denied' ? () => Linking.openSettings() : enable} />
            : <Btn label="Send me a test" icon="paper-plane" kind="ok" busy={busy === 'test'} onPress={test} />}
        </View>
      </Card>

      <Card>
        <Toggle title="All notifications" value={prefs.enabled} onChange={v => save({ enabled: v })} />
        <View style={{ borderTopWidth: 0.5, borderColor: c.line }}>
          {cats.map(k => <Toggle key={k.key} title={k.label} hint={k.hint} value={prefs.categories[k.key]} disabled={!prefs.enabled} onChange={v => save({ categories: { [k.key]: v } })} />)}
        </View>
      </Card>

      <Card>
        <Text style={type.h3}>Quiet hours</Text>
        <Text style={[type.small, { marginTop: 4, marginBottom: sp.m, lineHeight: 17 }]}>No alerts on this phone between these times (India time). They still appear in your notifications. Leave both empty to turn off.</Text>
        <View style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
          <TextInput style={st.time} placeholder="22:00" placeholderTextColor={c.faint} value={quiet.start} onChangeText={v => setQuiet(q => ({ ...q, start: v }))} onBlur={saveQuiet} maxLength={5} keyboardType="numbers-and-punctuation" />
          <Text style={type.small}>to</Text>
          <TextInput style={st.time} placeholder="07:00" placeholderTextColor={c.faint} value={quiet.end} onChangeText={v => setQuiet(q => ({ ...q, end: v }))} onBlur={saveQuiet} maxLength={5} keyboardType="numbers-and-punctuation" />
        </View>
      </Card>

      <Card><Toggle title="Hide details on the lock screen" hint="Alerts say “Payment update” instead of names and amounts. Details stay in the app." value={prefs.privacy === 'minimal'} onChange={v => save({ privacy: v ? 'minimal' : 'full' })} /></Card>

      <Text style={[type.h2, { marginTop: sp.s }]}>Recent activity</Text>
      <Card pad={false} style={{ paddingHorizontal: sp.l }}>
        {log.length === 0 && <Text style={[type.small, { paddingVertical: sp.l }]}>Nothing yet.</Text>}
        {log.slice(0, 12).map((l, i, arr) => (
          <View key={l.id} style={{ paddingVertical: 11, borderBottomWidth: i === arr.length - 1 ? 0 : 0.5, borderColor: c.line }}>
            <Text style={{ color: c.text, fontWeight: '600' }} numberOfLines={1}>{l.title}</Text>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 5 }}>
              <Pill tone={STATUS_TONE[l.status] || 'neutral'} text={l.status} />
              <Text style={[type.small, { flex: 1 }]} numberOfLines={1}>{l.reason ? (REASON[l.reason] || l.reason) + ' · ' : ''}{String(l.created_at).slice(5, 16)}</Text>
            </View>
          </View>))}
      </Card>
    </Screen>)
}
const st = { time: { borderWidth: 1, borderColor: c.border, borderRadius: radius.m, paddingVertical: 11, width: 96, textAlign: 'center', fontSize: 17, fontWeight: '700', backgroundColor: '#fff', color: c.text } }
