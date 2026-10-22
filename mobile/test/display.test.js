const test = require('node:test')
const assert = require('node:assert')
const { inrFull, inrShort, deltaPct, fmtDay, daysFrom, dueLabel, indiaToday, greeting, initials, pctOf } = require('../src/logic')

test('inrFull groups the Indian way and keeps the sign in front of the rupee symbol', () => {
  assert.strictEqual(inrFull(12345678), '₹1,23,457')
  assert.strictEqual(inrFull(0), '₹0')
  assert.strictEqual(inrFull(null), '₹0')
  assert.strictEqual(inrFull(-76544034), '-₹7,65,440')
  assert.strictEqual(inrFull('9990000'), '₹99,900')
})

test('inrShort gives compact tile values in lakh and crore', () => {
  assert.strictEqual(inrShort(27828800), '₹2.78 L')       // 2,78,288 rupees
  assert.strictEqual(inrShort(12345), '₹123')
  assert.strictEqual(inrShort(450000), '₹4.5K')
  assert.strictEqual(inrShort(1500000000), '₹1.5 Cr')
  assert.strictEqual(inrShort(-5000000), '-₹50K')
  assert.strictEqual(inrShort(undefined), '₹0')
})

test('deltaPct compares with last month and never divides by zero', () => {
  assert.strictEqual(deltaPct(10, 12), -17)
  assert.strictEqual(deltaPct(4, 2), 100)
  assert.strictEqual(deltaPct(0, 0), 0)
  assert.strictEqual(deltaPct(5, 0), null)          // "new": nothing to compare with
  assert.strictEqual(deltaPct('x', 3), null)
})

test('dates are shown as day and month, with the year only when it is not this year', () => {
  const now = new Date('2026-10-22T10:00:00Z')
  assert.strictEqual(fmtDay('2026-10-30', now), '30 Oct')
  assert.strictEqual(fmtDay('2027-02-06 09:00:00', now), '6 Feb 2027')
  assert.strictEqual(fmtDay('', now), '')
  assert.strictEqual(fmtDay('garbage', now), '')
})

test('due labels count whole days and handle past, today and tomorrow', () => {
  assert.strictEqual(daysFrom('2026-10-24', '2026-10-22'), 2)
  assert.strictEqual(daysFrom('2026-11-01', '2026-10-31'), 1)           // across a month end
  assert.strictEqual(daysFrom('2027-01-01', '2026-12-31'), 1)           // across a year end
  assert.strictEqual(dueLabel('2026-10-22', '2026-10-22'), 'today')
  assert.strictEqual(dueLabel('2026-10-23 10:00:00', '2026-10-22'), 'tomorrow')
  assert.strictEqual(dueLabel('2026-10-21', '2026-10-22'), 'yesterday')
  assert.strictEqual(dueLabel('2026-10-27', '2026-10-22'), 'in 5 days')
  assert.strictEqual(dueLabel('2026-10-08', '2026-10-22'), '14 days ago')
  assert.strictEqual(dueLabel(null, '2026-10-22'), '')
})

test('"today" and the greeting follow India time whatever the phone clock says', () => {
  assert.strictEqual(indiaToday(new Date('2026-10-21T20:00:00Z')), '2026-10-22')   // 01:30 IST next day
  assert.strictEqual(indiaToday(new Date('2026-10-22T10:00:00Z')), '2026-10-22')
  assert.strictEqual(greeting(new Date('2026-10-22T03:00:00Z')), 'Good morning')    // 08:30 IST
  assert.strictEqual(greeting(new Date('2026-10-22T08:00:00Z')), 'Good afternoon')  // 13:30 IST
  assert.strictEqual(greeting(new Date('2026-10-22T14:00:00Z')), 'Good evening')    // 19:30 IST
})

test('initials and percentages are safe for odd input', () => {
  assert.strictEqual(initials('Aarav Deshmukh'), 'AD')
  assert.strictEqual(initials('  priya '), 'P')
  assert.strictEqual(initials(''), '?')
  assert.strictEqual(initials(null), '?')
  assert.strictEqual(pctOf(25, 100), 25)
  assert.strictEqual(pctOf(5, 0), 0)
  assert.strictEqual(pctOf(150, 100), 100)
  assert.strictEqual(pctOf(-5, 100), 0)
})
