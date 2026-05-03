import { Trash2, PlusCircle } from 'lucide-react'
import Button from '../../components/Button'

const emptyInstallment = () => ({ title: '', amount: '', status: 'unpaid' })

export default function InstallmentsRepeater({ value = [], onChange }) {
  function addRow() { onChange([...value, emptyInstallment()]) }
  function removeRow(index) { onChange(value.filter((_, i) => i !== index)) }
  function updateRow(index, field, val) { onChange(value.map((row, i) => (i === index ? { ...row, [field]: val } : row))) }
  function toggleStatus(index) { updateRow(index, 'status', value[index].status === 'paid' ? 'unpaid' : 'paid') }

  const totalPaid = value.reduce((sum, r) => r.status === 'paid' ? sum + parseFloat(r.amount || 0) : sum, 0)
  const totalUnpaid = value.reduce((sum, r) => r.status === 'unpaid' ? sum + parseFloat(r.amount || 0) : sum, 0)

  return (
    <div className="space-y-3">
      {value.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200 bg-white text-sm">
            <thead className="bg-gray-50"><tr><th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Title</th><th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase w-36">Amount (€)</th><th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase w-28">Status</th><th className="px-3 py-2 w-10"></th></tr></thead>
            <tbody className="divide-y divide-gray-100">
              {value.map((row, index) => (
                <tr key={index}>
                  <td className="px-3 py-2"><input type="text" value={row.title} onChange={(e) => updateRow(index, 'title', e.target.value)} className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="e.g. Deposit" /></td>
                  <td className="px-3 py-2"><input type="number" min="0" step="0.01" value={row.amount} onChange={(e) => updateRow(index, 'amount', e.target.value)} className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="0.00" /></td>
                  <td className="px-3 py-2"><button type="button" onClick={() => toggleStatus(index)} className={['inline-flex items-center px-2.5 py-1 rounded text-xs font-medium transition-colors', row.status === 'paid' ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-red-100 text-red-800 hover:bg-red-200'].join(' ')}>{row.status === 'paid' ? 'Paid' : 'Unpaid'}</button></td>
                  <td className="px-3 py-2"><button type="button" onClick={() => removeRow(index)} className="text-gray-400 hover:text-red-500 transition-colors" aria-label="Remove installment"><Trash2 size={15} /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Button type="button" variant="secondary" size="sm" onClick={addRow}><PlusCircle size={14} />Add Installment</Button>
      {value.length > 0 && (
        <div className="flex gap-6 text-sm pt-1">
          <span className="text-green-700 font-medium">Paid: €{totalPaid.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
          <span className="text-red-700 font-medium">Unpaid: €{totalUnpaid.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
        </div>
      )}
    </div>
  )
}
