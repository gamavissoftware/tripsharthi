// DEV ONLY - phone-app screenshots (the browser build on :8082 + dev-cors-proxy :8085 + API :8731, showcase tenant) for the website.
// usage: node scripts/capture_mobile.mjs <outdir>
import { spawn } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
const OUT = process.argv[2]; mkdirSync(OUT, { recursive: true })
const T = (await (await fetch('http://localhost:8731/api/v1/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'showcase@tripsarthi.test', password: 'Showcase@12345' }) })).json()).token
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--remote-debugging-port=9334', '--user-data-dir=/tmp/tp-mcapture', '--hide-scrollbars', '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' })
await new Promise(r => setTimeout(r, 7000))
const tab = await (await fetch('http://localhost:9334/json/new?about:blank', { method: 'PUT' })).json()
const ws = new WebSocket(tab.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r)
let id = 0; const pend = new Map()
ws.onmessage = e => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id) } }
const cdp = (method, params = {}) => new Promise(r => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })) })
const sleep = ms => new Promise(r => setTimeout(r, ms))
const ev = (expression) => cdp('Runtime.evaluate', { expression, awaitPromise: true })
await cdp('Page.enable')
const SCHEME = process.argv[3] || 'light'
await cdp('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: SCHEME }] })
await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true })
await cdp('Page.navigate', { url: 'http://localhost:8082/' }); await sleep(2500)
await ev(`localStorage.setItem('tp_token','${T}')`)
await cdp('Page.navigate', { url: 'about:blank' }); await sleep(300)
await cdp('Page.navigate', { url: 'http://localhost:8082/' }); await sleep(6000)
const shot = async (name) => { const r = await cdp('Page.captureScreenshot', { format: 'png' }); writeFileSync(`${OUT}/${name}${SCHEME === 'dark' ? '-dark' : ''}.png`, Buffer.from(r.result.data, 'base64')); console.log('shot', name) }
const click = (txt) => ev(`(()=>{const els=[...document.querySelectorAll('[role=button],[tabindex],a,div')].filter(e=>e.innerText&&e.innerText.trim()===${JSON.stringify(txt)}&&e.getBoundingClientRect().top>600);const e=els[els.length-1];if(e){e.click();return 1}return 0})()`)
await shot('app-home')
await click('Enquiries'); await sleep(4000); await shot('app-enquiries')
await click('Chats'); await sleep(4000); await shot('app-chats')
await ev(`(()=>{const c=[...document.querySelectorAll('div')].filter(e=>e.innerText&&e.innerText.startsWith('Kavya Menon')&&e.innerText.length<80);const e=c[c.length-1];if(e)e.click()})()`); await sleep(4000); await shot('app-chat')
await cdp('Page.navigate', { url: 'about:blank' }); await sleep(300); await cdp('Page.navigate', { url: 'http://localhost:8082/' }); await sleep(5000)
await click('Bookings'); await sleep(4000); await shot('app-bookings')
chrome.kill(); process.exit(0)
