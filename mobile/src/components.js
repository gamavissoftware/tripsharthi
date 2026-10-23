import { Component } from 'react'
import { View, Linking, Platform } from 'react-native'
import { Text } from './T'
import { c, sp, type } from './theme'
import { Btn, Icon } from './ui'
import { reportError } from './errors'

/** A screen that crashes must not leave a white page: say so in plain words, tell the team, and offer a restart of the screen. */
export class ErrorBoundary extends Component {
  state = { failed: false }
  static getDerivedStateFromError() { return { failed: true } }
  componentDidCatch(error) { reportError(error) }
  render() {
    if (!this.state.failed) return this.props.children
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: sp.xl, backgroundColor: c.bg }}>
        <View style={{ width: 72, height: 72, borderRadius: 36, backgroundColor: c.warnSoft, alignItems: 'center', justifyContent: 'center' }}><Icon name="construct" size={32} color={c.warn} /></View>
        <Text style={[type.h2, { marginTop: sp.l, textAlign: 'center' }]}>Something went wrong</Text>
        <Text style={[type.small, { marginTop: 8, textAlign: 'center', lineHeight: 19 }]}>Sorry about that. The TripSarthi team has been told. Your data is safe.</Text>
        <Btn label="Try again" icon="refresh" onPress={() => this.setState({ failed: false })} style={{ marginTop: sp.xl, alignSelf: 'stretch' }} />
      </View>)
  }
}

/** Shown INSTEAD of the app when this version is older than the oldest the team allows. */
export function UpdateRequired({ link }) {
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: sp.xl, backgroundColor: c.bg }}>
      <View style={{ width: 80, height: 80, borderRadius: 40, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center' }}><Icon name="cloud-download" size={36} color={c.primary} /></View>
      <Text style={[type.h1, { marginTop: sp.l, textAlign: 'center' }]}>Please update TripSarthi</Text>
      <Text style={[type.small, { marginTop: 8, textAlign: 'center', lineHeight: 19 }]}>This version is out of date and can no longer be used. Install the latest version to continue. Your data is safe.</Text>
      {link ? <Btn label="Get the new version" icon="download" onPress={() => Linking.openURL(link).catch(() => {})} style={{ marginTop: sp.xl, alignSelf: 'stretch' }} />
        : <Text style={[type.small, { marginTop: sp.xl, textAlign: 'center' }]}>Ask your TripSarthi contact for the new version.</Text>}
    </View>)
}

/** A gentle note on Home when a newer version exists. */
export function UpdateBanner({ link, version, onDismiss }) {
  return (
    <View style={{ marginHorizontal: sp.l, marginTop: sp.m, backgroundColor: c.primarySoft, borderRadius: 14, padding: 12, flexDirection: 'row', alignItems: 'center', gap: 10 }}>
      <Icon name="sparkles" size={20} color={c.primary} />
      <View style={{ flex: 1 }}><Text style={{ color: c.text, fontWeight: '700' }}>A new version is available</Text><Text style={type.small}>Version {version} has improvements and fixes.</Text></View>
      {link && <Btn label="Update" small onPress={() => Linking.openURL(link).catch(() => {})} />}
      <Text onPress={onDismiss} style={{ color: c.sub, fontSize: 22, paddingHorizontal: 4 }} accessibilityLabel="Dismiss">×</Text>
    </View>)
}
