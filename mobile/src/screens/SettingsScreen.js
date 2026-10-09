import { useState, useEffect, useCallback } from 'react'
import { View, Text, ScrollView, Switch, TextInput, TouchableOpacity, Linking, Alert, StyleSheet, AppState } from 'react-native'
import { pushApi } from '../api'
import { c } from '../theme'
import { permissionState, registerForPush } from '../push'
import { lockEnabled, lockAvailable, setLockEnabled } from '../lock'

const REASON = {
  category_off: 'You turned this category off', notifications_off: 'Notifications are off', quiet_hours: 'Quiet hours', throttled: 'Too many at once — summarised',
  no_device: 'No phone registered then', staff_only: 'Owners & admins only', DeviceNotRegistered: 'Phone no longer reachable', duplicate: 'Duplicate',
}
const STATUS_COLOR = { sent: c.ok, delivered: c.ok, queued: c.warn, suppressed: c.sub, error: c.danger, failed: c.danger }
const hhmm = (v) => /^([01]\d|2[0-3]):[0-5]\d$/.test(v)

export default function SettingsScreen({ onSignOut }) {
  const [perm, setPerm] = useState('undetermined')
  const [reg, setReg] = useState(null)
  const [prefs, setPrefs] = useState(null)
  const [cats, setCats] = useState([])
  const [devices, setDevices] = useState([])
  const [log, setLog] = useState([])
  const [quiet, setQuiet] = useState({ start: '', end: '' })
  const [busy, setBusy] = useState('')
  const [lock, setLock] = useState({ on: false, can: true })
  useEffect(() => { Promise.all([lockEnabled(), lockAvailable()]).then(([on, can]) => setLock({ on, can })) }, [])
  async function toggleLock(v) {
    try { await setLockEnabled(v); setLock(l => ({ ...l, on: v })) } catch (e) { Alert.alert('App lock', e.message) }
  }

  const load = useCallback(async () => {
    setPerm(await permissionState())
    try {
      const [p, d, l] = await Promise.all([pushApi.preferences(), pushApi.devices(), pushApi.log()])
      setPrefs(p.preferences); setCats(p.categories); setDevices(d); setLog(l)
      setQuiet({ start: p.preferences.quiet_start || '', end: p.preferences.quiet_end || '' })
    } catch (e) { Alert.alert('Could not load settings', e.message) }
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
  if (!prefs) return <Text style={{ padding: 20 }}>Loading…</Text>

  const statusText = perm === 'granted' ? (devices.length ? `On — ${devices.length} phone${devices.length > 1 ? 's' : ''} registered` : 'Allowed, but this phone is not registered yet')
    : perm === 'denied' ? 'Blocked in phone settings' : 'Not turned on'

  return (
    <ScrollView style={{ flex: 1, backgroundColor: c.bg }} contentContainerStyle={{ padding: 14, gap: 12 }}>
      <View style={s.card}>
        <View style={s.row}>
          <View style={{ flex: 1, paddingRight: 10 }}><Text style={s.h2}>Lock the app</Text><Text style={{ color: c.sub, fontSize: 12 }}>{lock.can ? 'Ask for Face ID, fingerprint or your phone passcode when you open TripSarthi, and hide it in the app switcher. Customer details stay private if your phone is lost.' : 'Set up Face ID, a fingerprint or a screen lock in your phone settings first.'}</Text></View>
          <Switch value={lock.on} disabled={!lock.can && !lock.on} onValueChange={toggleLock} />
        </View>
      </View>

      <View style={s.card}>
        <Text style={s.h}>Push notifications</Text>
        <Text style={{ color: perm === 'granted' && devices.length ? c.ok : c.sub, marginBottom: 8 }}>{statusText}</Text>
        {reg?.state === 'simulator' && <Text style={s.note}>This is a simulator — real phones only. Test on a physical device.</Text>}
        {reg?.state === 'no_project' && <Text style={s.note}>This build has no Expo project ID, so it cannot get a push token. Use an EAS development or production build (see the README).</Text>}
        {reg?.state === 'error' && <Text style={[s.note, { color: c.danger }]}>{reg.error}</Text>}
        {perm !== 'granted' || !devices.length
          ? <TouchableOpacity style={s.btn} disabled={busy === 'enable'} onPress={perm === 'denied' ? () => Linking.openSettings() : enable}><Text style={s.btnT}>{perm === 'denied' ? 'Open phone settings' : 'Turn on notifications'}</Text></TouchableOpacity>
          : <TouchableOpacity style={[s.btn, { backgroundColor: c.ok }]} disabled={busy === 'test'} onPress={test}><Text style={s.btnT}>{busy === 'test' ? 'Sending…' : 'Send me a test'}</Text></TouchableOpacity>}
      </View>

      <View style={s.card}>
        <View style={s.row}><Text style={s.h2}>All notifications</Text><Switch value={prefs.enabled} onValueChange={v => save({ enabled: v })} /></View>
        {cats.map(k => (
          <View key={k.key} style={[s.row, { opacity: prefs.enabled ? 1 : 0.4 }]}>
            <View style={{ flex: 1, paddingRight: 10 }}><Text style={{ color: c.text, fontWeight: '600' }}>{k.label}</Text><Text style={{ color: c.sub, fontSize: 12 }}>{k.hint}</Text></View>
            <Switch disabled={!prefs.enabled} value={!!prefs.categories[k.key]} onValueChange={v => save({ categories: { [k.key]: v } })} />
          </View>))}
      </View>

      <View style={s.card}>
        <Text style={s.h2}>Quiet hours</Text>
        <Text style={{ color: c.sub, marginBottom: 8 }}>No alerts on this phone between these times (India time). They still appear in your inbox. Leave both empty to turn off.</Text>
        <View style={{ flexDirection: 'row', gap: 10, alignItems: 'center' }}>
          <TextInput style={s.input} placeholder="22:00" value={quiet.start} onChangeText={v => setQuiet(q => ({ ...q, start: v }))} onBlur={saveQuiet} maxLength={5} keyboardType="numbers-and-punctuation" />
          <Text>to</Text>
          <TextInput style={s.input} placeholder="07:00" value={quiet.end} onChangeText={v => setQuiet(q => ({ ...q, end: v }))} onBlur={saveQuiet} maxLength={5} keyboardType="numbers-and-punctuation" />
        </View>
      </View>

      <View style={s.card}>
        <View style={s.row}>
          <View style={{ flex: 1, paddingRight: 10 }}><Text style={s.h2}>Hide details on the lock screen</Text><Text style={{ color: c.sub, fontSize: 12 }}>Alerts say “Payment update” instead of names and amounts. Details stay in the app.</Text></View>
          <Switch value={prefs.privacy === 'minimal'} onValueChange={v => save({ privacy: v ? 'minimal' : 'full' })} />
        </View>
      </View>

      <View style={s.card}>
        <Text style={s.h2}>Recent activity</Text>
        {log.length === 0 ? <Text style={{ color: c.sub }}>Nothing yet.</Text> : log.slice(0, 12).map(l => (
          <View key={l.id} style={{ paddingVertical: 6, borderTopWidth: StyleSheet.hairlineWidth, borderColor: c.border }}>
            <Text style={{ color: c.text }} numberOfLines={1}>{l.title}</Text>
            <Text style={{ color: STATUS_COLOR[l.status] || c.sub, fontSize: 12 }}>{l.status}{l.reason ? ` — ${REASON[l.reason] || l.reason}` : ''} · {String(l.created_at).slice(5, 16)}</Text>
          </View>))}
      </View>

      <TouchableOpacity style={[s.btn, { backgroundColor: '#fff', borderWidth: 1, borderColor: c.border }]} onPress={onSignOut}><Text style={{ color: c.danger, fontWeight: '700' }}>Sign out</Text></TouchableOpacity>
    </ScrollView>
  )
}
const s = StyleSheet.create({
  card: { backgroundColor: c.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: c.border },
  h: { fontSize: 18, fontWeight: '800', color: c.text, marginBottom: 2 }, h2: { fontSize: 15, fontWeight: '800', color: c.text },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 8 },
  btn: { backgroundColor: c.primary, padding: 14, borderRadius: 12, alignItems: 'center' }, btnT: { color: '#fff', fontWeight: '700' },
  note: { backgroundColor: '#fef9c3', padding: 10, borderRadius: 10, marginBottom: 8, color: '#713f12' },
  input: { borderWidth: 1, borderColor: c.border, borderRadius: 10, padding: 10, width: 90, textAlign: 'center', fontSize: 16, backgroundColor: '#fff' },
})
