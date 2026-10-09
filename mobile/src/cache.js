import AsyncStorage from '@react-native-async-storage/async-storage'
import { shouldUseCache } from './logic'

const P = 'tpc:'

/**
 * Read-through offline cache. Customer data on a phone is sensitive, so: only lists/details the agent already viewed are kept,
 * and everything is wiped on sign-out (cacheClear). Stale data is always LABELLED to the user (see OfflineBanner), never passed off as live.
 */
export async function cacheSet(key, data) { try { await AsyncStorage.setItem(P + key, JSON.stringify({ t: Date.now(), data })) } catch { /* storage full/unavailable: caching is best-effort */ } }
export async function cacheGet(key) { try { const raw = await AsyncStorage.getItem(P + key); return raw ? JSON.parse(raw) : null } catch { return null } }
export async function cacheClear() {
  try { const keys = (await AsyncStorage.getAllKeys()).filter(k => k.startsWith(P)); if (keys.length) await AsyncStorage.multiRemove(keys) } catch { /* ignore */ }
}

/** @returns {Promise<{data:any, stale:boolean, at:number|null}>} live data, or saved data (stale:true) when the network/server is down. */
export async function cached(key, fetcher) {
  try {
    const data = await fetcher()
    cacheSet(key, data)
    return { data, stale: false, at: Date.now() }
  } catch (e) {
    if (!shouldUseCache(e)) throw e
    const hit = await cacheGet(key)
    if (hit) return { data: hit.data, stale: true, at: hit.t }
    throw e
  }
}
