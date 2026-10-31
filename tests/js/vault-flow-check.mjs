import * as f from '../../src/src/utils/vaultFlow.js'
let fail = 0
const eq = (a, b, label) => { const ok = JSON.stringify(a) === JSON.stringify(b); if (!ok) { fail++; console.log('FAIL', label, JSON.stringify(a), '!=', JSON.stringify(b)) } else console.log('  ok  ', label) }

// showChoice
eq(f.showChoice({ setup: false, choice: '' }), true, 'choice: no Vault, never asked')
eq(f.showChoice({ setup: false, choice: 'skipped' }), false, 'choice: not now answered')
eq(f.showChoice({ setup: true, choice: 'enabled' }), false, 'choice: Vault exists')
eq(f.showChoice({ setup: false }), true, 'choice: missing field counts as never asked')
eq(f.showChoice(undefined), false, 'choice: no status yet')

// encryptView
const st = (status, pending = true) => ({ encrypt_pending: pending, encrypt: { status } })
eq(f.encryptView(st('pending'), false), 'modal', 'pending shows the screen')
eq(f.encryptView(st('pending'), true), null, 'pending dismissed: hidden')
eq(f.encryptView(st('running'), false), 'modal', 'running shows the screen')
eq(f.encryptView(st('running'), true), 'modal', 'running is never hidden by the dismissal')
eq(f.encryptView(st('paused'), false), 'bar', 'paused shows the bar')
eq(f.encryptView(st('paused'), true), 'bar', 'paused shows the bar even after a dismissal')
eq(f.encryptView(st('error'), false), 'modal', 'error shows the screen (retry or cancel)')
eq(f.encryptView(st('error'), true), null, 'error dismissed: hidden until reload')
eq(f.encryptView(st('done', false), false), null, 'done: nothing')
eq(f.encryptView(st('none', false), false), null, 'none: nothing')
eq(f.encryptView({ encrypt_pending: false }, false), null, 'not pending: nothing')
eq(f.encryptView(undefined, false), null, 'no status: nothing')

// stepIndex / etaParts
eq(f.PHASES.map(f.stepIndex), [0, 1, 2, 3, 4], 'five phases map to steps 0 to 4')
eq(f.stepIndex('nonsense'), 0, 'unknown phase: first step')
eq(f.etaParts(null), null, 'eta: unknown')
eq(f.etaParts(0), { unit: 'less' }, 'eta: zero is less than a minute')
eq(f.etaParts(59), { unit: 'less' }, 'eta: 59 s')
eq(f.etaParts(60), { unit: 'minutes', n: 1 }, 'eta: 60 s is 1 minute')
eq(f.etaParts(61), { unit: 'minutes', n: 2 }, 'eta: 61 s rounds up to 2')
eq(f.etaParts(NaN), null, 'eta: NaN')

// mergeEncrypt never replaces the whole object
const prev = { setup: true, unlocked: true, kit_ok: false, encrypt_pending: true, encrypt: { status: 'running', percent: 10 } }
eq(f.mergeEncrypt(prev, { status: 'running', percent: 50 }), { ...prev, encrypt: { status: 'running', percent: 50 }, encrypt_pending: true }, 'merge: progress')
eq(f.mergeEncrypt(prev, { status: 'done', percent: 100 }).encrypt_pending, false, 'merge: done closes pending')
eq(f.mergeEncrypt(prev, { status: 'none' }).encrypt_pending, false, 'merge: none closes pending')
eq(f.mergeEncrypt(prev, { status: 'paused' }).encrypt_pending, true, 'merge: paused stays pending')
eq(f.mergeEncrypt(prev, { status: 'running' }).kit_ok, false, 'merge: other fields kept')
eq(f.mergeEncrypt(undefined, { status: 'running' }), undefined, 'merge: no cached status stays empty')

// dismissal storage with and without a working sessionStorage
globalThis.window = { sessionStorage: { _s: {}, getItem(k) { return this._s[k] ?? null }, setItem(k, v) { this._s[k] = v } } }
eq(f.readDismissed(), false, 'dismissed: starts false')
f.writeDismissed()
eq(f.readDismissed(), true, 'dismissed: remembered')
globalThis.window = { sessionStorage: { getItem() { throw new Error('blocked') }, setItem() { throw new Error('blocked') } } }
eq(f.readDismissed(), false, 'dismissed: blocked storage does not throw')
f.writeDismissed()
eq(true, true, 'dismissed: blocked storage write does not throw')
console.log(fail ? fail + ' failure(s)' : 'all passed')
process.exit(fail ? 1 : 0)
