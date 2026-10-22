const test = require('node:test')
const assert = require('node:assert')
const { compareVersions, updateState, updateLink, mediaKind, fileSize } = require('../src/logic')

test('versions compare as numbers, not text', () => {
  assert.strictEqual(compareVersions('1.9.2', '1.10.0'), -1)
  assert.strictEqual(compareVersions('1.10.0', '1.9.2'), 1)
  assert.strictEqual(compareVersions('1.1.0', '1.1.0'), 0)
  assert.strictEqual(compareVersions('1.1', '1.1.0'), 0)
  assert.strictEqual(compareVersions('2.0.0', '1.99.99'), 1)
  assert.strictEqual(compareVersions('abc', '1.0.0'), 0)
})

test('update state: required below the oldest allowed, available below the newest, otherwise none', () => {
  const info = { latest_version: '1.3.0', min_version: '1.2.0' }
  assert.strictEqual(updateState('1.1.0', info), 'required')
  assert.strictEqual(updateState('1.2.0', info), 'available')
  assert.strictEqual(updateState('1.3.0', info), 'none')
  assert.strictEqual(updateState('1.4.0', info), 'none')
  assert.strictEqual(updateState('1.1.0', { latest_version: null, min_version: null }), 'none')
  assert.strictEqual(updateState('1.1.0', { latest_version: '1.2.0', min_version: null }), 'available')
  assert.strictEqual(updateState('1.1.0', null), 'none')
  assert.strictEqual(updateLink({ android: 'https://x.example/app.apk' }), 'https://x.example/app.apk')
  assert.strictEqual(updateLink({}), null)
})

test('a picked file becomes the right WhatsApp message type', () => {
  assert.strictEqual(mediaKind('image/jpeg'), 'image')
  assert.strictEqual(mediaKind('video/mp4'), 'video')
  assert.strictEqual(mediaKind('audio/ogg'), 'audio')
  assert.strictEqual(mediaKind('application/pdf'), 'document')
  assert.strictEqual(mediaKind(undefined), 'document')
})

test('file sizes read naturally', () => {
  assert.strictEqual(fileSize(340 * 1024), '340 KB')
  assert.strictEqual(fileSize(2.4 * 1048576), '2.4 MB')
  assert.strictEqual(fileSize(10), '1 KB')
  assert.strictEqual(fileSize(0), '')
  assert.strictEqual(fileSize(null), '')
})
