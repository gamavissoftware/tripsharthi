import { useEffect, useState, useCallback, useRef } from 'react'
import { ActivityIndicator, View, Text, TouchableOpacity, AppState, StyleSheet } from 'react-native'
import { StatusBar } from 'expo-status-bar'
import { NavigationContainer } from '@react-navigation/native'
import { createNativeStackNavigator } from '@react-navigation/native-stack'
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs'
import { loadToken, clearToken, setUnauthorizedHandler } from './src/api'
import LoginScreen from './src/screens/LoginScreen'
import TripsScreen from './src/screens/TripsScreen'
import TripScreen from './src/screens/TripScreen'
import DuesScreen from './src/screens/DuesScreen'
import BookingScreen from './src/screens/BookingScreen'
import ContactScreen from './src/screens/ContactScreen'
import NotificationsScreen from './src/screens/NotificationsScreen'
import ChatsScreen from './src/screens/ChatsScreen'
import ChatScreen from './src/screens/ChatScreen'
import NewEnquiryScreen from './src/screens/NewEnquiryScreen'
import QuoteScreen from './src/screens/QuoteScreen'
import SettingsScreen from './src/screens/SettingsScreen'
import PushPrompt from './src/screens/PushPrompt'
import { navRef, openTarget, flushPending } from './src/nav'
import { Notifications, registerForPush, unregisterPush, permissionState, wasPrompted, markPrompted, ensureChannels, setBadge } from './src/push'
import { pushApi } from './src/api'
import { c } from './src/theme'
import { cacheClear } from './src/cache'
import { lockEnabled, authenticate } from './src/lock'
import { needsLock } from './src/logic'

const Stack = createNativeStackNavigator()
const Tabs = createBottomTabNavigator()

function Home({ unread, setUnread, chatUnread, setChatUnread, onSignOut }) {
  return (
    <Tabs.Navigator screenOptions={{ tabBarActiveTintColor: c.primary }}>
      <Tabs.Screen name="Enquiries" component={TripsScreen} options={{ tabBarIcon: () => <Text>✈️</Text> }} />
      <Tabs.Screen name="Chats" options={{ tabBarIcon: () => <Text>💬</Text>, tabBarBadge: chatUnread > 0 ? (chatUnread > 99 ? '99+' : chatUnread) : undefined }}>
        {(props) => <ChatsScreen {...props} onUnread={setChatUnread} />}
      </Tabs.Screen>
      <Tabs.Screen name="Dues" component={DuesScreen} options={{ title: 'Payments due', tabBarIcon: () => <Text>💰</Text> }} />
      <Tabs.Screen name="Inbox" options={{ tabBarIcon: () => <Text>🔔</Text>, tabBarBadge: unread > 0 ? (unread > 99 ? '99+' : unread) : undefined }}>
        {(props) => <NotificationsScreen {...props} onUnread={setUnread} />}
      </Tabs.Screen>
      <Tabs.Screen name="Settings" options={{ tabBarIcon: () => <Text>⚙️</Text> }}>{(props) => <SettingsScreen {...props} onSignOut={onSignOut} />}</Tabs.Screen>
    </Tabs.Navigator>
  )
}

export default function App() {
  const [ready, setReady] = useState(false)
  const [authed, setAuthed] = useState(false)
  const [unread, setUnread] = useState(0)
  const [chatUnread, setChatUnread] = useState(0)
  const [locked, setLocked] = useState(false)
  const [covered, setCovered] = useState(false)   // privacy cover while the app is inactive (app switcher snapshot)
  const leftAt = useRef(null)
  const lockOn = useRef(false)
  const [askPush, setAskPush] = useState(false)

  const signOut = useCallback(async () => { await unregisterPush(); await clearToken(); await cacheClear(); setUnread(0); setChatUnread(0); setLocked(false); setAuthed(false) }, [])

  useEffect(() => {
    setUnauthorizedHandler(() => setAuthed(false))
    loadToken().then(t => { setAuthed(!!t); setReady(true) })
  }, [])

  // App lock: cold start locks (when enabled); returning after the grace period locks; the switcher snapshot is covered.
  const unlock = useCallback(async () => { if (await authenticate()) { setLocked(false); setCovered(false) } }, [])
  useEffect(() => {
    if (!authed) return
    let dead = false
    lockEnabled().then(on => { lockOn.current = on; if (!dead && needsLock(on, null)) { setLocked(true); unlock() } })
    const sub = AppState.addEventListener('change', async (st) => {
      if (st === 'active') {
        lockOn.current = await lockEnabled()
        if (needsLock(lockOn.current, leftAt.current ?? Date.now())) { setLocked(true); unlock() } else setCovered(false)
        leftAt.current = null
      } else {
        if (leftAt.current === null) leftAt.current = Date.now()
        if (lockOn.current) setCovered(true)
      }
    })
    return () => { dead = true; sub.remove() }
  }, [authed, unlock])

  // After sign-in: register this phone silently if allowed already; otherwise explain first, THEN ask once.
  useEffect(() => {
    if (!authed) return
    let cancelled = false
    ;(async () => {
      await ensureChannels()
      const st = await permissionState()
      if (st === 'granted') registerForPush()
      else if (st === 'undetermined' && !(await wasPrompted()) && !cancelled) setAskPush(true)
      pushApi.feed().then(r => { setUnread(r.unread); setBadge(r.unread) }).catch(() => {})
    })()
    return () => { cancelled = true }
  }, [authed])

  // Notification taps (app open / background) and the one that launched the app (cold start).
  useEffect(() => {
    if (!authed) return
    const tap = Notifications.addNotificationResponseReceivedListener(r => openTarget(r.notification.request.content.data))
    const arrive = Notifications.addNotificationReceivedListener(() => pushApi.feed().then(r => setUnread(r.unread)).catch(() => {}))
    const token = Notifications.addPushTokenListener(() => registerForPush())            // the OS rotated the token
    Notifications.getLastNotificationResponseAsync().then(r => {
      if (r?.notification) { openTarget(r.notification.request.content.data); Notifications.clearLastNotificationResponseAsync?.() }
    }).catch(() => {})
    return () => { tap.remove(); arrive.remove(); token.remove() }
  }, [authed])

  if (!ready) return <View style={{ flex: 1, justifyContent: 'center' }}><ActivityIndicator /></View>
  return (
    <NavigationContainer ref={navRef} onReady={flushPending}>
      <StatusBar style="dark" />
      {authed ? (
        <Stack.Navigator>
          <Stack.Screen name="Home" options={{ headerShown: false }}>{() => <Home unread={unread} setUnread={setUnread} chatUnread={chatUnread} setChatUnread={setChatUnread} onSignOut={signOut} />}</Stack.Screen>
          <Stack.Screen name="Trip" component={TripScreen} options={{ title: 'Trip' }} />
          <Stack.Screen name="Booking" component={BookingScreen} options={{ title: 'Booking' }} />
          <Stack.Screen name="Contact" component={ContactScreen} options={{ title: 'Contact' }} />
          <Stack.Screen name="Chat" component={ChatScreen} options={{ title: 'Chat' }} />
          <Stack.Screen name="NewEnquiry" component={NewEnquiryScreen} options={{ title: 'New enquiry' }} />
          <Stack.Screen name="Quote" component={QuoteScreen} options={{ title: 'Quote' }} />
        </Stack.Navigator>
      ) : (
        <Stack.Navigator screenOptions={{ headerShown: false }}>
          <Stack.Screen name="Login">{() => <LoginScreen onLogin={() => setAuthed(true)} />}</Stack.Screen>
        </Stack.Navigator>
      )}
      {authed && (locked || covered) && (
        <View style={lk.cover}>
          <Text style={{ fontSize: 40 }}>🔒</Text>
          <Text style={lk.t}>TripSarthi is locked</Text>
          {locked && <>
            <TouchableOpacity style={lk.btn} onPress={unlock}><Text style={{ color: '#fff', fontWeight: '800' }}>Unlock</Text></TouchableOpacity>
            <TouchableOpacity onPress={signOut}><Text style={{ color: c.sub, marginTop: 18 }}>Sign out instead</Text></TouchableOpacity></>}
        </View>)}
      <PushPrompt visible={authed && askPush}
        onEnable={async () => { setAskPush(false); await markPrompted(); registerForPush({ ask: true }) }}
        onLater={async () => { setAskPush(false); await markPrompted() }} />
    </NavigationContainer>
  )
}

const lk = StyleSheet.create({
  cover: { ...StyleSheet.absoluteFillObject, backgroundColor: c.bg, alignItems: 'center', justifyContent: 'center', zIndex: 1000 },
  t: { fontSize: 18, fontWeight: '800', color: c.text, marginVertical: 12 },
  btn: { backgroundColor: c.primary, paddingHorizontal: 36, paddingVertical: 14, borderRadius: 12 },
})
