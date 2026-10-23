import { useEffect, useRef, useState, useCallback, createContext, useContext } from 'react'
import { View, TouchableOpacity, ScrollView, RefreshControl, Animated, StyleSheet, ActivityIndicator, Platform, Modal, TextInput, Keyboard, Dimensions } from 'react-native'
import { Text } from './T'
import { Ionicons } from '@expo/vector-icons'
import { LinearGradient } from 'expo-linear-gradient'
import * as Haptics from 'expo-haptics'
import { tr } from './i18n'
import { c, sp, radius, shadow, type, STATUS } from './theme'
import { deltaPct, initials, clampPct, targetStatus, toGoText, hasTarget, inrFull } from './logic'

/** A light tap on the phone for important actions. Never throws (web / unsupported devices do nothing). */
export const tap = () => { if (Platform.OS !== 'web') Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => {}) }
export const ok = () => { if (Platform.OS !== 'web') Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {}) }

export function Icon({ name, size = 20, color = c.text, style }) { return <Ionicons name={name} size={size} color={color} style={style} /> }

export function Card({ children, style, onPress, pad = true }) {
  const body = [s.card, pad && { padding: sp.l }, style]
  if (onPress) return <TouchableOpacity activeOpacity={0.8} onPress={() => { tap(); onPress() }} style={body}>{children}</TouchableOpacity>
  return <View style={body}>{children}</View>
}

export function Section({ title, action, onAction, children, style, hint }) {
  return (
    <View style={[{ marginTop: sp.xl }, style]}>
      <View style={s.secHead}>
        <View style={{ flex: 1 }}><Text style={type.h2}>{tr(title)}</Text>{!!hint && <Text style={[type.small, { marginTop: 2 }]}>{hint}</Text>}</View>
        {!!action && <TouchableOpacity onPress={onAction} hitSlop={10}><Text style={{ color: c.primary, fontWeight: '700', fontSize: 13.5 }}>{action}</Text></TouchableOpacity>}
      </View>
      {children}
    </View>)
}

/** tone: ok | warn | danger | info | teal | purple | neutral, or pass a status name (booked, overdue...) as `status`. */
const TONES = {
  ok: [c.okSoft, c.ok], warn: [c.warnSoft, c.warn], danger: [c.dangerSoft, c.danger], info: [c.primarySoft, c.primary],
  teal: [c.tealSoft, c.teal], purple: [c.purpleSoft, c.purple], neutral: [c.line, c.sub],
}
export function Pill({ text, tone = 'neutral', status, style, icon }) {
  const [bg, fg] = status ? (STATUS[status] || TONES.neutral) : TONES[tone] || TONES.neutral
  return (
    <View style={[s.pill, { backgroundColor: bg }, style]}>
      {!!icon && <Ionicons name={icon} size={11} color={fg} style={{ marginRight: 3 }} />}
      <Text style={{ color: fg, fontSize: 11.5, fontWeight: '700' }} numberOfLines={1}>{tr(String(text).replace(/_/g, ' '))}</Text>
    </View>)
}

export function Delta({ value, prev, invert = false, style }) {
  const d = deltaPct(value, prev)
  if (d === null) return <Text style={[s.delta, { color: c.faint }, style]}>new</Text>
  if (d === 0) return <Text style={[s.delta, { color: c.faint }, style]}>no change</Text>
  const good = invert ? d < 0 : d > 0
  return <Text style={[s.delta, { color: good ? c.ok : c.danger }, style]}>{d > 0 ? '▲' : '▼'} {Math.abs(d)}%</Text>
}

/** Headline number tile. */
export function Kpi({ icon, tone = c.primary, label, value, sub, delta, onPress, style, wide }) {
  const inner = (
    <>
      <View style={[s.kIcon, { backgroundColor: tone + '1c' }]}><Ionicons name={icon} size={18} color={tone} /></View>
      <Text style={s.kLabel} numberOfLines={1}>{tr(label)}</Text>
      <Text style={s.kValue} numberOfLines={1} adjustsFontSizeToFit>{value}</Text>
      {delta ? <Delta {...delta} /> : !!sub && <Text style={s.kSub} numberOfLines={1}>{sub}</Text>}
    </>)
  const st = [s.card, s.kpi, wide && { width: '100%' }, style]
  return onPress
    ? <TouchableOpacity activeOpacity={0.8} onPress={() => { tap(); onPress() }} style={st}>{inner}</TouchableOpacity>
    : <View style={st}>{inner}</View>
}

export function Avatar({ name, size = 40, color = c.primary }) {
  return <View style={{ width: size, height: size, borderRadius: size / 2, backgroundColor: color + '1f', alignItems: 'center', justifyContent: 'center' }}><Text style={{ color, fontWeight: '800', fontSize: size * 0.38 }}>{initials(name)}</Text></View>
}

export function Btn({ label, icon, onPress, kind = 'primary', busy, disabled, small, style, color }) {
  const kinds = {
    primary: { bg: c.primary, fg: '#fff', bd: c.primary }, ghost: { bg: c.card, fg: c.text, bd: c.border }, soft: { bg: c.primarySoft, fg: c.primary, bd: c.primarySoft },
    danger: { bg: c.dangerSoft, fg: c.danger, bd: c.dangerSoft }, green: { bg: '#25D366', fg: '#fff', bd: '#25D366' }, ok: { bg: c.ok, fg: '#fff', bd: c.ok },
  }
  const k = kinds[kind]
  const off = busy || disabled
  return (
    <TouchableOpacity activeOpacity={0.8} disabled={off} onPress={() => { tap(); onPress && onPress() }}
      style={[s.btn, { backgroundColor: color || k.bg, borderColor: color || k.bd, paddingVertical: small ? 9 : 13, opacity: off ? 0.55 : 1 }, style]}>
      {busy ? <ActivityIndicator color={k.fg} size="small" /> : <>
        {!!icon && <Ionicons name={icon} size={small ? 15 : 17} color={k.fg} style={{ marginRight: label ? 6 : 0 }} />}
        {!!label && <Text style={{ color: k.fg, fontWeight: '700', fontSize: small ? 13 : 14.5 }}>{tr(label)}</Text>}
      </>}
    </TouchableOpacity>)
}

export function Chip({ label, active, onPress, count, icon }) {
  return (
    <TouchableOpacity activeOpacity={0.8} onPress={() => { tap(); onPress() }} style={[s.chip, active && { backgroundColor: c.primaryDark, borderColor: c.primaryDark }]}>
      {!!icon && <Ionicons name={icon} size={14} color={active ? '#fff' : c.sub} style={{ marginRight: 5 }} />}
      <Text style={{ color: active ? '#fff' : c.text, fontWeight: '600', fontSize: 13 }}>{tr(label)}</Text>
      {count != null && <Text style={{ color: active ? '#cfe0f7' : c.faint, fontWeight: '700', fontSize: 12, marginLeft: 6 }}>{count}</Text>}
    </TouchableOpacity>)
}

export function Row({ icon, tone = c.primary, title, sub, right, onPress, avatar, last, children }) {
  const inner = (
    <View style={[s.row, !last && { borderBottomWidth: StyleSheet.hairlineWidth, borderColor: c.line }]}>
      {avatar ? <Avatar name={avatar} size={38} color={tone} /> : !!icon && <View style={[s.rowIcon, { backgroundColor: tone + '1c' }]}><Ionicons name={icon} size={17} color={tone} /></View>}
      <View style={{ flex: 1 }}>
        <Text style={{ color: c.text, fontWeight: '600', fontSize: 14.5 }} numberOfLines={1}>{title}</Text>
        {!!sub && <Text style={[type.small, { marginTop: 2 }]} numberOfLines={2}>{sub}</Text>}
        {children}
      </View>
      {right}
      {onPress && !right && <Ionicons name="chevron-forward" size={17} color={c.faint} />}
    </View>)
  return onPress ? <TouchableOpacity activeOpacity={0.7} onPress={() => { tap(); onPress() }}>{inner}</TouchableOpacity> : inner
}

export function Progress({ pct = 0, color = c.primary, height = 8, tick, track = '#e8eef7' }) {
  const w = Math.max(0, Math.min(100, Number(pct) || 0))
  return (
    <View style={{ height, borderRadius: height, backgroundColor: track, overflow: 'visible', justifyContent: 'center' }}>
      <View style={{ width: `${w}%`, height: '100%', backgroundColor: color, borderRadius: height }} />
      {tick != null && <View style={{ position: 'absolute', left: `${Math.max(0, Math.min(100, tick))}%`, top: -3, bottom: -3, width: 2, backgroundColor: c.navy, borderRadius: 1, opacity: 0.55 }} />}
    </View>)
}

/** Paired bars per label (e.g. revenue vs cash collected). data = [{ label, a, b }]. */
export function Bars({ data, colorA = c.primary, colorB = c.teal, height = 120 }) {
  const max = Math.max(1, ...data.map(d => Math.max(Number(d.a) || 0, Number(d.b) || 0)))
  return (
    <View style={{ flexDirection: 'row', alignItems: 'flex-end', height: height + 22, gap: 6 }}>
      {data.map((d, i) => (
        <View key={i} style={{ flex: 1, alignItems: 'center' }}>
          <View style={{ height, flexDirection: 'row', alignItems: 'flex-end', gap: 3 }}>
            <View style={{ width: 11, height: Math.max(3, (Number(d.a) / max) * height), backgroundColor: colorA, borderRadius: 4 }} />
            {d.b != null && <View style={{ width: 11, height: Math.max(3, (Number(d.b) / max) * height), backgroundColor: colorB, borderRadius: 4 }} />}
          </View>
          <Text style={{ fontSize: 10.5, color: c.sub, marginTop: 6 }}>{d.label}</Text>
        </View>))}
    </View>)
}

const FILL = { ok: c.ok, info: c.primary, bad: c.danger }
/** Progress towards a monthly target with a tick for "where you would be today on an even pace". p = the server's pace block. */
export function TargetBar({ label, p, money = true, daysLeft, compact = false }) {
  if (!hasTarget(p)) return null
  const fmt = money ? inrFull : String
  const st = targetStatus(p.status)
  const color = FILL[st && st.tone] || c.primary
  return (
    <View style={{ marginTop: compact ? 6 : 14 }} accessibilityRole="progressbar" accessibilityLabel={`${label || 'Progress'}: ${p.pct}% of target`}>
      {!compact && (
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 7 }}>
          <Text style={{ fontWeight: '700', color: c.text, fontSize: 13.5 }}>{label}</Text>
          <Text style={{ color: c.sub, fontSize: 12.5 }}><Text style={{ fontWeight: '800', color: c.text }}>{fmt(p.actual)}</Text> of {fmt(p.target)} · {p.pct}%</Text>
        </View>)}
      <Progress pct={clampPct(p.pct)} color={color} height={compact ? 7 : 10} tick={p.status !== 'achieved' ? clampPct(p.expected_pct) : undefined} />
      {!compact && (
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 7 }}>
          <Text style={{ color, fontWeight: '800', fontSize: 12.5 }}>{st && st.label}</Text>
          <Text style={{ color: c.sub, fontSize: 12.5 }}>{toGoText(p, fmt, daysLeft)}</Text>
        </View>)}
    </View>)
}

export function GradientHeader({ children, style }) {
  return <LinearGradient colors={['#0a1f44', '#0d2c63', '#0f5d9c']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[{ paddingHorizontal: sp.l, paddingBottom: sp.xl }, style]}>{children}</LinearGradient>
}

export function Empty({ icon = 'file-tray-outline', title, text, action, onAction }) {
  return (
    <View style={{ alignItems: 'center', padding: sp.xxl, paddingTop: 48 }}>
      <View style={{ width: 68, height: 68, borderRadius: 34, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center', marginBottom: sp.m }}><Ionicons name={icon} size={30} color={c.primary} /></View>
      <Text style={[type.h3, { textAlign: 'center' }]}>{tr(title)}</Text>
      {!!text && <Text style={[type.small, { textAlign: 'center', marginTop: 6, lineHeight: 18 }]}>{tr(text)}</Text>}
      {!!action && <Btn label={action} onPress={onAction} small style={{ marginTop: sp.l, paddingHorizontal: 22 }} />}
    </View>)
}

export function ErrorBox({ text, onRetry }) {
  return (
    <View style={[s.card, { padding: sp.l, flexDirection: 'row', alignItems: 'center', gap: 10, borderColor: '#f6c9ce', backgroundColor: '#fff8f8' }]}>
      <Ionicons name="alert-circle" size={22} color={c.danger} />
      <Text style={{ flex: 1, color: '#7a1d25' }}>{text}</Text>
      {!!onRetry && <Btn label="Retry" kind="ghost" small onPress={onRetry} />}
    </View>)
}

/** Grey pulsing placeholder while data loads. */
export function Skeleton({ h = 16, w = '100%', r = 8, style }) {
  const o = useRef(new Animated.Value(0.45)).current
  useEffect(() => {
    const a = Animated.loop(Animated.sequence([Animated.timing(o, { toValue: 1, duration: 750, useNativeDriver: true }), Animated.timing(o, { toValue: 0.45, duration: 750, useNativeDriver: true })]))
    a.start(); return () => a.stop()
  }, [o])
  return <Animated.View style={[{ height: h, width: w, borderRadius: r, backgroundColor: '#dfe6f1', opacity: o }, style]} />
}

export function SkeletonList({ n = 4 }) {
  return <View style={{ gap: sp.m, padding: sp.l }}>{Array.from({ length: n }).map((_, i) => <View key={i} style={[s.card, { padding: sp.l, gap: 10 }]}><Skeleton h={16} w="60%" /><Skeleton h={12} w="85%" /><Skeleton h={12} w="40%" /></View>)}</View>
}

/**
 * Height of the on-screen keyboard (0 when closed). Newer Android versions no longer shrink the screen when the keyboard opens, so every
 * form must make room itself: this is what lifts sheets, forms, the chat box and the sign-in form above the keyboard.
 */
export function useKeyboardHeight() {
  const [h, setH] = useState(0)
  useEffect(() => {
    const ios = Platform.OS === 'ios'
    const a = Keyboard.addListener(ios ? 'keyboardWillShow' : 'keyboardDidShow', (e) => setH(e.endCoordinates?.height || 0))
    const b = Keyboard.addListener(ios ? 'keyboardWillHide' : 'keyboardDidHide', () => setH(0))
    return () => { a.remove(); b.remove() }
  }, [])
  return h
}

const ScrollCtx = createContext(null)

/** A scrolling page with pull-to-refresh. */
export function Screen({ children, onRefresh, refreshing, style, contentStyle, header }) {
  const kb = useKeyboardHeight()
  const ref = useRef(null)
  // a field that gets focus scrolls into the visible part of the page (above the keyboard)
  const reveal = useCallback((fieldRef) => {
    setTimeout(() => {
      const sv = ref.current, f = fieldRef.current
      if (!sv || !f || !sv.getInnerViewNode) return
      try { f.measureLayout(sv.getInnerViewNode(), (_x, y) => sv.scrollTo({ y: Math.max(0, y - 120), animated: true }), () => {}) } catch { /* the page simply stays where it is */ }
    }, 120)
  }, [])
  return (
    <ScrollCtx.Provider value={reveal}>
      <ScrollView ref={ref} style={[{ flex: 1, backgroundColor: c.bg }, style]} contentContainerStyle={[{ paddingBottom: 40 + kb }, contentStyle]} showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled" keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'}
        refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={c.primary} colors={[c.primary]} /> : undefined}>
        {header}
        {children}
      </ScrollView>
    </ScrollCtx.Provider>)
}

/** A labelled text field with an inline error. */
export function Input({ label, error, style, hint, onFocus, ...p }) {
  const reveal = useContext(ScrollCtx)
  const box = useRef(null)
  return (
    <View ref={box} style={[{ marginBottom: sp.m }, style]}>
      {!!label && <Text style={[type.label, { marginBottom: 6 }]}>{tr(label)}</Text>}
      <TextInput placeholderTextColor={c.faint} {...p} placeholder={typeof p.placeholder === 'string' ? tr(p.placeholder) : p.placeholder} onFocus={(e) => { if (reveal) reveal(box); if (onFocus) onFocus(e) }} style={[s.input, error && { borderColor: c.danger }, p.multiline && { minHeight: 84, textAlignVertical: 'top' }]} />
      {!!error ? <Text style={{ color: c.danger, fontSize: 12, marginTop: 4 }}>{error}</Text> : !!hint && <Text style={[type.small, { marginTop: 4 }]}>{hint}</Text>}
    </View>)
}

/** A card that slides up from the bottom (new task, new contact...). */
export function Sheet({ visible, onClose, title, children }) {
  const kb = useKeyboardHeight()
  const maxH = Math.max(260, Dimensions.get('window').height - kb - 56)        // never taller than the space above the keyboard
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent navigationBarTranslucent>
      <View style={{ flex: 1, justifyContent: 'flex-end', paddingBottom: kb }}>
        <TouchableOpacity activeOpacity={1} onPress={() => { Keyboard.dismiss(); onClose() }} style={{ ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(10,31,68,0.45)' }} />
        <View style={[s.sheet, { maxHeight: maxH }]}>
          <View style={{ alignSelf: 'center', width: 42, height: 4, borderRadius: 2, backgroundColor: c.border, marginBottom: sp.m }} />
          <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: sp.m }}>
            <Text style={[type.h2, { flex: 1 }]}>{title}</Text>
            <TouchableOpacity onPress={() => { Keyboard.dismiss(); onClose() }} hitSlop={12} accessibilityLabel="Close"><Ionicons name="close" size={26} color={c.sub} /></TouchableOpacity>
          </View>
          <ScrollView keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false} contentContainerStyle={{ paddingBottom: sp.s }}>{children}</ScrollView>
        </View>
      </View>
    </Modal>)
}

/** Floating round action button (bottom right). */
export function Fab({ icon = 'add', onPress, label, bottom = 22 }) {
  return (
    <TouchableOpacity activeOpacity={0.85} onPress={() => { tap(); onPress() }} accessibilityLabel={label} style={[s.fab, { bottom }]}>
      <Ionicons name={icon} size={28} color="#fff" />
    </TouchableOpacity>)
}

export function Money({ children, style }) { return <Text style={[{ fontVariant: ['tabular-nums'], fontWeight: '800', color: c.text }, style]}>{children}</Text> }

const s = StyleSheet.create({
  card: { backgroundColor: c.card, borderRadius: radius.l, borderWidth: StyleSheet.hairlineWidth, borderColor: c.border, ...shadow },
  secHead: { flexDirection: 'row', alignItems: 'flex-end', marginBottom: sp.m, paddingHorizontal: sp.l },
  pill: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', paddingHorizontal: 9, paddingVertical: 3.5, borderRadius: radius.pill },
  delta: { fontSize: 12, fontWeight: '700', marginTop: 2 },
  kpi: { padding: sp.m + 2, width: '48.2%', minHeight: 112 },
  kIcon: { width: 32, height: 32, borderRadius: 10, alignItems: 'center', justifyContent: 'center', marginBottom: 8 },
  kLabel: { fontSize: 12, fontWeight: '600', color: c.sub },
  kValue: { fontSize: 22, fontWeight: '800', color: c.text, marginTop: 2, letterSpacing: -0.4 },
  kSub: { fontSize: 11.5, color: c.faint, marginTop: 2 },
  btn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', borderRadius: radius.m, paddingHorizontal: 16, borderWidth: 1 },
  chip: { flexDirection: 'row', alignItems: 'center', backgroundColor: c.card, borderWidth: 1, borderColor: c.border, paddingHorizontal: 14, paddingVertical: 8, borderRadius: radius.pill },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
  input: { backgroundColor: c.card, borderWidth: 1, borderColor: c.border, borderRadius: radius.m, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, color: c.text },
  sheet: { backgroundColor: c.card, borderTopLeftRadius: 26, borderTopRightRadius: 26, padding: sp.l, paddingBottom: sp.l },
  fab: { position: 'absolute', right: 18, width: 58, height: 58, borderRadius: 29, backgroundColor: c.primary, alignItems: 'center', justifyContent: 'center', ...shadow, elevation: 6 },
  rowIcon: { width: 36, height: 36, borderRadius: 11, alignItems: 'center', justifyContent: 'center' },
})
