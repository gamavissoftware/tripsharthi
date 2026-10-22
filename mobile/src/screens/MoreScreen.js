import { View, Text, Linking, Alert, Platform } from 'react-native'
import Constants from 'expo-constants'
import { c, sp, type } from '../theme'
import { Screen, Card, Row, Avatar, Section, Icon, Pill } from '../ui'

const ROLE = { owner: 'Owner', admin: 'Admin', agent: 'Agent' }

/** Everything that does not fit in the five tabs. Owner-only items are hidden from agents (the server enforces it too). */
export default function MoreScreen({ navigation, user, unread = 0, onSignOut }) {
  const manager = user?.role === 'owner' || user?.role === 'admin'
  const go = (n) => () => navigation.navigate(n)
  return (
    <Screen contentStyle={{ paddingTop: sp.l }}>
      <Card style={{ marginHorizontal: sp.l, flexDirection: 'row', alignItems: 'center', gap: 14 }}>
        <Avatar name={user?.name} size={54} />
        <View style={{ flex: 1 }}>
          <Text style={type.h2} numberOfLines={1}>{user?.name || 'Your account'}</Text>
          <Text style={[type.small, { marginTop: 2 }]} numberOfLines={1}>{user?.email}</Text>
          {!!user?.role && <Pill tone="info" text={ROLE[user.role] || user.role} style={{ marginTop: 8 }} />}
        </View>
      </Card>

      <Section title="Your work">
        <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
          <Row icon="checkbox" tone={c.orange} title="Tasks" sub="Follow-ups and reminders" onPress={go('Tasks')} />
          <Row icon="people" tone={c.primary} title="Contacts" sub="Search and add customers" onPress={go('Contacts')} />
          <Row icon="airplane" tone={c.teal} title="Group departures" sub="Seats sold and at-risk tours" onPress={go('Departures')} />
          <Row icon="flag" tone={c.purple} title="Sales targets" sub="Your progress this month" onPress={go('Targets')} last />
        </Card>
      </Section>

      {manager && (
        <Section title="Business">
          <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
            <Row icon="stats-chart" tone={c.ok} title="Business report" sub="Revenue, margin, funnel, cash position" onPress={go('Report')} last />
          </Card>
        </Section>)}

      <Section title="Account">
        <Card pad={false} style={{ marginHorizontal: sp.l, paddingHorizontal: sp.l }}>
          <Row icon="notifications" tone={c.primary} title="Notifications" sub="Leads, replies, payments" onPress={go('Notifications')}
            right={unread > 0 ? <View style={{ backgroundColor: c.orange, minWidth: 22, height: 22, borderRadius: 11, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6 }}><Text style={{ color: '#fff', fontWeight: '800', fontSize: 12 }}>{unread > 99 ? '99+' : unread}</Text></View> : undefined} />
          <Row icon="settings" tone={c.navy} title="Settings" sub="Push, app lock, quiet hours" onPress={go('Settings')} />
          <Row icon="help-buoy" tone={c.teal} title="Help and support" sub="tripsarthi.com/contact" onPress={() => Linking.openURL('https://tripsarthi.com/contact.html').catch(() => Alert.alert('Could not open the link'))} last />
        </Card>
      </Section>

      <View style={{ paddingHorizontal: sp.l, marginTop: sp.xl }}>
        <Card onPress={() => Alert.alert('Sign out?', 'Saved data on this phone will be removed.', [{ text: 'Cancel', style: 'cancel' }, { text: 'Sign out', style: 'destructive', onPress: onSignOut }])} style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, borderColor: '#f3c5ca' }}>
          <Icon name="log-out-outline" size={20} color={c.danger} /><Text style={{ color: c.danger, fontWeight: '800', fontSize: 15 }}>Sign out</Text>
        </Card>
        <Text style={[type.small, { textAlign: 'center', marginTop: sp.l }]}>TripSarthi {Constants.expoConfig?.version || ''} · {Platform.OS === 'android' ? 'Android' : Platform.OS === 'ios' ? 'iPhone' : 'Web preview'}</Text>
      </View>
    </Screen>)
}
