// DEV ONLY - captures website screenshots from the LOCAL app with headless Chrome over CDP (no dependencies; Node 22).
// usage: node scripts/capture_screens.mjs <outdir> [name ...]     (needs API :8731, app :5917, partner portal :5919, showcase + partner seeds)
import { spawn } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'

const DISMISS = 'tp_onboarding_dismissed'
const OUT = process.argv[2], ONLY = process.argv.slice(3)
mkdirSync(OUT, { recursive: true })
const API = 'http://localhost:8731/api/v1'
const login = async (path, body) => (await (await fetch(API + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json()).token
const T = await login('/auth/login', { email: 'showcase@tripsarthi.test', password: 'Showcase@12345' })
const P = await login('/partner-auth/login', { email: 'anita@partner-showcase.test', password: 'Showcase@12345' })

// name, base url, storage key/token, route, viewport [w,h], full page?, wait ms
const APP = 'http://localhost:5917/', PART = 'http://localhost:5919/'
const SHOTS = JSON.parse(await (await import('node:fs/promises')).readFile(new URL(process.env.SHOTS || './capture_shots.json', import.meta.url), 'utf8'))

const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--remote-debugging-port=9333', '--user-data-dir=/tmp/tp-capture-profile', '--hide-scrollbars', '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' })
await new Promise(r => setTimeout(r, 2500))
const tab = await (await fetch('http://localhost:9333/json/new?about:blank', { method: 'PUT' })).json()
const ws = new WebSocket(tab.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r)
let id = 0; const pend = new Map()
ws.onmessage = e => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id) } }
const cdp = (method, params = {}) => new Promise(r => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })) })
const sleep = ms => new Promise(r => setTimeout(r, ms))
await cdp('Page.enable')

for (const s of SHOTS) {
  if (ONLY.length && !ONLY.includes(s.name)) continue
  const [w, h] = s.vp || [1440, 900]
  const base = s.app === 'partner' ? PART : APP
  const key = s.app === 'partner' ? 'tp_partner_token' : 'tp_token', tok = s.app === 'partner' ? P : T
  await cdp('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: s.scale || 2, mobile: !!s.mobile })
  if (s.url) { await cdp('Page.navigate', { url: s.url }); await sleep(s.wait || 2500); if (s.js) await cdp('Runtime.evaluate', { expression: s.js }); await sleep(800) } else {
  await cdp('Page.navigate', { url: base }); await sleep(1200)
  await cdp('Runtime.evaluate', { expression: `localStorage.setItem('${key}', '${tok}'); localStorage.setItem('${DISMISS}', '1'); ${s.pre || ''}` })
  await cdp('Page.navigate', { url: 'about:blank' }); await sleep(300)
  await cdp('Page.navigate', { url: base + '#' + s.route })
  for (let i = 0; i < 25; i++) { await sleep(600); const r = await cdp('Runtime.evaluate', { expression: `document.body.innerText.length>400 && !/Loading/.test(document.body.innerText)` }); if (r.result.result.value && i > 3) break }
  for (let t = 0; t < 4; t++) {
    const bad = (await cdp('Runtime.evaluate', { expression: `/Too many requests|Failed to load|Could not load/i.test(document.body.innerText)` })).result.result.value
    if (!bad) break
    console.log('rate-limited, retrying', s.name); await sleep(25000)
    await cdp('Page.navigate', { url: 'about:blank' }); await sleep(300); await cdp('Page.navigate', { url: base + '#' + s.route })
    for (let i = 0; i < 20; i++) { await sleep(700); const r = await cdp('Runtime.evaluate', { expression: `document.body.innerText.length>400 && !/Loading/.test(document.body.innerText)` }); if (r.result.result.value && i > 3) break }
  }
  await sleep(s.wait ? s.wait - 3000 : 600)
  await cdp('Runtime.evaluate', { expression: `(()=>{const w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);let n;while(n=w.nextNode()){if(n.nodeValue.includes('http://localhost:5917'))n.nodeValue=n.nodeValue.replace('http://localhost:5917','https://app.tripsarthi.com')}; [...document.querySelectorAll('div,p')].filter(e=>/^Demo mode/.test(e.textContent)&&e.textContent.length<200).forEach(e=>e.style.display='none'); document.querySelectorAll('div,span,code,input').forEach(e=>{if(e.children.length===0&&e.textContent.includes('localhost:8731'))e.textContent=e.textContent.replace('http://localhost:8731','https://app.tripsarthi.com')})})()` })
  if (s.js) { await cdp('Runtime.evaluate', { expression: s.js, awaitPromise: true }); await sleep(1500) }
  }
  const params = { format: 'png' }
  if (s.full) { const m = (await cdp('Page.getLayoutMetrics')).result; const hh = Math.min(Math.ceil(m.cssContentSize.height), s.maxH || 3000); params.clip = { x: 0, y: 0, width: w, height: hh, scale: 1 }; params.captureBeyondViewport = true }
  const r = await cdp('Page.captureScreenshot', params)
  writeFileSync(`${OUT}/${s.name}.png`, Buffer.from(r.result.data, 'base64')); console.log('shot', s.name)
}
chrome.kill(); process.exit(0)
