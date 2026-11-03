import FormField from '../../components/FormField'
import { __ } from '../../utils/i18n'
import { eur } from '../../utils/installments'

const readOnly = 'rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600 w-48 cursor-not-allowed'

function ReadOnlyAmount({ id, label, value, hint }) {
  return (
    <FormField label={label} htmlFor={id}>
      <input id={id} type="text" readOnly tabIndex={-1} value={eur(value)} className={readOnly} />
      {hint && <p className="text-xs text-gray-500 mt-1 max-w-[12rem]">{hint}</p>}
    </FormField>
  )
}

// What the installments must add up to, then the figures that come out of them. None of these is typed by
// the admin: they are worked out from the installment rows (see utils/installments.js).
export default function PaymentsSummary({ subtotal, fees, required, calc, vat, showTaxable }) {
  const hasVat = (vat?.mode === 'excluded' || vat?.mode === 'included') && Number(vat?.rate) > 0
  return (
    <div className="space-y-8">
      <div className="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 max-w-md">
        <div className="flex justify-between gap-6">
          <span>{__('Services', 'eleva-crm-for-photographers')}</span>
          <span>{eur(subtotal)}</span>
        </div>
        {fees > 0 && (
          <div className="flex justify-between gap-6">
            <span>{__('Supplier fees added to the price', 'eleva-crm-for-photographers')}</span>
            <span>{eur(fees)}</span>
          </div>
        )}
        <div className="flex justify-between gap-6 border-t border-gray-200 mt-2 pt-2 font-semibold text-gray-900">
          <span>{__('The installments must cover', 'eleva-crm-for-photographers')}</span>
          <span>{eur(required)}</span>
        </div>
      </div>

      <div className="flex flex-wrap gap-6">
        <ReadOnlyAmount
          id="total_price"
          label={__('Total Price (€)', 'eleva-crm-for-photographers')}
          value={calc.total}
          hint={__('Worked out from the installments.', 'eleva-crm-for-photographers')}
        />
        {showTaxable && (
          <ReadOnlyAmount
            id="total_price_taxable"
            label={__('Taxable Price (€)', 'eleva-crm-for-photographers')}
            value={calc.taxable}
            hint={__('The installments marked as taxable.', 'eleva-crm-for-photographers')}
          />
        )}
        {hasVat && (
          <ReadOnlyAmount
            id="vat_amount"
            label={`${__('VAT', 'eleva-crm-for-photographers')} ${vat.rate}%`}
            value={calc.vat}
          />
        )}
        {hasVat && vat.mode === 'excluded' && (
          <ReadOnlyAmount
            id="to_collect"
            label={__('Total to collect (€)', 'eleva-crm-for-photographers')}
            value={calc.toCollect}
            hint={__('Total price plus VAT.', 'eleva-crm-for-photographers')}
          />
        )}
      </div>
    </div>
  )
}
