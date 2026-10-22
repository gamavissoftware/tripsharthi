import { useRef, useState, useEffect, useCallback } from 'react'
import { View, Text, ActivityIndicator, BackHandler, Linking, Platform, TouchableOpacity, StyleSheet } from 'react-native'
import { WebView } from 'react-native-webview'
import { getToken, getAdminToken, adminSessionEnded, biz } from '../api'
import { WEB_URL, ADMIN_URL } from '../config'
import { c, sp, type } from '../theme'
import { Btn, Icon, Empty } from '../ui'

const originOf = (u) => { try { return new URL(u).origin } catch { return u } }

/**
 * Any screen of the TripSarthi web app, shown INSIDE the phone app and already signed in (the same session), with the web menu hidden. This is how
 * setup screens (WhatsApp, integrations, webhooks, billing, GST exports...) are managed without ever opening a browser. Addresses outside the
 * TripSarthi site (Google or Facebook sign-in, documents, links) open in the phone's own browser instead.
 */
export default function WebPageScreen({ route, navigation }) {
  const { path, title, admin } = route.params          // admin = the TripSarthi team's own site (platform admins), with its own session
  const BASE = admin ? ADMIN_URL : WEB_URL
  const ORIGIN = originOf(BASE)
  const ref = useRef(null)
  const [canGoBack, setCanGoBack] = useState(false)
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const token = admin ? getAdminToken() : getToken()

  useEffect(() => { navigation.setOptions({ title: title || 'TripSarthi' }) }, [navigation, title])

  // Android back button / the header arrow: step back inside the web page first, then leave the screen
  const back = useCallback(() => { if (canGoBack && ref.current) { ref.current.goBack(); return true } return false }, [canGoBack])
  useEffect(() => {
    if (Platform.OS !== 'android') return
    const sub = BackHandler.addEventListener('hardwareBackPress', back)
    return () => sub.remove()
  }, [back])
  useEffect(() => {
    navigation.setOptions({ headerLeft: canGoBack ? () => (
      <TouchableOpacity onPress={() => ref.current && ref.current.goBack()} hitSlop={12} style={{ paddingRight: 14 }} accessibilityLabel="Back"><Icon name="arrow-back" size={24} color={c.primary} /></TouchableOpacity>) : undefined })
  }, [canGoBack, navigation])

  if (Platform.OS === 'web') return <Empty icon="desktop" title="Open this on your phone" text="This screen shows the TripSarthi web app inside the mobile app. It is not available in the browser preview." />
  if (!token) return <Empty icon="lock-closed" title="Please sign in again" text="Your session has ended." />

  // the web app reads its session from localStorage: hand it the one this app already has, before the page loads
  const inject = `try { localStorage.setItem('${admin ? 'tp_admin_token' : 'tp_token'}', ${JSON.stringify(token)}); sessionStorage.setItem('ts_embed', '1'); } catch (e) {} true;`
  const uri = `${BASE}/#${path}`

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      {!failed && (
        <WebView
          ref={ref}
          source={{ uri }}
          injectedJavaScriptBeforeContentLoaded={inject}
          javaScriptEnabled domStorageEnabled
          applicationNameForUserAgent="TripSarthiApp/1.1"
          setSupportMultipleWindows={false}
          pullToRefreshEnabled
          allowsBackForwardNavigationGestures
          startInLoadingState={false}
          onLoadStart={() => setLoading(true)}
          onLoadEnd={() => setLoading(false)}
          onError={() => { setFailed(true); setLoading(false) }}
          onHttpError={(e) => { if (e.nativeEvent.statusCode >= 500) { setFailed(true) } }}
          onNavigationStateChange={(s) => setCanGoBack(!!s.canGoBack)}
          onMessage={(e) => {
            try { const m = JSON.parse(e.nativeEvent.data); if (m.type === 'unauthorized') { if (admin) adminSessionEnded(); else biz.me().catch(() => {}) } } catch { /* not ours */ }     // an ended session signs the whole app out
          }}
          onShouldStartLoadWithRequest={(req) => {
            const url = req.url || ''
            // documents and downloads (PDF, CSV, images from the API) cannot be shown by the in-app page: hand them to the phone
            if (url.startsWith(ORIGIN) && /\/api\/v1\//.test(url)) { Linking.openURL(url).catch(() => {}); return false }
            if (url.startsWith(ORIGIN) || url.startsWith('about:') || url.startsWith('blob:') || url.startsWith('data:')) return true
            if (/^(https?|tel|mailto|whatsapp|sms):/i.test(url)) { Linking.openURL(url).catch(() => {}); return false }      // outside TripSarthi: the phone's browser / app
            return false
          }}
          style={{ flex: 1, backgroundColor: c.bg }}
        />)}
      {loading && !failed && <View style={s.loader} pointerEvents="none"><ActivityIndicator color={c.primary} /></View>}
      {failed && (
        <View style={{ flex: 1, justifyContent: 'center' }}>
          <Empty icon="cloud-offline" title="Could not open this screen" text="Check your internet connection and try again." action="Try again" onAction={() => { setFailed(false); setLoading(true) }} />
        </View>)}
    </View>)
}
const s = StyleSheet.create({ loader: { position: 'absolute', top: 14, alignSelf: 'center', backgroundColor: c.card, borderRadius: 20, padding: 10, elevation: 4, shadowColor: '#000', shadowOpacity: 0.12, shadowRadius: 6 } })
