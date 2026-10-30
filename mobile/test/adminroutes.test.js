const test = require('node:test')
const assert = require('node:assert')
const fs = require('node:fs')
const { ADMIN } = require('../src/webRoutes')

test('every screen of the admin site is reachable from the phone', () => {
  const src = fs.readFileSync(__dirname + '/../../frontend/src/admin/AdminApp.jsx', 'utf8')
  const web = [...src.matchAll(/\{ to: '(\/[^']*)',\s*label:/g)].map(m => m[1])
  const phone = new Set(ADMIN.flatMap(g => g.items.map(i => i.path)))
  assert.ok(web.length >= 17)
  assert.deepStrictEqual(web.filter(p => !phone.has(p)), [], 'in the admin menu but not on the phone')
  assert.deepStrictEqual([...phone].filter(p => !web.includes(p)), [], 'on the phone but not in the admin menu')
})
