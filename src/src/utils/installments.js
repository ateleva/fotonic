// Mirror of Fotonic_Payments::compute() / ::check() in includes/class-payments.php. Keep both in lockstep:
// tests/js/payments-check.mjs and tests/integration/test-payments-rework.php run the same case table
// (tests/fixtures/payments-cases.json).
//
// The admin types, on each installment row, only its `base` (its share of the services price). Per row:
//   surcharge = round2(base x pct / 100), pct from the row's payment type (Pro: Rivalsa INPS)
//   taxable   = has a surcharge, or the row toggle
//   vat       = excluded: round2(net x rate); included: net - round2(net / (1 + rate)); none: 0
//   amount    = the cash the client pays for the row
// Money is rounded to cents exactly like the server (absolute value, 15 significant digits, halves up).

export function round2(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return 0
  const cents = Math.round(Number((Math.abs(v) * 100).toPrecision(15)))
  if (cents === 0) return 0
  return (v < 0 ? -cents : cents) / 100
}

export function eur(n) {
  return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(Number(n) || 0)
}

export const NO_VAT = { mode: 'none', rate: 0 }

export function pctForType(slug, types = []) {
  const t = (types ?? []).find((x) => x?.slug === slug)
  return Math.max(0, Math.min(100, Number(t?.surcharge_pct) || 0))
}

// What the work asks for: the services plus the supplier fees marked "added" (percentage fees are taken on the
// services subtotal). Same rule the server applies.
export function requiredAmount(services = [], suppliers = []) {
  const subtotal = (services ?? []).reduce((sum, row) => sum + (parseFloat(row?.price_override) || 0), 0)
  // The server keeps one row per supplier and drops rows without one (sanitize_suppliers): do the same here.
  const seen = new Set()
  const rows = (suppliers ?? []).filter((row) => {
    const id = Number(row?.id)
    if (!id || seen.has(id)) return false
    seen.add(id)
    return true
  })
  const fees = rows.reduce((sum, row) => {
    if ((row?.applied ?? 'added') !== 'added') return sum
    const value = parseFloat(row?.fee_value) || 0
    if (row?.fee_type === 'percentage') return sum + (subtotal * value) / 100
    if (row?.fee_type === 'fixed') return sum + value
    return sum
  }, 0)
  // Fees are rounded to cents on their own before they are added, exactly like the server.
  return { subtotal: round2(subtotal), fees: round2(fees), required: round2(subtotal + round2(fees)) }
}

export function computeInstallmentRows({ rows = [], types = [], vat = NO_VAT }) {
  const mode = vat?.mode === 'excluded' || vat?.mode === 'included' ? vat.mode : 'none'
  const rate = Math.max(0, Math.min(100, Number(vat?.rate) || 0))
  const effMode = rate > 0 ? mode : 'none'
  const r = rate / 100
  let planned = 0, total = 0, taxable = 0, vatSum = 0, collect = 0
  const out = (rows ?? []).map((row) => {
    const base = Math.max(0, round2(parseFloat(row?.base) || 0))
    const surcharge = round2((base * pctForType(row?.type, types)) / 100)
    const net = round2(base + surcharge)
    const isTaxable = surcharge > 0 || !!row?.taxable
    let vatRow = 0
    let amount = net
    if (isTaxable && effMode === 'excluded') {
      vatRow = round2(net * r)
      amount = round2(net + vatRow)
    } else if (isTaxable && effMode === 'included') {
      vatRow = round2(net - round2(net / (1 + r)))
    }
    planned += base
    total += net
    vatSum += vatRow
    collect += amount
    if (isTaxable) taxable += net
    return { ...row, base, surcharge, taxable: isTaxable, vat: vatRow, amount }
  })
  const taxableNet = effMode === 'included' ? taxable - vatSum : taxable
  return {
    rows: out,
    total: round2(total),
    taxable: round2(taxableNet),
    vat: round2(vatSum),
    toCollect: round2(collect),
    planned: round2(planned),
  }
}

// Whole cents, so float noise never hides a real difference.
export function leftToAssign(required, planned) {
  return round2(Math.round(required * 100) / 100 - planned)
}
