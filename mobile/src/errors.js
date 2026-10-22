import { Platform } from 'react-native'
import Constants from 'expo-constants'
import { appInfo } from './api'
import { navRef } from './nav'

const sent = new Set()          // the same error is reported once per app session, never in a loop

/** Tells the TripSarthi team (admin app -> Mobile app) that something broke on this phone. Never throws, never blocks. */
export function reportError(err, extra = {}) {
  try {
    const message = String((err && err.message) || err || 'Unknown error').slice(0, 300)
    if (sent.has(message) || sent.size > 20) return
    sent.add(message)
    const route = navRef.isReady() ? navRef.getCurrentRoute() : null
    appInfo.report({ message, stack: String((err && err.stack) || '').slice(0, 3500), version: Constants.expoConfig?.version, platform: Platform.OS, screen: route?.name || extra.screen || '' })
  } catch { /* reporting must never cause a second problem */ }
}

/** Catches crashes that happen outside React rendering too (timers, promises that were never handled). */
export function installGlobalErrorReporter() {
  try {
    const eu = global.ErrorUtils
    if (!eu || !eu.setGlobalHandler) return
    const prev = eu.getGlobalHandler && eu.getGlobalHandler()
    eu.setGlobalHandler((e, isFatal) => { reportError(e); if (prev) prev(e, isFatal) })
  } catch { /* ignore */ }
}
