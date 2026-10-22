import { useState, useMemo } from 'react'
import { View, Text, TextInput, TouchableOpacity, StyleSheet } from 'react-native'
import { c, sp, radius, type } from '../theme'
import { Screen, Card, Row, Icon, Empty } from '../ui'
import { FEATURES, SETTINGS, visible, search } from '../webRoutes'

/**
 * The whole TripSarthi menu in one place. `mode`: 'features' (Travel, CRM, Messaging...) or 'settings' (channels, integrations, workspace, billing).
 * Screens the phone has natively open natively; everything else opens the web page inside the app (see WebPageScreen).
 */
export default function FeaturesScreen({ navigation, route, user }) {
  const mode = route.params?.mode === 'settings' ? 'settings' : 'features'
  const manager = user?.role === 'owner' || user?.role === 'admin'
  const groups = useMemo(() => visible(mode === 'settings' ? SETTINGS : FEATURES, manager), [mode, manager])
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(mode === 'settings' ? groups[0]?.id : null)
  const hits = useMemo(() => search(groups, q), [groups, q])

  function go(i) {
    if (i.native) { i.tab ? navigation.navigate('Home', { screen: i.native }) : navigation.navigate(i.native); return }
    navigation.navigate('Web', { path: i.path, title: i.label })
  }
  const Item = ({ i, last, sub }) => (
    <Row icon={i.icon} tone={c.primary} title={i.label} sub={sub} last={last} onPress={() => go(i)}
      right={i.native ? (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <Text style={{ fontSize: 11, fontWeight: '800', color: c.ok }}>IN APP</Text>
          <TouchableOpacity onPress={() => navigation.navigate('Web', { path: i.path, title: i.label })} hitSlop={10} accessibilityLabel={`Open the full ${i.label} screen`} style={{ padding: 6, borderRadius: 10, backgroundColor: c.primarySoft }}><Icon name="open-outline" size={17} color={c.primary} /></TouchableOpacity>
        </View>) : undefined} />
  )
  return (
    <Screen contentStyle={{ padding: sp.l }}>
      <View style={s.search}>
        <Icon name="search" size={18} color={c.faint} />
        <TextInput value={q} onChangeText={setQ} placeholder={mode === 'settings' ? 'Find a setting, e.g. WhatsApp, webhook' : 'Find a feature, e.g. deals, templates'} placeholderTextColor={c.faint} style={{ flex: 1, fontSize: 15, color: c.text, paddingVertical: 2 }} autoCapitalize="none" />
        {!!q && <TouchableOpacity onPress={() => setQ('')} hitSlop={10}><Icon name="close-circle" size={18} color={c.faint} /></TouchableOpacity>}
      </View>

      {q ? (
        hits.length === 0 ? <Empty icon="search" title="Nothing found" text="Try another word." /> : (
          <Card pad={false} style={{ paddingHorizontal: sp.l, marginTop: sp.m }}>{hits.map((i, n) => <Item key={i.path} i={i} sub={i.group} last={n === hits.length - 1} />)}</Card>)
      ) : groups.map(g => {
        const isOpen = open === g.id
        return (
          <Card key={g.id} pad={false} style={{ marginTop: sp.m, overflow: 'hidden' }}>
            <TouchableOpacity activeOpacity={0.8} onPress={() => setOpen(isOpen ? null : g.id)} style={s.head} accessibilityRole="button" accessibilityLabel={g.title}>
              <View style={s.ico}><Icon name={g.icon} size={19} color={c.primary} /></View>
              <View style={{ flex: 1 }}><Text style={type.h3}>{g.title}</Text><Text style={[type.small, { marginTop: 1 }]}>{g.items.length} screens</Text></View>
              <Icon name={isOpen ? 'chevron-up' : 'chevron-down'} size={20} color={c.faint} />
            </TouchableOpacity>
            {isOpen && <View style={{ paddingHorizontal: sp.l, borderTopWidth: StyleSheet.hairlineWidth, borderColor: c.line }}>{g.items.map((i, n) => <Item key={i.path} i={i} last={n === g.items.length - 1} />)}</View>}
          </Card>)
      })}
      <Text style={[type.small, { textAlign: 'center', marginTop: sp.xl, lineHeight: 18 }]}>Screens marked IN APP are built for the phone; tap the small arrow beside one to open its full version. All the others open the full TripSarthi screen right here in the app, already signed in.</Text>
    </Screen>)
}
const s = StyleSheet.create({
  search: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#fff', borderRadius: radius.l, paddingHorizontal: 14, paddingVertical: 11, borderWidth: 1, borderColor: c.border },
  head: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: sp.l },
  ico: { width: 38, height: 38, borderRadius: 12, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center' },
})
