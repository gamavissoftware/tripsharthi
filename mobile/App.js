import { useEffect, useState, useCallback, useRef } from 'react'
import { ActivityIndicator, View, TouchableOpacity, AppState, StyleSheet, Platform } from 'react-native'
import { Text } from './src/T'
import { Ionicons } from '@expo/vector-icons'
import { useFonts } from 'expo-font'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { StatusBar } from 'expo-status-bar'
import { NavigationContainer, DefaultTheme, DarkTheme } from '@react-navigation/native'
import { createNativeStackNavigator } from '@react-navigation/native-stack'
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs'
import { loadToken, clearToken, setUnauthorizedHandler, biz, appInfo, loadAdminToken, adminAuth, setAdminUnauthorizedHandler } from './src/api'
import Constants from 'expo-constants'
import { ErrorBoundary, UpdateRequired } from './src/components'
import { installGlobalErrorReporter } from './src/errors'
import LoginScreen from './src/screens/LoginScreen'
import HomeScreen from './src/screens/HomeScreen'
import TripsScreen from './src/screens/TripsScreen'
import BookingsScreen from './src/screens/BookingsScreen'
import TasksScreen from './src/screens/TasksScreen'
import ContactsScreen from './src/screens/ContactsScreen'
import DeparturesScreen from './src/screens/DeparturesScreen'
import ReportScreen from './src/screens/ReportScreen'
import MoreScreen from './src/screens/MoreScreen'
import WebPageScreen from './src/screens/WebPageScreen'
import FeaturesScreen from './src/screens/FeaturesScreen'
import AdminHomeScreen from './src/screens/AdminHomeScreen'
import { Sheet, Input, Btn } from './src/ui'
import TripScreen from './src/screens/TripScreen'
import TargetsScreen from './src/screens/TargetsScreen'
import BookingScreen from './src/screens/BookingScreen'
import DocumentsScreen from './src/screens/DocumentsScreen'
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
import { c, isDark } from './src/theme'
import { tr } from './src/i18n'
import { cacheClear } from './src/cache'
import { outboxClear } from './src/outbox'
import PendingBanner from './src/PendingBanner'
import OtaBanner from './src/OtaBanner'
import { watchPing } from './src/appPing'
import { lockEnabled, authenticate } from './src/lock'
import { needsLock, updateState, updateLink } from './src/logic'

const Stack = createNativeStackNavigator()
const Tabs = createBottomTabNavigator()

const ICON = { Home: ['home', 'home-outline'], Enquiries: ['airplane', 'airplane-outline'], Chats: ['chatbubbles', 'chatbubbles-outline'], Bookings: ['briefcase', 'briefcase-outline'], More: ['grid', 'grid-outline'] }
const navTheme = { ...(isDark ? DarkTheme : DefaultTheme), colors: { ...(isDark ? DarkTheme : DefaultTheme).colors, primary: c.primary, background: c.bg, card: c.card, text: c.text, border: c.border, notification: c.danger } }
const headerOpts = { headerStyle: { backgroundColor: c.card }, headerShadowVisible: false, headerTitleStyle: { fontWeight: '800', color: c.text, fontSize: 18 }, headerTintColor: c.primary }

function Home({ unread, setUnread, chatUnread, setChatUnread, onSignOut, user, update, onOpenAdmin }) {
  return (
    <Tabs.Navigator screenOptions={({ route }) => ({
      ...headerOpts,
      tabBarActiveTintColor: c.primary, tabBarInactiveTintColor: c.faint, tabBarLabelStyle: { fontSize: 11, fontWeight: '700' },
      tabBarStyle: { borderTopColor: c.border, backgroundColor: c.card, height: Platform.OS === 'ios' ? 86 : 70, paddingTop: 8, paddingBottom: Platform.OS === 'ios' ? 28 : 10 },
      tabBarIcon: ({ focused, color }) => <Ionicons name={ICON[route.name][focused ? 0 : 1]} size={24} color={color} />,
    })}>
      <Tabs.Screen name="Home" options={{ headerShown: false, tabBarLabel: tr('Home') }}>{(props) => <HomeScreen {...props} unread={unread} user={user} update={update} />}</Tabs.Screen>
      <Tabs.Screen name="Enquiries" component={TripsScreen} options={{ title: tr('Enquiries') }} />
      <Tabs.Screen name="Chats" options={{ title: tr('WhatsApp chats'), tabBarLabel: tr('Chats'), tabBarBadge: chatUnread > 0 ? (chatUnread > 99 ? '99+' : chatUnread) : undefined, tabBarBadgeStyle: { backgroundColor: c.orange } }}>
        {(props) => <ChatsScreen {...props} onUnread={setChatUnread} />}
      </Tabs.Screen>
      <Tabs.Screen name="Bookings" component={BookingsScreen} options={{ title: tr('Bookings') }} />
      <Tabs.Screen name="More" options={{ title: tr('More'), tabBarBadge: unread > 0 ? (unread > 99 ? '99+' : unread) : undefined, tabBarBadgeStyle: { backgroundColor: c.orange } }}>
        {(props) => <MoreScreen {...props} user={user} unread={unread} onSignOut={onSignOut} onOpenAdmin={onOpenAdmin} />}
      </Tabs.Screen>
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
  const [user, setUser] = useState(null)
  const [adminAuthed, setAdminAuthed] = useState(false)      // signed in as the TripSarthi team (separate from a workspace sign-in)
  const [adminMode, setAdminMode] = useState(false)          // showing the admin area right now
  const [adminEmail, setAdminEmail] = useState('')
  const [askAdmin, setAskAdmin] = useState(false)
  const [adminPw, setAdminPw] = useState('')
  const [adminErr, setAdminErr] = useState('')
  const [adminBusy, setAdminBusy] = useState(false)
  const [info, setInfo] = useState(null)        // newest / oldest allowed version, from the server
  const version = Constants.expoConfig?.version || '0.0.0'
  const update = { state: updateState(version, info), link: updateLink(info), latest: info?.latest_version }
  const [fontsReady] = useFonts(Ionicons.font)

  const signOut = useCallback(async () => { await unregisterPush(); await clearToken(); await cacheClear(); await outboxClear(); await adminAuth.logout(); setAdminAuthed(false); setAdminMode(false); setUnread(0); setChatUnread(0); setLocked(false); setUser(null); setAuthed(false) }, [])

  // is there a newer version? asked at start and whenever the app comes back to the front
  useEffect(() => {
    installGlobalErrorReporter()
    appInfo.get().then(setInfo)
    const sub = AppState.addEventListener('change', st => { if (st === 'active') appInfo.get().then(setInfo) })
    return () => sub.remove()
  }, [])

  useEffect(() => {
    setUnauthorizedHandler(() => setAuthed(false))
    setAdminUnauthorizedHandler(() => { setAdminAuthed(false); setAdminMode(false) })
    Promise.all([loadToken(), loadAdminToken()]).then(([t, a]) => { setAuthed(!!t); if (a) { setAdminAuthed(true); if (!t) setAdminMode(true) } setReady(true) })
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

  useEffect(() => { if (authed) return watchPing() }, [authed])      // tell the server which version this phone runs

  // who is signed in (name, role): drives the greeting and hides owner-only menu items from agents
  useEffect(() => { if (authed) biz.me().then(setUser).catch(() => {}) }, [authed])

  // After sign-in: register this phone silently if allowed already; otherwise explain first, THEN ask once.
  useEffect(() => {
    if (!authed) return
    let cancelled = false
    if (Platform.OS === 'web') { pushApi.feed().then(r => setUnread(r.unread)).catch(() => {}); return }       // browser preview: no push
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
    if (!authed || Platform.OS === 'web') return
    const tap = Notifications.addNotificationResponseReceivedListener(r => openTarget(r.notification.request.content.data))
    const arrive = Notifications.addNotificationReceivedListener(() => pushApi.feed().then(r => setUnread(r.unread)).catch(() => {}))
    const token = Notifications.addPushTokenListener(() => registerForPush())            // the OS rotated the token
    Notifications.getLastNotificationResponseAsync().then(r => {
      if (r?.notification) { openTarget(r.notification.request.content.data); Notifications.clearLastNotificationResponseAsync?.() }
    }).catch(() => {})
    return () => { tap.remove(); arrive.remove(); token.remove() }
  }, [authed])

  const openAdmin = useCallback(() => { if (adminAuthed) setAdminMode(true); else { setAdminPw(''); setAdminErr(''); setAskAdmin(true) } }, [adminAuthed])
  async function adminSignIn() {
    if (!adminPw) { setAdminErr('Enter your admin password.'); return }
    setAdminBusy(true); setAdminErr('')
    try { await adminAuth.login(user?.email || '', adminPw); setAdminEmail(user?.email || ''); setAdminAuthed(true); setAdminMode(true); setAskAdmin(false); setAdminPw('') }
    catch (e) { setAdminErr(e.message) } finally { setAdminBusy(false) }
  }
  async function adminSignOut() { await adminAuth.logout(); setAdminAuthed(false); setAdminMode(false) }

  if (update.state === 'required') return <UpdateRequired link={update.link} />
  if (!ready || !fontsReady) return <View style={{ flex: 1, justifyContent: 'center', backgroundColor: c.bg }}><ActivityIndicator color={c.primary} /></View>
  return (
    <SafeAreaProvider>
    <ErrorBoundary>
    <View style={{ flex: 1 }}>
    <OtaBanner signedIn={authed} />
    <PendingBanner signedIn={authed} />
    <View style={{ flex: 1 }}>
    <NavigationContainer ref={navRef} onReady={flushPending} theme={navTheme}>
      <StatusBar style={isDark ? 'light' : 'dark'} />
      {adminMode && adminAuthed ? (
        <Stack.Navigator screenOptions={{ ...headerOpts, contentStyle: { backgroundColor: c.bg } }}>
          <Stack.Screen name="AdminHome" options={{ title: tr('TripSarthi admin') }}>{(props) => <AdminHomeScreen {...props} email={adminEmail || user?.email || ''} hasWorkspace={authed} onWorkspace={() => setAdminMode(false)} onSignOut={adminSignOut} />}</Stack.Screen>
          <Stack.Screen name="Web" component={WebPageScreen} options={{ title: tr('TripSarthi admin') }} />
        </Stack.Navigator>
      ) : authed ? (
        <Stack.Navigator screenOptions={{ ...headerOpts, contentStyle: { backgroundColor: c.bg } }}>
          <Stack.Screen name="Home" options={{ headerShown: false }}>{() => <Home unread={unread} setUnread={setUnread} chatUnread={chatUnread} setChatUnread={setChatUnread} onSignOut={signOut} user={user} update={update} onOpenAdmin={openAdmin} />}</Stack.Screen>
          <Stack.Screen name="Trip" component={TripScreen} options={{ title: tr('Enquiry') }} />
          <Stack.Screen name="Booking" component={BookingScreen} options={{ title: tr('Booking') }} />
          <Stack.Screen name="Documents" component={DocumentsScreen} options={{ title: tr('Documents') }} />
          <Stack.Screen name="Contact" component={ContactScreen} options={{ title: tr('Contact') }} />
          <Stack.Screen name="Chat" component={ChatScreen} options={{ title: tr('Chat') }} />
          <Stack.Screen name="NewEnquiry" component={NewEnquiryScreen} options={{ title: tr('New enquiry') }} />
          <Stack.Screen name="Quote" component={QuoteScreen} options={{ title: tr('Quote') }} />
          <Stack.Screen name="Web" component={WebPageScreen} options={{ title: tr('TripSarthi') }} />
          <Stack.Screen name="Features" options={({ route }) => ({ title: route.params?.mode === 'settings' ? 'Channels & settings' : 'All features' })}>{(props) => <FeaturesScreen {...props} user={user} />}</Stack.Screen>
          <Stack.Screen name="Tasks" component={TasksScreen} options={{ title: tr('Tasks') }} />
          <Stack.Screen name="Contacts" component={ContactsScreen} options={{ title: tr('Contacts') }} />
          <Stack.Screen name="Departures" component={DeparturesScreen} options={{ title: tr('Group departures') }} />
          <Stack.Screen name="Targets" component={TargetsScreen} options={{ title: tr('Sales targets') }} />
          <Stack.Screen name="Report" component={ReportScreen} options={{ title: tr('Business report') }} />
          <Stack.Screen name="Notifications" options={{ title: tr('Notifications') }}>{(props) => <NotificationsScreen {...props} onUnread={setUnread} />}</Stack.Screen>
          <Stack.Screen name="Settings" options={{ title: tr('Settings') }}>{(props) => <SettingsScreen {...props} onSignOut={signOut} />}</Stack.Screen>
        </Stack.Navigator>
      ) : (
        <Stack.Navigator screenOptions={{ headerShown: false }}>
          <Stack.Screen name="Login">{() => <LoginScreen onLogin={() => setAuthed(true)} onAdminLogin={(email) => { setAdminEmail(email); setAdminAuthed(true); setAdminMode(true) }} />}</Stack.Screen>
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
      <Sheet visible={askAdmin} onClose={() => setAskAdmin(false)} title="TripSarthi admin">
        <Text style={{ color: c.sub, marginBottom: 12, lineHeight: 19 }}>Enter the admin password for {user?.email}. The admin area has its own sign-in.</Text>
        <Input label="Admin password" value={adminPw} onChangeText={t => { setAdminPw(t); setAdminErr('') }} secureTextEntry autoCapitalize="none" error={adminErr} autoFocus onSubmitEditing={adminSignIn} />
        <Btn label="Open the admin area" icon="shield-checkmark" onPress={adminSignIn} busy={adminBusy} />
      </Sheet>
      <PushPrompt visible={authed && askPush}
        onEnable={async () => { setAskPush(false); await markPrompted(); registerForPush({ ask: true }) }}
        onLater={async () => { setAskPush(false); await markPrompted() }} />
    </NavigationContainer>
    </View>
    </View>
    </ErrorBoundary>
    </SafeAreaProvider>
  )
}

const lk = StyleSheet.create({
  cover: { ...StyleSheet.absoluteFillObject, backgroundColor: c.bg, alignItems: 'center', justifyContent: 'center', zIndex: 1000 },
  t: { fontSize: 18, fontWeight: '800', color: c.text, marginVertical: 12 },
  btn: { backgroundColor: c.primary, paddingHorizontal: 36, paddingVertical: 14, borderRadius: 12 },
})
