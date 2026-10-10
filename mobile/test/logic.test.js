const test = require('node:test')
const assert = require('node:assert/strict')
const L = require('../src/logic')

test('cache is used for network/server trouble, never for errors the user must act on', () => {
  assert.equal(L.shouldUseCache(new TypeError('Network request failed')), true)
  assert.equal(L.shouldUseCache(Object.assign(new Error('x'), { status: 503 })), true)
  assert.equal(L.shouldUseCache(Object.assign(new Error('x'), { status: 401 })), false)
  assert.equal(L.shouldUseCache(Object.assign(new Error('x'), { status: 422 })), false)
  assert.equal(L.shouldUseCache(Object.assign(new Error('x'), { status: 404 })), false)
})

test('age labels', () => {
  const now = 1_000_000_000
  assert.equal(L.ageLabel(now - 20_000, now), 'just now')
  assert.equal(L.ageLabel(now - 5 * 60_000, now), '5 min ago')
  assert.equal(L.ageLabel(now - 3 * 3600_000, now), '3 h ago')
  assert.equal(L.ageLabel(now - 24 * 3600_000, now), '1 day ago')
  assert.equal(L.ageLabel(now - 50 * 3600_000, now), '2 days ago')
  assert.equal(L.ageLabel(now + 5000, now), 'just now')   // clock skew never shows a negative age
})

test('app lock timing', () => {
  const now = 10_000_000
  assert.equal(L.needsLock(false, null, now), false)                // feature off
  assert.equal(L.needsLock(true, null, now), true)                  // cold start always locks
  assert.equal(L.needsLock(true, now - 10_000, now), false)         // quick app switch: no prompt
  assert.equal(L.needsLock(true, now - 60_000, now), true)          // away for the grace period
  assert.equal(L.needsLock(true, now - 5 * 60_000, now, 5 * 60_000), true)
})

test('phone validation matches the server', () => {
  for (const ok of ['98765 43210', '+91-9876543210', '09876543210', '919876543210', '+44 20 7123 4567']) assert.equal(L.validPhone(ok), true, ok)
  for (const bad of ['', 'abc', '12345', '5876543210', '98765432101234567890']) assert.equal(L.validPhone(bad), false, bad)
})

test('enquiry validation', () => {
  const today = '2026-10-08'
  assert.deepEqual(L.validateEnquiry({ name: 'Asha', phone: '9876543210', adults: 2, nights: '', start_date: '' }, today), {})
  const e = L.validateEnquiry({ name: ' ', phone: '12', adults: 0, nights: 120, start_date: '2020-01-01' }, today)
  assert.deepEqual(Object.keys(e).sort(), ['adults', 'name', 'nights', 'phone', 'start_date'])
  assert.equal(L.validateEnquiry({ name: 'A', phone: '9876543210', adults: 1, start_date: '2026-10-08' }, today).start_date, undefined)   // today is allowed
})

test('composer follows the 24-hour window', () => {
  assert.equal(L.composerMode({ send_mode: 'free_form', status: 'open' }), 'text')
  assert.equal(L.composerMode({ send_mode: 'template_only', status: 'open' }), 'template')
  assert.equal(L.composerMode({ send_mode: 'free_form', status: 'resolved' }), 'resolved')
  assert.equal(L.composerMode(null), 'none')
  assert.equal(L.windowLabel(0), 'Window closed — templates only')
  assert.equal(L.windowLabel(3600 * 23 + 600), '23 h 10 min left to reply freely')
  assert.equal(L.windowLabel(1500), '25 min left to reply freely')
})

test('template variables are counted by the highest placeholder', () => {
  assert.equal(L.templateVarCount('Hi {{1}}, your {{2}} for {{3}}'), 3)
  assert.equal(L.templateVarCount('No variables'), 0)
  assert.equal(L.templateVarCount('{{ 2 }} only'), 2)
})

test('target bar width never leaves 0-100 and survives bad input', () => {
  assert.equal(L.clampPct(37), 37)
  assert.equal(L.clampPct(130), 100)
  assert.equal(L.clampPct(-5), 0)
  assert.equal(L.clampPct(undefined), 0)
  assert.equal(L.clampPct('abc'), 0)
  assert.equal(L.clampPct('42'), 42)
})

test('target status labels match the server statuses and unknown ones draw nothing', () => {
  assert.deepEqual(L.targetStatus('behind'), { label: 'Behind pace', tone: 'bad' })
  assert.deepEqual(L.targetStatus('achieved'), { label: 'Target hit', tone: 'ok' })
  assert.equal(L.targetStatus('ahead').tone, 'ok')
  assert.equal(L.targetStatus('on_track').tone, 'info')
  assert.equal(L.targetStatus('none'), null)
  assert.equal(L.targetStatus('whatever'), null)
})

test('"to go" text', () => {
  const money = (n) => '₹' + n
  assert.equal(L.toGoText({ to_go: 190000 }, money, 21), '₹190000 to go · 21 days left')
  assert.equal(L.toGoText({ to_go: 2 }, String, 1), '2 to go · 1 day left')
  assert.equal(L.toGoText({ to_go: 0 }, money, 5), 'Over target · 5 days left')
  assert.equal(L.toGoText({ to_go: 10 }, String, 0), '10 to go · last day')
  assert.equal(L.toGoText({ to_go: 10 }, String, undefined), '10 to go')
  assert.equal(L.toGoText(null, String, 3), 'Over target · 3 days left')
})

test('a pace block is drawn only when there is a real target', () => {
  assert.equal(L.hasTarget({ status: 'behind', target: 100 }), true)
  assert.equal(L.hasTarget({ status: 'none', target: 0 }), false)
  assert.equal(L.hasTarget({ status: 'ahead', target: 0 }), false)
  assert.equal(L.hasTarget(null), false)
})
