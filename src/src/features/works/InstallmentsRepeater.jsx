import { useEffect, useRef, useState } from 'react'
import { Trash2, PlusCircle, ChevronLeft, ChevronRight } from 'lucide-react'
import { __, sprintf } from '../../utils/i18n'
import Button from '../../components/Button'
import RowReorder from '../../components/RowReorder'
import { moveRow } from '../../utils/moveRow'
import Switch from '../../components/Switch'
import { usePaymentTypes } from '../../api/paymentTypes'
import { computeInstallmentRows, eur, leftToAssign, pctForType, NO_VAT } from '../../utils/installments'

const CARD_GAP = 16

const emptyInstallment = () => ({
  title: '',
  base: '',
  taxable: false,
  status: 'unpaid',
  type: 'default',
  date: '',
})

// `required` is what the rows must add up to (services plus supplier fees marked "added"); `vat` is the VAT
// setting of the work (Pro). The admin types only the share of the services price each row covers; the
// surcharge of its payment type, the VAT and what the client pays are computed here and on the server.
export default function InstallmentsRepeater({ value = [], onChange, required = 0, vat = NO_VAT }) {
  const { data: paymentTypes = [] } = usePaymentTypes()

  // The cards sit in a row that scrolls sideways when they do not all fit (three fit when there is room). The
  // arrows in the header move it one card at a time; a new card goes last and the row scrolls to it.
  const scroller = useRef(null)
  const wantEnd = useRef(false)
  const [nav, setNav] = useState({ scrollable: false, left: false, right: false })

  function measure() {
    const el = scroller.current
    if (!el) return
    setNav({
      scrollable: el.scrollWidth > el.clientWidth + 1,
      left: el.scrollLeft > 1,
      right: el.scrollLeft + el.clientWidth < el.scrollWidth - 1,
    })
  }

  useEffect(() => {
    const el = scroller.current
    if (!el) return undefined
    measure()
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(measure) : null
    ro?.observe(el)
    window.addEventListener('resize', measure)
    return () => { ro?.disconnect(); window.removeEventListener('resize', measure) }
  }, [value.length])

  useEffect(() => {
    if (wantEnd.current && scroller.current) {
      wantEnd.current = false
      scroller.current.scrollTo({ left: scroller.current.scrollWidth, behavior: 'smooth' })
    }
    measure()
  }, [value.length])

  function slide(dir) {
    const el = scroller.current
    if (!el) return
    const card = el.firstElementChild
    const step = (card ? card.getBoundingClientRect().width : el.clientWidth) + CARD_GAP
    el.scrollBy({ left: dir * step, behavior: 'smooth' })
  }

  function addRow() {
    wantEnd.current = true
    onChange([...value, emptyInstallment()])
  }

  function moveRowBy(index, dir) {
    onChange(moveRow(value, index, dir))
  }

  function removeRow(index) {
    onChange(value.filter((_, i) => i !== index))
  }

  function updateRow(index, field, val) {
    onChange(value.map((row, i) => (i === index ? { ...row, [field]: val } : row)))
  }

  function toggleStatus(index) {
    const current = value[index].status
    updateRow(index, 'status', current === 'paid' ? 'unpaid' : 'paid')
  }

  const calc = computeInstallmentRows({ rows: value, types: paymentTypes, vat })
  const showTaxable = !!window.FotonicApp?.features?.taxable_price
  const left = leftToAssign(required, calc.planned)

  const totalPaid = calc.rows.reduce((sum, r) => (r.status === 'paid' ? sum + r.amount : sum), 0)
  const totalUnpaid = calc.rows.reduce((sum, r) => (r.status === 'unpaid' ? sum + r.amount : sum), 0)

  return (
    <div className="space-y-4">
      {/* Title, what is paid and unpaid, and (when the cards do not all fit) the arrows that slide them */}
      <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2" style={{ marginTop: 40 }}>
        <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
          <h3 className="text-lg font-semibold text-gray-800 m-0">{__('Installments', 'eleva-crm-for-photographers')}</h3>
          {value.length > 0 && (
            <div className="flex gap-5 text-base">
              <span className="text-green-700 font-medium">{__('Paid', 'eleva-crm-for-photographers')}: {eur(totalPaid)}</span>
              <span className="text-red-700 font-medium">{__('Unpaid', 'eleva-crm-for-photographers')}: {eur(totalUnpaid)}</span>
            </div>
          )}
        </div>
        {nav.scrollable && (
          <div className="flex items-center gap-2">
            <Button type="button" variant="secondary" size="sm" disabled={!nav.left} onClick={() => slide(-1)} aria-label={__('Previous installments', 'eleva-crm-for-photographers')}>
              <ChevronLeft size={16} />
            </Button>
            <Button type="button" variant="secondary" size="sm" disabled={!nav.right} onClick={() => slide(1)} aria-label={__('Next installments', 'eleva-crm-for-photographers')}>
              <ChevronRight size={16} />
            </Button>
          </div>
        )}
      </div>

      {value.length > 0 && (
        // One card per installment, like a receipt, in a row. Cards are a third of the row (never narrower than
        // 300px): when they do not fit, the row scrolls inside itself, so the page never gets a sideways scrollbar.
        <div
          ref={scroller}
          onScroll={measure}
          className="[scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
          style={{ display: 'flex', gap: CARD_GAP, overflowX: 'auto', scrollSnapType: 'x mandatory', width: '100%', maxWidth: '100%', minWidth: 0, paddingBottom: 2 }}
        >
          {value.map((row, index) => {
            const c = calc.rows[index]
            const paid = row.status === 'paid'
            return (
              <div
                key={index}
                className="rounded-lg border border-gray-200 bg-white p-4 flex flex-col gap-3"
                style={{ flex: `0 0 max(300px, calc((100% - ${CARD_GAP * 2}px) / 3))`, scrollSnapAlign: 'start', minWidth: 0, boxSizing: 'border-box' }}
              >
                <div className="flex items-center justify-between">
                  <span className="text-sm font-semibold text-gray-700">{sprintf(__('Installment %s', 'eleva-crm-for-photographers'), index + 1)}</span>
                  <div className="flex items-center gap-1 whitespace-nowrap">
                    <RowReorder index={index} count={value.length} onMove={moveRowBy} horizontal />
                    <Button type="button" variant="danger" size="sm" onClick={() => removeRow(index)} aria-label={__('Remove installment', 'eleva-crm-for-photographers')}>
                      <Trash2 size={14} />
                    </Button>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">{__('Type', 'eleva-crm-for-photographers')}</label>
                    <select
                      value={row.type ?? 'default'}
                      onChange={(e) => updateRow(index, 'type', e.target.value)}
                      className="w-full rounded-md border border-gray-300 pl-2 pr-7 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      {paymentTypes.length > 0
                        ? paymentTypes.map((t) => (
                            // t.label is admin-editable data (fotonic_payment_types option), already
                            // final text by the time it reaches here, not a __()-translatable string.
                            <option key={t.id} value={t.slug}>{t.label}</option>
                          ))
                        : <option value={row.type ?? 'default'}>{row.type ?? 'default'}</option>
                      }
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">{__('Title', 'eleva-crm-for-photographers')}</label>
                    <input
                      type="text"
                      value={row.title}
                      onChange={(e) => updateRow(index, 'title', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder={__('e.g. Deposit', 'eleva-crm-for-photographers')}
                    />
                  </div>
                </div>

                {/* The share and (with Pro) the taxable switch share one row */}
                <div className="grid gap-3 items-end" style={{ gridTemplateColumns: showTaxable ? '1fr auto' : '1fr' }}>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">{__('Share of services (€)', 'eleva-crm-for-photographers')}</label>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={row.base ?? ''}
                      onChange={(e) => updateRow(index, 'base', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder="0.00"
                    />
                  </div>
                  {showTaxable && (
                    <div>
                      <span className="block text-xs font-medium text-gray-600 mb-1">{__('Taxable', 'eleva-crm-for-photographers')}</span>
                      <div className="flex items-center" style={{ height: 34 }}>
                        <Switch
                          checked={c.taxable}
                          disabled={c.surcharge > 0}
                          onChange={(on) => updateRow(index, 'taxable', on)}
                          label={c.surcharge > 0 ? __('A row with the extra percentage is always taxable.', 'eleva-crm-for-photographers') : __('Taxable', 'eleva-crm-for-photographers')}
                        />
                      </div>
                    </div>
                  )}
                </div>
                {/* Always one line tall, even without the extra percentage, so every card has the same height and
                    its figures line up with the others */}
                <p className="text-xs font-medium text-gray-600 m-0" style={{ minHeight: '1rem', lineHeight: '1rem' }}>
                  {c.surcharge > 0
                    ? sprintf(__('%1$s added for the INPS surcharge (%2$s%%)', 'eleva-crm-for-photographers'), eur(c.surcharge), String(pctForType(row.type, paymentTypes)))
                    : '\u00A0'}
                </p>

                <div className="flex items-baseline justify-between border-t border-dashed border-gray-300 pt-3">
                  <span className="text-sm text-gray-600">{__('Client pays', 'eleva-crm-for-photographers')}</span>
                  <span className="text-lg font-semibold text-gray-900 whitespace-nowrap">{eur(c.amount)}</span>
                </div>

                <div className="grid grid-cols-2 gap-3 items-end">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">{__('Date', 'eleva-crm-for-photographers')}</label>
                    <input
                      type="date"
                      value={row.date ?? ''}
                      onChange={(e) => updateRow(index, 'date', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>
                  <button
                    type="button"
                    onClick={() => toggleStatus(index)}
                    className={[
                      'inline-flex items-center justify-center px-2.5 py-2 rounded text-xs font-medium transition-colors',
                      paid ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-red-100 text-red-800 hover:bg-red-200',
                    ].join(' ')}
                  >
                    {paid ? __('Paid', 'eleva-crm-for-photographers') : __('Unpaid', 'eleva-crm-for-photographers')}
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      )}

      <Button type="button" variant="secondary" size="sm" onClick={addRow}>
        <PlusCircle size={14} />
        {__('Add Installment', 'eleva-crm-for-photographers')}
      </Button>

      {Math.abs(left) >= 0.005 && (
        <p className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
          {left > 0
            ? sprintf(__('Left to assign: %s', 'eleva-crm-for-photographers'), eur(left))
            : sprintf(__('Over by %s: lower the shares of the rows.', 'eleva-crm-for-photographers'), eur(-left))}
        </p>
      )}
    </div>
  )
}
