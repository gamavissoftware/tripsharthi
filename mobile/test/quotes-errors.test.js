const test = require('node:test')
const assert = require('node:assert')
const { latestQuote, isFlatMarkup, quotePatch, friendlyError } = require('../src/logic')

test('latestQuote picks the highest id even though the API lists oldest first', () => {
  assert.strictEqual(latestQuote([{ id: '83', version: 1 }, { id: '84', version: 2 }]).id, '84')
  assert.strictEqual(latestQuote([{ id: 9 }, { id: 12 }, { id: 10 }]).id, 12)
  assert.strictEqual(latestQuote([]), null)
  assert.strictEqual(latestQuote(undefined), null)
})

test('a blank markup box on a FLAT-markup quote never sends a markup (it used to send 0% and cut the price)', () => {
  const flat = { markup_type: 'flat', markup_value: 500000 }
  const r = quotePatch(flat, '', '2000')
  assert.deepStrictEqual(r.patch, { discount_amount: 200000 })
  assert.ok(!('markup_type' in r.patch) && !('markup_value' in r.patch))
  assert.ok(isFlatMarkup(flat))
})

test('typing a percentage replaces the markup, with limits', () => {
  assert.deepStrictEqual(quotePatch({ markup_type: 'flat' }, '15', '').patch, { discount_amount: 0, markup_type: 'percent', markup_value: 15 })
  assert.ok(quotePatch({ markup_type: 'percent' }, '250', '').error)
  assert.ok(quotePatch({ markup_type: 'percent' }, '-1', '').error)
  assert.ok(quotePatch({ markup_type: 'percent' }, '10', '-5').error)
})

test('a percent quote with the markup box cleared asks for a value instead of sending 0', () => {
  assert.ok(quotePatch({ markup_type: 'percent', markup_value: '12' }, '', '').error)
  assert.strictEqual(quotePatch({ markup_type: 'percent' }, '0', '').patch.markup_value, 0)   // a typed 0 is allowed
})

test('friendlyError turns codes and network failures into words an agent can act on', () => {
  const limit = (type, lim) => friendlyError({ status: 422, message: 'plan_limit_exceeded', data: { messages: { error: 'plan_limit_exceeded', limit_type: type, limit: lim } } })
  assert.match(limit('ai_replies', 0), /does not include AI drafts/)
  assert.match(limit('agents', 1), /limit for team members \(1\) is used up/)
  assert.match(friendlyError({ message: 'Network request failed' }), /Cannot reach TripSarthi/)
  assert.match(friendlyError({ message: 'timed out' }), /taking too long/)
  assert.match(friendlyError({ status: 503, message: '' }), /problem on our side/)
  assert.match(friendlyError({ status: 429 }), /Too many tries/)
  assert.strictEqual(friendlyError({ status: 401, message: 'Invalid email or password.' }), 'Invalid email or password.')
  assert.strictEqual(friendlyError({ status: 422, message: 'Enter a valid phone number.' }), 'Enter a valid phone number.')
  assert.match(friendlyError({ status: 403, code: 'tenant_suspended', message: 'This workspace is suspended.' }), /suspended/)
})
