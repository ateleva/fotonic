// Run: node --test tests/js/years.test.mjs (from the plugin root). The year the Works list opens on.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { pickDefaultYear } from '../../src/src/utils/years.js'

test('the current year when it has works', () => {
  assert.equal(pickDefaultYear(['2026', '2025'], 2026), '2026')
})

test('the nearest year when the current one is empty', () => {
  assert.equal(pickDefaultYear(['2025', '2023'], 2026), '2025')
  assert.equal(pickDefaultYear(['2028'], 2026), '2028')
})

test('a tie goes to the past year', () => {
  assert.equal(pickDefaultYear(['2027', '2025'], 2026), '2025')
})

test('no years at all: the current year', () => {
  assert.equal(pickDefaultYear([], 2026), '2026')
  assert.equal(pickDefaultYear(undefined, 2026), '2026')
})
