import { useState } from 'react'
import { View, Text, TouchableOpacity, TextInput, Platform, StyleSheet } from 'react-native'
import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker'
import { Ionicons } from '@expo/vector-icons'
import { c, sp, radius, type } from './theme'
import { fmtDay } from './logic'

const pad = (n) => String(n).padStart(2, '0')
const toIsoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const toHm = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`
function fromValue(v, mode, initial) {
  const d = new Date()
  if (mode === 'time') { const m = /^(\d{2}):(\d{2})$/.exec(String(v || '')); if (m) d.setHours(Number(m[1]), Number(m[2]), 0, 0); return d }
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v || initial || '')); return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : d
}

/**
 * A tap-to-choose date (or time) field: the phone's own calendar / clock instead of typing "2026-11-05". value is "YYYY-MM-DD" (or "HH:MM" in
 * time mode); onChange gets the same format. In the browser preview it falls back to a plain text box.
 */
export default function DateField({ label, value, onChange, mode = 'date', minimumDate, maximumDate, initial, placeholder, error, hint, clearable = false, style }) {
  const [open, setOpen] = useState(false)       // iOS shows the picker inline under the field
  const label2 = !value ? '' : mode === 'time' ? value : `${fmtDay(value)} ${String(value).slice(0, 4)}`

  function pick(_e, d) {
    if (!d) return
    onChange(mode === 'time' ? toHm(d) : toIsoDate(d))
  }
  function press() {
    if (Platform.OS === 'android') DateTimePickerAndroid.open({ value: fromValue(value, mode, initial), mode, is24Hour: true, minimumDate, maximumDate, onChange: pick })
    else setOpen(o => !o)
  }

  if (Platform.OS === 'web') {
    return (
      <View style={[{ marginBottom: sp.m }, style]}>
        {!!label && <Text style={[type.label, { marginBottom: 6 }]}>{label}</Text>}
        <TextInput value={value || ''} onChangeText={onChange} placeholder={placeholder || (mode === 'time' ? 'HH:MM' : 'YYYY-MM-DD')} placeholderTextColor={c.faint} style={[s.box, { paddingVertical: 12, fontSize: 15, color: c.text }, error && { borderColor: c.danger }]} />
        {!!error && <Text style={{ color: c.danger, fontSize: 12, marginTop: 4 }}>{error}</Text>}
      </View>)
  }
  return (
    <View style={[{ marginBottom: sp.m }, style]}>
      {!!label && <Text style={[type.label, { marginBottom: 6 }]}>{label}</Text>}
      <TouchableOpacity activeOpacity={0.8} onPress={press} style={[s.box, error && { borderColor: c.danger }]} accessibilityRole="button" accessibilityLabel={label || 'Choose'}>
        <Ionicons name={mode === 'time' ? 'time-outline' : 'calendar-outline'} size={19} color={c.sub} />
        <Text style={{ flex: 1, fontSize: 15, color: value ? c.text : c.faint }}>{label2 || placeholder || (mode === 'time' ? 'Choose a time' : 'Choose a date')}</Text>
        {clearable && !!value && <TouchableOpacity onPress={() => onChange('')} hitSlop={10}><Ionicons name="close-circle" size={19} color={c.faint} /></TouchableOpacity>}
      </TouchableOpacity>
      {!!error ? <Text style={{ color: c.danger, fontSize: 12, marginTop: 4 }}>{error}</Text> : !!hint && <Text style={[type.small, { marginTop: 4 }]}>{hint}</Text>}
      {Platform.OS === 'ios' && open && (
        <View style={{ backgroundColor: c.card, borderRadius: radius.m, marginTop: 6, borderWidth: 1, borderColor: c.border }}>
          <DateTimePicker value={fromValue(value, mode, initial)} mode={mode} display={mode === 'time' ? 'spinner' : 'inline'} minimumDate={minimumDate} maximumDate={maximumDate} is24Hour onChange={pick} />
          <TouchableOpacity onPress={() => setOpen(false)} style={{ alignItems: 'center', padding: 12 }}><Text style={{ color: c.primary, fontWeight: '800' }}>Done</Text></TouchableOpacity>
        </View>)}
    </View>)
}
const s = StyleSheet.create({ box: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c.card, borderWidth: 1, borderColor: c.border, borderRadius: radius.m, paddingHorizontal: 14, paddingVertical: 13 } })
