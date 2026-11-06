// Run: node --test tests/js/editor-sync.test.mjs (from the plugin root). Decisions behind the notes editor: the caret must never jump.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { shouldPushValue, shouldReport, lastKnown, receiveKnown, pushKnown, ackKnown, KNOWN_HISTORY } from '../../src/src/components/editorSync.js'

const base = { value: '<p>new</p>', current: '<p>old</p>', known: [], focused: false }

test('a real outside change is pushed into an editor that is idle', () => {
  assert.equal(shouldPushValue(base), true)
})

test('never while the editor has the focus, whatever the value', () => {
  assert.equal(shouldPushValue({ ...base, focused: true }), false)
  assert.equal(shouldPushValue({ ...base, focused: true, known: ['<p>other</p>'] }), false)
})

test('never when the editor already holds that HTML', () => {
  assert.equal(shouldPushValue({ ...base, value: '<p>old</p>' }), false)
  assert.equal(shouldPushValue({ ...base, value: '', current: '' }), false)
})

test('never for the echo of the last text the editor reported, even if the editor moved on', () => {
  // The editor reported "<p>ab</p>", the user typed one more character before the render with
  // that value was committed: pushing "<p>ab</p>" back would eat the character and reset the caret.
  assert.equal(shouldPushValue({ value: '<p>ab</p>', current: '<p>abc</p>', known: ['<p>ab</p>'], focused: false }), false)
})

test('never for the echo of an EARLIER text either: several keystrokes can be in flight', () => {
  const known = ['<p>a</p>', '<p>ab</p>', '<p>abc</p>', '<p>abcd</p>']
  assert.equal(shouldPushValue({ value: '<p>ab</p>', current: '<p>abcd</p>', known, focused: false }), false)
  assert.equal(shouldPushValue({ value: '<p>a</p>', current: '<p>abcd</p>', known, focused: false }), false)
})

test('the text the editor started with is an echo too, until a later text was acknowledged', () => {
  // Late effects of the very first render: the value is the start-up text, the editor already moved on.
  assert.equal(shouldPushValue({ value: '<p>start</p>', current: '<p>start!</p>', known: ['<p>start</p>', '<p>start!</p>'], focused: false }), false)
  assert.equal(shouldPushValue({ value: '<p>start</p>', current: '<p>start!</p>', known: ackKnown(['<p>start</p>', '<p>start!</p>'], '<p>start!</p>'), focused: false }), true)
})

test('an echo is recognised even if the editor serialises the same text differently now', () => {
  assert.equal(shouldPushValue({ value: '<p>a</p>', current: '<p>a&nbsp;</p>', known: ['<p>a</p>'], focused: false }), false)
})

test('a text the editor never held or reported is pushed, and so is an old one once the history was reset by a push', () => {
  assert.equal(shouldPushValue({ value: '<p>server</p>', current: '<p>abc</p>', known: ['<p>ab</p>', '<p>abc</p>'], focused: false }), true)
  assert.equal(shouldPushValue({ value: '<p>abc</p>', current: '<p>server</p>', known: [], focused: false }), true)
})

test('an empty string is a value like any other, a non-string reads as empty', () => {
  assert.equal(shouldPushValue({ value: '', current: '<p>x</p>', known: [], focused: false }), true)
  assert.equal(shouldPushValue({ value: null, current: '', known: [], focused: false }), false)
  assert.equal(shouldPushValue({ value: undefined, current: '<p>x</p>', known: [], focused: false }), true)
})

test('the empty-string echo is also an echo', () => {
  assert.equal(shouldPushValue({ value: '', current: '<p>typed again</p>', known: ['<p>t</p>', ''], focused: false }), false)
})

test('a missing history reads as empty', () => {
  assert.equal(shouldPushValue({ value: '<p>a</p>', current: '<p>b</p>', focused: false }), true)
})

test('pushKnown appends, skips a repeat of the last text and keeps only the most recent ones', () => {
  assert.deepEqual(pushKnown([], 'a'), ['a'])
  assert.deepEqual(pushKnown(['a'], 'b'), ['a', 'b'])
  assert.deepEqual(pushKnown(['a', 'b'], 'b'), ['a', 'b'])
  assert.deepEqual(pushKnown(['a', 'b'], 'a'), ['a', 'b', 'a'])
  assert.deepEqual(pushKnown(['a', 'b', 'c'], 'd', 3), ['b', 'c', 'd'])
  assert.deepEqual(pushKnown(undefined, 'a'), ['a'])
  let list = []
  for (let i = 0; i < KNOWN_HISTORY * 3; i++) list = pushKnown(list, `<p>${i}</p>`)
  assert.equal(list.length, KNOWN_HISTORY)
  assert.equal(list[list.length - 1], `<p>${KNOWN_HISTORY * 3 - 1}</p>`)
})

test('ackKnown keeps only the latest text once it came back as the value, and nothing else changes it', () => {
  assert.deepEqual(ackKnown(['a', 'b', 'c'], 'c'), ['c'])
  assert.deepEqual(ackKnown(['a', 'b', 'c'], 'b'), ['a', 'b', 'c'], 'an older echo does not acknowledge the latest text')
  assert.deepEqual(ackKnown(['a', 'b', 'c'], 'x'), ['a', 'b', 'c'])
  assert.deepEqual(ackKnown(['a'], 'a'), ['a'])
  assert.deepEqual(ackKnown([], 'a'), [])
  assert.deepEqual(ackKnown(undefined, 'a'), [])
})

test('after the latest text is acknowledged, an older text is a real outside change again', () => {
  const known = ackKnown(['<p>a</p>', '<p>ab</p>', '<p>abc</p>'], '<p>abc</p>')
  assert.equal(shouldPushValue({ value: '<p>a</p>', current: '<p>abc</p>', known, focused: false }), true)
})

test('type then Backspace before the parent committed: the older text is reported again', () => {
  // The parent holds "<p>A</p>" (committed). The admin types "c": reported, but the render carrying
  // it is not committed yet, so `value` still reads "<p>A</p>". Backspace brings the editor back to
  // "<p>A</p>": comparing with `value` would report nothing and leave the parent on "<p>Ac</p>".
  let known = ['<p>A</p>']
  assert.equal(shouldReport({ html: '<p>Ac</p>', known }), true)
  known = pushKnown(known, '<p>Ac</p>')
  assert.equal(shouldReport({ html: '<p>A</p>', known }), true, 'back to the older text differs from what the parent was last told')
  known = pushKnown(known, '<p>A</p>')
  assert.deepEqual(known, ['<p>A</p>', '<p>Ac</p>', '<p>A</p>'])
  assert.equal(shouldReport({ html: '<p>A</p>', known }), false, 'and now the parent was told')
  // The stale echo of "<p>Ac</p>" is still recognised and never pushed back into the editor.
  assert.equal(shouldPushValue({ value: '<p>Ac</p>', current: '<p>A</p>', known, focused: false }), false)
})

test('identical text reports nothing (arrow keys fire keyup without changing the content)', () => {
  assert.equal(shouldReport({ html: '<p>A</p>', known: ['<p>A</p>'] }), false)
  assert.equal(shouldReport({ html: '<p>A</p>', known: ['<p>x</p>', '<p>A</p>'] }), false)
  assert.equal(shouldReport({ html: '', known: [''] }), false)
})

test('a text that is old but not the latest known one is still a change to report', () => {
  assert.equal(shouldReport({ html: '<p>a</p>', known: ['<p>a</p>', '<p>ab</p>'] }), true)
})

test('with no history yet, any text is reported; lastKnown reads the newest entry', () => {
  assert.equal(shouldReport({ html: '<p>a</p>', known: [] }), true)
  assert.equal(shouldReport({ html: '<p>a</p>' }), true)
  assert.equal(lastKnown([]), undefined)
  assert.equal(lastKnown(undefined), undefined)
  assert.equal(lastKnown(['a', 'b']), 'b')
})

test('a value received from the parent that the editor already holds counts as known', () => {
  // The editor holds "<p>S</p>" although its last report was "<p>A</p>" (e.g. the parent set it).
  const known = receiveKnown(['<p>A</p>'], '<p>S</p>', '<p>S</p>')
  assert.deepEqual(known, ['<p>A</p>', '<p>S</p>'])
  assert.equal(shouldReport({ html: '<p>S</p>', known }), false, 'no echo of what the parent already has')
  assert.equal(shouldReport({ html: '<p>A</p>', known }), true, 'but going back to the older text is reported')
})

test('a value pushed into the editor resets the history to that value, so the pushed text reports nothing', () => {
  const known = ['<p>server</p>']
  assert.equal(shouldReport({ html: '<p>server</p>', known }), false)
  assert.equal(shouldReport({ html: '<p>server!</p>', known }), true)
})

test('receiveKnown leaves the history alone when the editor does not hold the value', () => {
  assert.deepEqual(receiveKnown(['a', 'b'], 'a', 'b'), ['a', 'b'])
  assert.deepEqual(receiveKnown(['a'], 'a', 'a'), ['a'], 'not twice in a row')
  assert.deepEqual(receiveKnown(undefined, 'a', 'x'), [])
})
