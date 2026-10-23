import { View, Alert } from 'react-native'
import { Text } from '../T'
import { c, sp, type } from '../theme'
import { Screen, Card, Row, Avatar, Section, Btn, Pill, Icon } from '../ui'
import { ADMIN } from '../webRoutes'

/**
 * The TripSarthi team's admin area, on the phone: customers, subscriptions, GST invoices, support, partners, mail and payment settings, the mobile-app
 * page and more. It is the real admin site shown inside the app (own sign-in, separate from any workspace session).
 */
export default function AdminHomeScreen({ navigation, email, hasWorkspace, onWorkspace, onSignOut }) {
  const go = (i) => navigation.navigate('Web', { path: i.path, title: i.label, admin: true })
  return (
    <Screen contentStyle={{ padding: sp.l }}>
      <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
        <Avatar name={email} size={52} color={c.navy} />
        <View style={{ flex: 1 }}>
          <Text style={type.h2} numberOfLines={1}>TripSarthi admin</Text>
          <Text style={[type.small, { marginTop: 2 }]} numberOfLines={1}>{email}</Text>
          <Pill tone="purple" icon="shield-checkmark" text="Platform admin" style={{ marginTop: 8 }} />
        </View>
      </Card>
      <Btn label="Open the overview" icon="speedometer" onPress={() => go({ path: '/admin', label: 'Overview' })} style={{ marginTop: sp.m }} />

      {ADMIN.map(g => (
        <Section key={g.id} title={g.title}>
          <Card pad={false} style={{ paddingHorizontal: sp.l }}>
            {g.items.map((i, n) => <Row key={i.path} icon={i.icon} tone={c.navy} title={i.label} last={n === g.items.length - 1} onPress={() => go(i)} />)}
          </Card>
        </Section>))}

      <View style={{ marginTop: sp.xl, gap: sp.s }}>
        {hasWorkspace && <Btn label="Go to my workspace" icon="swap-horizontal" kind="soft" onPress={onWorkspace} />}
        <Btn label="Sign out of admin" icon="log-out-outline" kind="danger" onPress={() => Alert.alert('Sign out of admin?', 'You will need your admin password to come back.', [{ text: 'Cancel', style: 'cancel' }, { text: 'Sign out', style: 'destructive', onPress: onSignOut }])} />
      </View>
      <Text style={[type.small, { textAlign: 'center', marginTop: sp.l, lineHeight: 18 }]}>Admin sessions end after 2 hours of no use. Every change you make here is recorded.</Text>
    </Screen>)
}
