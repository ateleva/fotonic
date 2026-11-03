// Run: node tests/js/payments-check.mjs
// Checks the JS mirror of Fotonic_Payments::compute() against the case table the PHP test also reads.
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { computeInstallmentRows, requiredAmount, leftToAssign } from '../../src/src/utils/installments.js'

const here = dirname(fileURLToPath(import.meta.url))
const cases = JSON.parse(readFileSync(join(here, '../fixtures/payments-cases.json'), 'utf8'))
let failures = 0
function eq(label, expected, actual) {
  const ok = JSON.stringify(expected) === JSON.stringify(actual)
  if (!ok) { failures++; console.log(`  FAIL ${label}\n       expected: ${JSON.stringify(expected)}\n       actual:   ${JSON.stringify(actual)}`) }
}

for (const c of cases) {
  const types = Object.entries(c.types).map(([slug, pct]) => ({ slug, surcharge_pct: pct }))
  const r = computeInstallmentRows({ rows: c.rows, types, vat: c.vat })
  const e = c.expect
  eq(`${c.name}: total`, e.total, r.total)
  eq(`${c.name}: taxable`, e.taxable, r.taxable)
  eq(`${c.name}: vat`, e.vat, r.vat)
  eq(`${c.name}: to collect`, e.to_collect, r.toCollect)
  eq(`${c.name}: amounts`, e.amounts, r.rows.map((x) => x.amount))
  eq(`${c.name}: taxable flags`, e.taxables, r.rows.map((x) => x.taxable))
}

// What the work asks for: services plus the supplier fees marked "added".
eq('required: services only', 1500, requiredAmount([{ price_override: 500 }, { price_override: 1000 }]).required)
eq('required: 10% added fee', 1650, requiredAmount([{ price_override: 1500 }], [{ id: 1, applied: 'added', fee_type: 'percentage', fee_value: 10 }]).required)
eq('required: a fee marked included is not added', 1500, requiredAmount([{ price_override: 1500 }], [{ id: 1, applied: 'included', fee_type: 'fixed', fee_value: 99 }]).required)
eq('required: fixed fee', 1600, requiredAmount([{ price_override: 1500 }], [{ id: 1, fee_type: 'fixed', fee_value: 100 }]).required)
eq('required: the fee is rounded to cents before it is added (10% of 33.35)', 36.69, requiredAmount([{ price_override: 33.35 }], [{ id: 1, fee_type: 'percentage', fee_value: 10 }]).required)
eq('required: a supplier row without an id is dropped', 1500, requiredAmount([{ price_override: 1500 }], [{ fee_type: 'fixed', fee_value: 100 }]).required)
eq('required: the same supplier counts once', 1600, requiredAmount([{ price_override: 1500 }], [{ id: 7, fee_type: 'fixed', fee_value: 100 }, { id: 7, fee_type: 'fixed', fee_value: 100 }]).required)

// Cents: 333.33 x 3 is short of 1000 by one cent, 333.33 + 333.33 + 333.34 is not.
eq('three times 333.33 leaves a cent', 0.01, leftToAssign(1000, 999.99))
eq('333.33 + 333.33 + 333.34 leaves nothing', 0, leftToAssign(1000, computeInstallmentRows({ rows: [{ base: 333.33 }, { base: 333.33 }, { base: 333.34 }] }).planned))

if (failures) { console.log(`${failures} failure(s)`); process.exit(1) }
console.log('all passed')
