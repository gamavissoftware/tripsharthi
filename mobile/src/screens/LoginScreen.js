import { useState, useRef } from 'react'
import { View, Text, TextInput, TouchableOpacity, ScrollView, KeyboardAvoidingView, Platform, Linking, Image, StyleSheet } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { api, saveToken } from '../api'
import { c, sp, radius, type, shadow } from '../theme'
import { GradientHeader, Btn, Icon, useKeyboardHeight } from '../ui'

export default function LoginScreen({ onLogin }) {
  const insets = useSafeAreaInsets()
  const kb = useKeyboardHeight()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const pw = useRef(null)

  async function submit() {
    if (busy) return
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) { setErr('Enter the e-mail you registered with.'); return }
    if (!password) { setErr('Enter your password.'); return }
    setBusy(true); setErr('')
    try { const r = await api.login(email.trim(), password); await saveToken(r.token); onLogin() }
    catch (e) { setErr(e.message) } finally { setBusy(false) }
  }

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ flexGrow: 1, paddingBottom: kb }} showsVerticalScrollIndicator={false}>
        <GradientHeader style={{ paddingTop: insets.top + 36, paddingBottom: 70, alignItems: 'center' }}>
          <View style={s.logoWrap}><Image source={require('../../assets/icon.png')} style={{ width: 76, height: 76, borderRadius: 18 }} /></View>
          <Text style={{ color: '#fff', fontSize: 30, fontWeight: '800', letterSpacing: -0.6, marginTop: 14 }}>Trip<Text style={{ color: '#6fe0d6' }}>Sarthi</Text></Text>
          <Text style={{ color: '#b7cdee', marginTop: 6, fontSize: 14.5, textAlign: 'center' }}>Your travel business, in your pocket.</Text>
        </GradientHeader>

        <View style={[s.card, { marginTop: -42 }]}>
          <Text style={type.h2}>Welcome back</Text>
          <Text style={[type.small, { marginTop: 3, marginBottom: sp.l }]}>Sign in with your agency account.</Text>

          <Text style={[type.label, { marginBottom: 6 }]}>E-mail</Text>
          <View style={s.field}>
            <Icon name="mail-outline" size={18} color={c.faint} />
            <TextInput style={s.input} placeholder="you@company.com" placeholderTextColor={c.faint} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" textContentType="username" autoComplete="email"
              value={email} onChangeText={t => { setEmail(t); setErr('') }} returnKeyType="next" onSubmitEditing={() => pw.current && pw.current.focus()} />
          </View>

          <Text style={[type.label, { marginBottom: 6, marginTop: sp.m }]}>Password</Text>
          <View style={s.field}>
            <Icon name="lock-closed-outline" size={18} color={c.faint} />
            <TextInput ref={pw} style={s.input} placeholder="Your password" placeholderTextColor={c.faint} secureTextEntry={!show} textContentType="password" autoComplete="password"
              value={password} onChangeText={t => { setPassword(t); setErr('') }} returnKeyType="go" onSubmitEditing={submit} />
            <TouchableOpacity onPress={() => setShow(v => !v)} hitSlop={10} accessibilityLabel={show ? 'Hide password' : 'Show password'}><Icon name={show ? 'eye-off-outline' : 'eye-outline'} size={20} color={c.sub} /></TouchableOpacity>
          </View>

          {!!err && <View style={s.err}><Icon name="alert-circle" size={17} color={c.danger} /><Text style={{ color: '#7a1d25', flex: 1, fontSize: 13.5 }}>{err}</Text></View>}

          <Btn label="Sign in" onPress={submit} busy={busy} style={{ marginTop: sp.l }} />
          <TouchableOpacity onPress={() => Linking.openURL('https://app.tripsarthi.com/#/login')} style={{ alignItems: 'center', marginTop: sp.l }}>
            <Text style={{ color: c.primary, fontWeight: '700', fontSize: 13.5 }}>Forgot your password?</Text>
          </TouchableOpacity>
        </View>

        <View style={{ alignItems: 'center', padding: sp.xl, paddingBottom: insets.bottom + sp.xl }}>
          <Text style={type.small}>New to TripSarthi?</Text>
          <TouchableOpacity onPress={() => Linking.openURL('https://tripsarthi.com')}><Text style={{ color: c.primary, fontWeight: '700', marginTop: 4 }}>Create your agency account at tripsarthi.com</Text></TouchableOpacity>
        </View>
      </ScrollView>
    </View>)
}

const s = StyleSheet.create({
  logoWrap: { borderRadius: 22, padding: 4, backgroundColor: 'rgba(255,255,255,0.18)' },
  card: { marginHorizontal: sp.l, backgroundColor: '#fff', borderRadius: radius.xl, padding: sp.xl, ...shadow, borderWidth: StyleSheet.hairlineWidth, borderColor: c.border },
  field: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c.bg, borderRadius: radius.m, borderWidth: 1, borderColor: c.border, paddingHorizontal: 14 },
  input: { flex: 1, paddingVertical: 14, fontSize: 15.5, color: c.text },
  err: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: '#fff3f4', borderRadius: radius.m, padding: 12, marginTop: sp.m, borderWidth: 1, borderColor: '#f6c9ce' },
})
