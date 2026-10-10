import * as Notifications from 'expo-notifications'
import * as Device from 'expo-device'
import Constants from 'expo-constants'
import * as SecureStore from 'expo-secure-store'
import { Platform } from 'react-native'
import { pushApi } from './api'

// Channel ids MUST equal the backend category keys (PushCategory::ALL) — the server sends `channelId: <category>`.
export const CHANNELS = [
  { id: 'lead',    name: 'New leads & enquiries',    importance: Notifications.AndroidImportance.MAX },
  { id: 'reply',   name: 'Customer replies',         importance: Notifications.AndroidImportance.MAX },
  { id: 'quote',   name: 'Quote opened or accepted', importance: Notifications.AndroidImportance.HIGH },
  { id: 'payment', name: 'Payments',                 importance: Notifications.AndroidImportance.HIGH },
  { id: 'booking', name: 'Bookings & trips',         importance: Notifications.AndroidImportance.DEFAULT },
  { id: 'task',    name: 'Tasks & mentions',         importance: Notifications.AndroidImportance.DEFAULT },
  { id: 'ads',     name: 'Ad campaign alerts',       importance: Notifications.AndroidImportance.DEFAULT },
  { id: 'target',  name: 'Sales target nudges',      importance: Notifications.AndroidImportance.DEFAULT },
  { id: 'digest',  name: 'Morning briefing',         importance: Notifications.AndroidImportance.LOW },
]

// Show alerts while the app is open too (a lead is worth interrupting for). Must answer within 3 seconds.
Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: true }),
})

const TOKEN_KEY = 'tp_expo_push_token'
const PROMPTED_KEY = 'tp_push_prompted'

export async function ensureChannels() {
  if (Platform.OS !== 'android') return
  await Promise.all(CHANNELS.map(c => Notifications.setNotificationChannelAsync(c.id, { name: c.name, importance: c.importance, vibrationPattern: [0, 250, 250, 250] })))
}

/** 'granted' | 'denied' | 'undetermined' */
export async function permissionState() {
  const p = await Notifications.getPermissionsAsync()
  if (p.granted) return 'granted'
  return p.canAskAgain === false || p.status === 'denied' ? 'denied' : 'undetermined'
}

export async function wasPrompted() { return (await SecureStore.getItemAsync(PROMPTED_KEY)) === '1' }
export async function markPrompted() { await SecureStore.setItemAsync(PROMPTED_KEY, '1') }

/**
 * Get this phone's Expo push token and register it with the server.
 * @param {{ask?:boolean}} opts ask=true shows the OS permission dialog (only after OUR explanation — see PushPrompt).
 * @returns {Promise<{state:'registered'|'denied'|'undetermined'|'simulator'|'no_project'|'error', token?:string, error?:string}>}
 */
export async function registerForPush({ ask = false } = {}) {
  try {
    if (!Device.isDevice) return { state: 'simulator' }                      // simulators cannot obtain a push token
    await ensureChannels()                                                    // Android 13+: the permission prompt only appears once a channel exists
    let state = await permissionState()
    if (state !== 'granted' && ask && state === 'undetermined') {
      await Notifications.requestPermissionsAsync({ ios: { allowAlert: true, allowBadge: true, allowSound: true } })
      state = await permissionState()
    }
    if (state !== 'granted') return { state }
    const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId
    if (!projectId) return { state: 'no_project' }
    const token = (await Notifications.getExpoPushTokenAsync({ projectId })).data
    await pushApi.registerDevice({ expo_token: token, platform: Platform.OS, device_name: Device.deviceName || Device.modelName || null, app_version: Constants.expoConfig?.version || null })
    await SecureStore.setItemAsync(TOKEN_KEY, token)
    return { state: 'registered', token }
  } catch (e) {
    return { state: 'error', error: e?.message || String(e) }
  }
}

/** On sign-out: stop this phone receiving the account's alerts (best effort — the server also moves tokens on next login). */
export async function unregisterPush() {
  try {
    const t = await SecureStore.getItemAsync(TOKEN_KEY)
    if (t) { await pushApi.unregisterDevice(t); await SecureStore.deleteItemAsync(TOKEN_KEY) }
    await Notifications.setBadgeCountAsync(0)
  } catch { /* offline: the server drops the token on the next account switch or when Expo reports it dead */ }
}

export const setBadge = (n) => Notifications.setBadgeCountAsync(Math.max(0, Number(n) || 0)).catch(() => {})
export { Notifications }
