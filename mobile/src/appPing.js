import { AppState, Platform } from 'react-native'
import Constants from 'expo-constants'
import * as Device from 'expo-device'
import * as Updates from 'expo-updates'
import { getItem, setItem } from './storage'
import { request } from './api'

const ID_KEY = 'tp_install_id', LAST_KEY = 'tp_ping_at', VER_KEY = 'tp_ping_ver'
const EVERY_MS = 6 * 3600 * 1000

async function installId() {
  let id = await getItem(ID_KEY)
  if (!id || !/^[A-Za-z0-9-]{12,40}$/.test(id)) {
    id = 'i' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 8)
    id = id.slice(0, 36); await setItem(ID_KEY, id)
  }
  return id
}

/**
 * Tells the server this phone opened the app: version, update and device (no personal data, no push token needed), so the admin app can show who
 * still runs an old version. At most every 6 hours, or at once after the app was updated. Failures are silent: this must never get in the way.
 */
export async function pingServer(force = false) {
  try {
    const version = Constants.expoConfig?.version || ''
    const last = Number(await getItem(LAST_KEY)) || 0
    const lastVer = await getItem(VER_KEY)
    const updateId = Updates.updateId ? String(Updates.updateId).replace(/-/g, '').slice(0, 8) : undefined
    const stamp = `${version}|${updateId || ''}`
    if (!force && Date.now() - last < EVERY_MS && lastVer === stamp) return
    await request('POST', '/mobile/ping', { install_id: await installId(), app_version: version, platform: Platform.OS, runtime_version: Updates.runtimeVersion || undefined, update_id: updateId, device_name: Device.deviceName || Device.modelName || undefined })
    await setItem(LAST_KEY, String(Date.now())); await setItem(VER_KEY, stamp)
  } catch { /* offline or signed out: try again next time */ }
}

/** Ping now and whenever the app comes back to the front. Returns the cleanup function. */
export function watchPing() {
  pingServer()
  const sub = AppState.addEventListener('change', st => { if (st === 'active') pingServer() })
  return () => sub.remove()
}
