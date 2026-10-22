import AsyncStorage from '@react-native-async-storage/async-storage'
import { request } from './api'
import { outboxVerdict, newIdemKey } from './logic'

const KEY = 'tpq:outbox'
const FAILED = 'tpq:failed'
let listeners = []
let flushing = false

/** What can be saved on the phone while there is no signal, and where it goes once there is. */
const KINDS = {
  task: { method: 'POST', path: '/tasks', label: 'Task' },
  contact: { method: 'POST', path: '/contacts', label: 'Contact' },
  enquiry: { method: 'POST', path: '/trips/quick', label: 'Enquiry' },
}

async function read(k) { try { const raw = await AsyncStorage.getItem(k); return raw ? JSON.parse(raw) : [] } catch { return [] } }
async function write(k, v) { try { await AsyncStorage.setItem(k, JSON.stringify(v)) } catch { /* storage full: the item is lost only if the app is killed before it sends */ } }
const tell = async () => { const n = await outboxCount(); listeners.forEach(f => { try { f(n) } catch { /* a screen unmounted */ } }) }

export const onOutboxChange = (fn) => { listeners.push(fn); return () => { listeners = listeners.filter(x => x !== fn) } }
export async function outboxCount() { return (await read(KEY)).length }
export async function outboxItems() { return read(KEY) }
export async function outboxFailed() { return read(FAILED) }
export async function outboxDismissFailed() { await write(FAILED, []); await tell() }
export async function outboxClear() { try { await AsyncStorage.multiRemove([KEY, FAILED]) } catch { /* ignore */ } await tell() }

/**
 * Sends a create-request now; if the phone has no signal, saves it and sends it later. Returns { queued:false, data } or { queued:true }.
 * The same Idempotency-Key is used for every attempt, so a retry after a dropped connection can never create a second copy.
 */
export async function sendOrQueue(kind, body, summary) {
  const def = KINDS[kind]; if (!def) throw new Error('Unknown item')
  const key = newIdemKey()
  try {
    const r = await request(def.method, def.path, body, { 'Idempotency-Key': key })
    return { queued: false, data: r.data }
  } catch (e) {
    if (outboxVerdict(e) !== 'retry' || e.status === 409) throw e       // the server answered "no": the person must fix it, do not hide it in a queue
    const items = await read(KEY)
    items.push({ id: key, kind, body, summary: summary || def.label, at: Date.now() })
    await write(KEY, items); await tell()
    return { queued: true }
  }
}

/** Tries to send everything saved, oldest first. Stops at the first sign the network is still down. Safe to call often. */
export async function flushOutbox() {
  if (flushing) return { sent: 0, left: await outboxCount() }
  flushing = true; let sent = 0
  try {
    for (;;) {
      const items = await read(KEY); if (!items.length) break
      const it = items[0]; const def = KINDS[it.kind]
      if (!def) { await write(KEY, items.slice(1)); continue }
      try {
        await request(def.method, def.path, it.body, { 'Idempotency-Key': it.id })
        await write(KEY, (await read(KEY)).filter(x => x.id !== it.id)); sent++
      } catch (e) {
        const v = outboxVerdict(e)
        if (e.status === 409) {                                           // "first copy still processing" - ask a few times, then give up
          const cur = await read(KEY); const i = cur.findIndex(x => x.id === it.id)
          if (i >= 0 && (cur[i].tries || 0) < 4) { cur[i].tries = (cur[i].tries || 0) + 1; await write(KEY, cur); break }
        } else if (v === 'auth' || v === 'retry') break
        // the server refused it for good (for example a number it does not accept): keep a note so the person is told, then move on
        await write(KEY, (await read(KEY)).filter(x => x.id !== it.id))
        const failed = await read(FAILED); failed.push({ ...it, error: e.message }); await write(FAILED, failed.slice(-20))
      }
    }
  } finally { flushing = false; await tell() }
  return { sent, left: await outboxCount() }
}
