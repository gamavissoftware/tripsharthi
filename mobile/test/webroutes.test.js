const test = require('node:test')
const assert = require('node:assert')
const { FEATURES, SETTINGS, visible, search } = require('../src/webRoutes')
const fs = require('node:fs')

test('every web screen is reachable and none is listed twice', () => {
  const all = [...FEATURES, ...SETTINGS].flatMap(g => g.items)
  assert.ok(all.length >= 60)
  const seen = new Set()
  for (const i of all) { assert.match(i.path, /^\/[a-z0-9\-\/]+$/); assert.ok(!seen.has(i.path), 'duplicate ' + i.path); seen.add(i.path); assert.ok(i.label && i.icon) }
})

test('the list matches the web menu (frontend/src/App.jsx): no web screen is missing from the phone', () => {
  const src = fs.readFileSync(__dirname + '/../../frontend/src/App.jsx', 'utf8')
  const web = [...src.matchAll(/\{ to: '(\/[^']+)', label:/g)].map(m => m[1]).filter(p => p !== '/dashboard')
  const phone = new Set([...FEATURES, ...SETTINGS].flatMap(g => g.items.map(i => i.path)))
  const missing = web.filter(p => !phone.has(p))
  assert.deepStrictEqual(missing, [], 'in the web menu but not in the phone menu: ' + missing.join(', '))
})

test('agents are not shown owner-only screens, owners see everything', () => {
  const agentPaths = visible([...FEATURES, ...SETTINGS], false).flatMap(g => g.items.map(i => i.path))
  assert.ok(!agentPaths.includes('/settings/waba') && !agentPaths.includes('/payables') && !agentPaths.includes('/settings/billing'))
  assert.ok(agentPaths.includes('/contacts') && agentPaths.includes('/settings/profile') && agentPaths.includes('/meetings'))
  const ownerCount = visible([...FEATURES, ...SETTINGS], true).flatMap(g => g.items).length
  assert.strictEqual(ownerCount, [...FEATURES, ...SETTINGS].flatMap(g => g.items).length)
})

test('search finds a feature by its name or its group', () => {
  const g = [...FEATURES, ...SETTINGS]
  assert.ok(search(g, 'whatsapp').some(i => i.path === '/settings/waba'))
  assert.ok(search(g, 'webhook').some(i => i.path === '/settings/webhooks'))
  assert.ok(search(g, 'channels').length >= 7)
  assert.deepStrictEqual(search(g, ''), [])
})
