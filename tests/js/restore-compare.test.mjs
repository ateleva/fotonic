// Run: node --test tests/js/restore-compare.test.mjs
// The restore screen: two sides of a difference as readable rows.
import test from 'node:test'
import assert from 'node:assert/strict'
import { register } from 'node:module'

register('data:text/javascript,' + encodeURIComponent(`
export async function resolve(spec, ctx, next) {
  try { return await next(spec, ctx) } catch (e) {
    if (spec.startsWith('.') && !spec.endsWith('.js')) return next(spec + '.js', ctx)
    throw e
  }
}`), new URL('../../src/', import.meta.url))

globalThis.window = { FotonicApp: { locale: 'it_IT', dateFormat: 'd/m/Y' } }
const { compareField } = await import('../../src/src/features/restore/restoreDiff.js')

const pay = (title, base, status = 'unpaid') => ({ title, base, status, type: 'default', taxable: '1', date: '2027-12-24', surcharge: '0', vat: '0', amount: base })
const diff = {
  field: '_ftnc_installments',
  site: [pay('Acconto', '600', 'paid'), pay('Saldo 1', '600'), pay('Saldo 2', '600')],
  backup: [pay('Acconto', '600', 'paid'), pay('Saldo', '1200')],
}

test('payments: equal ones are the same, the others are only on one side', () => {
  const r = compareField(diff, { paymentTypes: { default: 'Bonifico' } })
  assert.deepEqual(r.rows.map((x) => [x.title, x.same, x.only]), [
    ['Acconto', true, null], ['Saldo 1', false, 'site'], ['Saldo 2', false, 'site'], ['Saldo', false, 'backup'],
  ])
  const texts = r.rows[1].lines.map((l) => l.site)
  assert.ok(texts.includes('Bonifico') && texts.includes('Unpaid') && texts.includes('24/12/2027'))
  assert.ok(!texts.includes('unpaid') && !texts.includes('default'))
})

test('same name: only the changed details are marked, a missing one is still listed', () => {
  const old = { title: 'Saldo', status: 'unpaid', amount: '900' }
  const r = compareField({ field: '_ftnc_installments', site: [pay('Saldo', '600')], backup: [old] })
  const changed = r.rows[0].lines.filter((l) => l.changed).map((l) => l.key)
  assert.ok(changed.includes('base') && changed.includes('amount') && !changed.includes('status'))
  assert.equal(r.rows[0].lines.find((l) => l.key === 'base').backup, '')
})

test('a person with another surname and email is still the same person', () => {
  const r = compareField({ field: '_ftnc_people', site: [{ first_name: 'Andrea', last_name: 'Argenti', email: 'a@x.it' }], backup: [{ first_name: 'Andrea', last_name: '', email: 'b@x.it' }] })
  assert.equal(r.rows.length, 1)
  assert.equal(r.rows[0].only, null)
})

test('record ids become names', () => {
  const r = compareField(
    { field: '_ftnc_work_services', site: [{ service_id: '4', price_override: '100' }], backup: [{ service_id: 'new:9', price_override: '100' }] },
    { names: { _ftnc_work_services: { 4: 'Matrimonio' } } },
  )
  assert.equal(r.rows[0].lines[0].site, 'Matrimonio')
  assert.equal(r.rows[1].lines[0].backup, 'One that is only in the backup')
})

test('plain values, card status, switches, unknown and unreadable fields', () => {
  const line = (d) => compareField(d).rows[0].lines[0]
  assert.deepEqual([line({ field: '_ftnc_event_date', site: '2027-01-02', backup: '' }).site, line({ field: '_ftnc_event_date', site: '2027-01-02', backup: '' }).backup], ['02/01/2027', ''])
  const card = line({ field: 'term:ftnc_card_status', site: ['in_use'], backup: ['free'] })
  assert.deepEqual([card.site, card.backup], ['In Use', 'Ready'])
  const off = line({ field: '_ftnc_backup_done', site: '1', backup: '' })
  assert.deepEqual([off.site, off.backup], ['Yes', 'No'])
  assert.equal(compareField({ field: '_ftnc_something', site: '1', backup: '2' }).type, 'hidden')
  assert.equal(compareField({ field: '_ftnc_notes', unreadable: 'backup', site: 'a', backup: null }).type, 'note')
})

test('a Pro table is given as cells', () => {
  window.FotonicProComponents = { restoreFields: (name) => (name === 't' ? { label: 'T', table: (v) => v.cells } : undefined) }
  const r = compareField({ field: 't', site: { cells: [['A', '1']] }, backup: { cells: [['A', '2']] } })
  assert.deepEqual([r.type, r.site, r.backup], ['table', [['A', '1']], [['A', '2']]])
  delete window.FotonicProComponents
})
