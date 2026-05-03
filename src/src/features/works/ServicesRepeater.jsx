import { Trash2, PlusCircle } from 'lucide-react'
import Button from '../../components/Button'

const emptyRow = () => ({ service_id: '', service_title: '', price_override: '', notes_override: '' })

export default function ServicesRepeater({ value = [], onChange, services = [] }) {
  function addRow() { onChange([...value, emptyRow()]) }
  function removeRow(index) { onChange(value.filter((_, i) => i !== index)) }
  function updateRow(index, field, val) {
    const next = value.map((row, i) => {
      if (i !== index) return row
      if (field === 'service_id') {
        const svc = services.find((s) => String(s.id) === String(val))
        return { ...row, service_id: val, service_title: svc ? svc.title : '', price_override: svc ? svc.base_price : '' }
      }
      return { ...row, [field]: val }
    })
    onChange(next)
  }

  return (
    <div className="space-y-3">
      {value.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200 bg-white text-sm">
            <thead className="bg-gray-50"><tr><th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Service</th><th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase w-32">Price (€)</th><th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Notes</th><th className="px-3 py-2 w-10"></th></tr></thead>
            <tbody className="divide-y divide-gray-100">
              {value.map((row, index) => (
                <tr key={index}>
                  <td className="px-3 py-2"><select value={row.service_id} onChange={(e) => updateRow(index, 'service_id', e.target.value)} className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"><option value="">— Select service —</option>{services.map((svc) => <option key={svc.id} value={svc.id}>{svc.title}</option>)}</select></td>
                  <td className="px-3 py-2"><input type="number" min="0" step="0.01" value={row.price_override} onChange={(e) => updateRow(index, 'price_override', e.target.value)} className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="0.00" /></td>
                  <td className="px-3 py-2"><input type="text" value={row.notes_override} onChange={(e) => updateRow(index, 'notes_override', e.target.value)} className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Optional notes..." /></td>
                  <td className="px-3 py-2"><button type="button" onClick={() => removeRow(index)} className="text-gray-400 hover:text-red-500 transition-colors" aria-label="Remove service"><Trash2 size={15} /></button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Button type="button" variant="secondary" size="sm" onClick={addRow}><PlusCircle size={14} />Add Service</Button>
    </div>
  )
}
