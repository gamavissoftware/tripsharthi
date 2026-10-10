/**
 * Lightweight API client for the TripSarthi backend.
 *
 * Reads the Bearer token from localStorage and attaches it to every request.
 * The Vite dev-server proxy forwards /api/* → http://localhost:8081 so no
 * CORS configuration is needed during local development.
 */

/* global __ADMIN_APP__ */
const BASE = '/api/v1'

// The platform-admin app is a separate build (vite.admin.config.js defines __ADMIN_APP__) with its OWN storage key,
// so a customer session and an admin session in the same browser can never overwrite each other.
const TOKEN_KEY = typeof __ADMIN_APP__ !== 'undefined' && __ADMIN_APP__ ? 'tp_admin_token' : 'tp_token'

function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

async function request(method, path, body = null) {
  // A FormData body must NOT carry an explicit Content-Type: the browser has
  // to set it so it can append the multipart boundary. Setting it by hand
  // produces a request PHP parses as empty.
  const isForm  = typeof FormData !== 'undefined' && body instanceof FormData
  const headers = isForm ? {} : { 'Content-Type': 'application/json' }
  const token = getToken()
  if (token) headers['Authorization'] = `Bearer ${token}`

  const options = { method, headers }
  if (body !== null) options.body = isForm ? body : JSON.stringify(body)

  const res = await fetch(`${BASE}${path}`, options)
  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    // CI4 returns validation reasons under `messages` (object of field→text),
    // not a top-level `message`. Surface the first readable reason so toasts
    // show the real cause instead of a bare "HTTP 422".
    const detail = data.messages ?? data.errors ?? null
    let readable = data.message
    if (!readable && detail) {
      readable = typeof detail === 'string'
        ? detail
        : Object.values(detail).find(v => typeof v === 'string')
    }
    const err = new Error(readable ?? `HTTP ${res.status}`)
    err.status = res.status
    err.errors = detail
    err.data = data   // full payload: lets callers show structured details (e.g. every spend-guardrail violation)

    // Auto-logout on 401 — expired token or revoked session
    if (res.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      window.dispatchEvent(new CustomEvent('tp:unauthorized'))
    }

    // Enrich plan-limit errors with a user-friendly message
    if (res.status === 422 && data.errors?.error === 'plan_limit_exceeded') {
      err.message = `Plan limit reached: you've used ${data.errors.current} of ${data.errors.limit} ${data.errors.limit_type?.replace(/_/g, ' ')}. Upgrade your plan to continue.`
      err.isPlanLimit = true
      window.dispatchEvent(new CustomEvent('tp:plan-limit', { detail: data.errors }))
    }

    throw err
  }

  return data
}

/** Fetch a binary (PDF) with the auth header and open it in a new tab. A plain <a href> cannot send the Bearer token. */
export async function openPdf(path) {
  const token = getToken()
  const res = await fetch(`${BASE}${path}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Could not open the document (HTTP ${res.status})`)
  }
  const url = URL.createObjectURL(await res.blob())
  const w = window.open(url, '_blank')
  if (!w) { const a = document.createElement('a'); a.href = url; a.download = 'document.pdf'; a.click() }   // pop-up blocked: download instead
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

/** Authenticated file download (CSV/JSON/XML). A plain <a href> cannot send the Bearer token. */
export async function downloadFile(path, fallbackName = 'download') {
  const token = getToken()
  const res = await fetch(`${BASE}${path}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Download failed (HTTP ${res.status})`)
  }
  const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '')?.[1] || fallbackName
  const url = URL.createObjectURL(await res.blob())
  const a = document.createElement('a'); a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

export const api = {
  get:    (path)        => request('GET',    path),
  post:   (path, body)  => request('POST',   path, body),
  postForm: (path, fd)  => request('POST',   path, fd),
  put:    (path, body)  => request('PUT',    path, body),
  patch:  (path, body)  => request('PATCH',  path, body),
  delete: (path)        => request('DELETE', path),
}

// ------------------------------------------------------------------
// Auth helpers
// ------------------------------------------------------------------

export function saveToken(token) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}

export function isLoggedIn() {
  return Boolean(getToken())
}

/** Fetch a protected image and return an object URL (an <img src> cannot send the Bearer token). Caller revokes it. */
export async function fetchImageUrl(path) {
  const token = getToken()
  const res = await fetch(`${BASE}${path}`, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
  if (!res.ok) throw new Error('Could not load the image')
  return URL.createObjectURL(await res.blob())
}
