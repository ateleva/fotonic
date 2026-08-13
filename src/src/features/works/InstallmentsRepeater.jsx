import { Trash2, PlusCircle } from 'lucide-react'
import { __ } from '../../utils/i18n'
import Button from '../../components/Button'
import { usePaymentTypes } from '../../api/paymentTypes'

const emptyInstallment = () => ({
  title: '',
  amount: '',
  status: 'unpaid',
  type: 'default',
  date: '',
})

export default function InstallmentsRepeater({ value = [], onChange }) {
  const { data: paymentTypes = [] } = usePaymentTypes()

  function addRow() {
    onChange([...value, emptyInstallment()])
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

  const totalPaid = value.reduce((sum, r) => {
    return r.status === 'paid' ? sum + parseFloat(r.amount || 0) : sum
  }, 0)

  const totalUnpaid = value.reduce((sum, r) => {
    return r.status === 'unpaid' ? sum + parseFloat(r.amount || 0) : sum
  }, 0)

  return (
    <div className="space-y-3">
      {value.length > 0 && (
        <div className="ftnc-table-scroll">
          <table className="min-w-full divide-y divide-gray-200 bg-white text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">{__('Type', 'eleva-crm-for-photographers')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">{__('Title', 'eleva-crm-for-photographers')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase w-36">{__('Amount (€)', 'eleva-crm-for-photographers')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase w-36">{__('Date', 'eleva-crm-for-photographers')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase w-28">{__('Status', 'eleva-crm-for-photographers')}</th>
                <th className="px-3 py-2 w-10"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {value.map((row, index) => (
                <tr key={index}>
                  <td className="px-3 py-2">
                    <select
                      value={row.type ?? 'default'}
                      onChange={(e) => updateRow(index, 'type', e.target.value)}
                      className="w-auto rounded-md border border-gray-300 pl-2 pr-7 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      {paymentTypes.length > 0
                        ? paymentTypes.map((t) => (
                            <option key={t.id} value={t.slug}>{__(t.label)}</option>
                          ))
                        : <option value={row.type ?? 'default'}>{row.type ?? 'default'}</option>
                      }
                    </select>
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="text"
                      value={row.title}
                      onChange={(e) => updateRow(index, 'title', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder={__('e.g. Deposit', 'eleva-crm-for-photographers')}
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={row.amount}
                      onChange={(e) => updateRow(index, 'amount', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder="0.00"
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="date"
                      value={row.date ?? ''}
                      onChange={(e) => updateRow(index, 'date', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </td>
                  <td className="px-3 py-2">
                    <button
                      type="button"
                      onClick={() => toggleStatus(index)}
                      className={[
                        'inline-flex items-center px-2.5 py-1 rounded text-xs font-medium transition-colors',
                        row.status === 'paid'
                          ? 'bg-green-100 text-green-800 hover:bg-green-200'
                          : 'bg-red-100 text-red-800 hover:bg-red-200',
                      ].join(' ')}
                    >
                      {row.status === 'paid' ? __('Paid', 'eleva-crm-for-photographers') : __('Unpaid', 'eleva-crm-for-photographers')}
                    </button>
                  </td>
                  <td className="px-3 py-2">
                    <Button type="button" variant="danger" size="sm" onClick={() => removeRow(index)} aria-label={__('Remove installment', 'eleva-crm-for-photographers')}>
                      <Trash2 size={14} />
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Button type="button" variant="secondary" size="sm" onClick={addRow}>
        <PlusCircle size={14} />
        {__('Add Installment', 'eleva-crm-for-photographers')}
      </Button>

      {value.length > 0 && (
        <div className="flex gap-6 text-sm pt-1">
          <span className="text-green-700 font-medium">
            {__('Paid', 'eleva-crm-for-photographers')}: €{totalPaid.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </span>
          <span className="text-red-700 font-medium">
            {__('Unpaid', 'eleva-crm-for-photographers')}: €{totalUnpaid.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </span>
        </div>
      )}
    </div>
  )
}
