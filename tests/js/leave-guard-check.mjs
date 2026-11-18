// Run: node tests/js/leave-guard-check.mjs
// The pure part of the "unsaved changes" guard: when leaving a page is stopped, and the fingerprint
// the Pro forms compare to know whether anything changed.
import { fingerprint, shouldBlockLeave } from '../../src/src/utils/leaveGuard.js'

let failures = 0
function eq(label, expected, actual) {
  if (expected !== actual) { failures++; console.log(`  FAIL ${label}\n       expected: ${JSON.stringify(expected)}\n       actual:   ${JSON.stringify(actual)}`) }
}

const a = { pathname: '/customers/12' }
const b = { pathname: '/customers' }

eq('dirty, other page: blocked', true, shouldBlockLeave({ dirty: true, bypass: false, from: a, to: b }))
eq('clean: never blocked', false, shouldBlockLeave({ dirty: false, bypass: false, from: a, to: b }))
eq('after save or delete (bypass): not blocked', false, shouldBlockLeave({ dirty: true, bypass: true, from: a, to: b }))
eq('same page (only the query changes): not blocked', false, shouldBlockLeave({ dirty: true, bypass: false, from: a, to: { pathname: '/customers/12' } }))
eq('missing locations: not blocked', false, shouldBlockLeave({ dirty: true, bypass: false }))

eq('key order does not matter', fingerprint({ a: 1, b: { c: 2, d: [1, { e: 3, f: 4 }] } }), fingerprint({ b: { d: [1, { f: 4, e: 3 }], c: 2 }, a: 1 }))
eq('array order matters', false, fingerprint([1, 2]) === fingerprint([2, 1]))
eq('a changed value is seen', false, fingerprint({ a: 'x' }) === fingerprint({ a: 'y' }))
eq('undefined keys are ignored like JSON does', fingerprint({ a: 1 }), fingerprint({ a: 1, b: undefined }))
eq('null stays distinct from empty text', false, fingerprint({ a: null }) === fingerprint({ a: '' }))

if (failures) { console.log(`${failures} failure(s)`); process.exit(1) }
console.log('all passed')
