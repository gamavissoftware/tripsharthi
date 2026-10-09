import { useState } from 'react'
import { View, Text, TextInput, TouchableOpacity, ActivityIndicator, StyleSheet, KeyboardAvoidingView, Platform } from 'react-native'
import { api, saveToken } from '../api'
import { c } from '../theme'

export default function LoginScreen({ onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  async function submit() {
    setBusy(true); setErr('')
    try { const r = await api.login(email.trim(), password); await saveToken(r.token); onLogin() }
    catch (e) { setErr(e.message) } finally { setBusy(false) }
  }
  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={s.wrap}>
      <Text style={s.logo}>TripSarthi</Text>
      <Text style={s.sub}>Sign in to your agency account</Text>
      <TextInput style={s.input} placeholder="Email" autoCapitalize="none" keyboardType="email-address" value={email} onChangeText={setEmail} />
      <TextInput style={s.input} placeholder="Password" secureTextEntry value={password} onChangeText={setPassword} onSubmitEditing={submit} />
      {!!err && <Text style={s.err}>{err}</Text>}
      <TouchableOpacity style={s.btn} onPress={submit} disabled={busy || !email || !password}>
        {busy ? <ActivityIndicator color="#fff" /> : <Text style={s.btnText}>Sign in</Text>}
      </TouchableOpacity>
    </KeyboardAvoidingView>
  )
}
const s = StyleSheet.create({
  wrap: { flex: 1, justifyContent: 'center', padding: 24, backgroundColor: c.bg },
  logo: { fontSize: 30, fontWeight: '800', color: c.text, textAlign: 'center' },
  sub: { color: c.sub, textAlign: 'center', marginBottom: 24, marginTop: 4 },
  input: { backgroundColor: c.card, borderWidth: 1, borderColor: c.border, borderRadius: 12, padding: 14, marginBottom: 12, fontSize: 16 },
  btn: { backgroundColor: c.primary, borderRadius: 12, padding: 16, alignItems: 'center', marginTop: 4 },
  btnText: { color: '#fff', fontWeight: '700', fontSize: 16 },
  err: { color: c.danger, marginBottom: 8 },
})
