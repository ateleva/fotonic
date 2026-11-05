// Reload-once behaviour of src/utils/lazyImport.js (run: node tests/js/lazy-import-check.mjs from the plugin root).
import { loadWithReload } from '../../src/src/utils/lazyImport.js'

let fails = 0
const ok = (cond, label) => { console.log((cond ? '  ok   ' : '  FAIL ') + label); if (!cond) fails++ }

function fakeWindow(storage = true) {
  const store = {}
  globalThis.window = {
    location: { reloads: 0, reload() { this.reloads++ } },
    sessionStorage: storage
      ? { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = v } }
      : { getItem() { throw new Error('blocked') }, setItem() { throw new Error('blocked') } },
  }
  return globalThis.window
}
const chunkErr = () => Promise.reject(new TypeError('Failed to fetch dynamically imported module: https://x/y.js'))

let w = fakeWindow()
const pending = loadWithReload(chunkErr)
await new Promise((r) => setTimeout(r, 10))
ok(w.location.reloads === 1, 'a missing chunk reloads the page once')
ok(await Promise.race([pending.then(() => 'settled'), new Promise((r) => setTimeout(() => r('waiting'), 30))]) === 'waiting', 'it stays on the loading screen meanwhile')

let err2 = null
await loadWithReload(chunkErr).catch((e) => { err2 = e })
ok(w.location.reloads === 1 && err2 instanceof TypeError, 'a second failure within 30 s shows the error, no reload loop')

w = fakeWindow(); let err3 = null
await loadWithReload(() => Promise.reject(new Error('boom'))).catch((e) => { err3 = e })
ok(err3 && err3.message === 'boom' && w.location.reloads === 0, 'other errors are not swallowed and do not reload')

w = fakeWindow(false); let err4 = null
await loadWithReload(chunkErr).catch((e) => { err4 = e })
ok(w.location.reloads === 0 && err4, 'no usable storage: no reload (cannot guard against a loop)')

for (const m of ['error loading dynamically imported module: x', 'Importing a module script failed.']) {
  w = fakeWindow()
  loadWithReload(() => Promise.reject(new TypeError(m)))
  await new Promise((r) => setTimeout(r, 10))
  ok(w.location.reloads === 1, 'Firefox/Safari wording reloads: ' + m.slice(0, 24))
}

const good = await loadWithReload(() => Promise.resolve({ default: 1 }))
ok(good.default === 1, 'a successful import passes through')
console.log(fails ? fails + ' FAILED' : 'all passed')
process.exit(fails ? 1 : 0)
