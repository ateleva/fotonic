// Run: node tests/js/titles-check.mjs
// titleOr(): the label a list shows when the server could not read a title (null), never an empty cell.
import { titleOr } from '../../src/src/utils/titles.js'

let failures = 0
function eq(label, expected, actual) {
  if (expected !== actual) { failures++; console.log(`  FAIL ${label}\n       expected: ${JSON.stringify(expected)}\n       actual:   ${JSON.stringify(actual)}`) }
}

eq('null customer', 'Customer 12', titleOr(null, 'customer', 12))
eq('empty work', 'Work 3', titleOr('', 'work', 3))
eq('undefined work', 'Work 9', titleOr(undefined, 'work', 9))
eq('readable stays', 'Rossi', titleOr('Rossi', 'customer', 1))
eq('no id, no trailing space', 'Customer', titleOr(null, 'customer'))
eq('a title that looks like ciphertext is shown as typed', 'f1:abc', titleOr('f1:abc', 'work', 4))

if (failures) { console.log(`${failures} failure(s)`); process.exit(1) }
console.log('all passed')
