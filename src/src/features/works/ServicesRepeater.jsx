import { Trash2, PlusCircle } from 'lucide-react'
import Button from '../../components/Button'
import RowReorder from '../../components/RowReorder'
import { moveRow } from '../../utils/moveRow'
import SearchSelect from '../../components/SearchSelect'
import { __ } from '../../utils/i18n'

const emptyRow = () => ({
  service_id: '',
  service_title: '',
  price_override: '',
  notes_override: '',
})

export default function ServicesRepeater({ value = [], onChange, services = [] }) {
  function addRow() {
    onChange([...value, emptyRow()])
  }

  // Saved rows are matched to their stored note by position inside the same service: with an unreadable
  // value (Vault reset) moving a row could swap notes, so the arrows wait until it is fixed.
  const moveLocked = value.some((r) => r.notes_override === null || r.price_override === null)

  function moveRowBy(index, dir) {
    onChange(moveRow(value, index, dir))
  }

  function removeRow(index) {
    onChange(value.filter((_, i) => i !== index))
  }

  function updateRow(index, field, val) {
    const next = value.map((row, i) => {
      if (i !== index) return row
      if (field === 'service_id') {
        const svc = services.find((s) => String(s.id) === String(val))
        return {
          ...row,
          service_id: val,
          service_title: svc ? (svc.title ?? '') : '',
          price_override: svc ? (svc.base_price ?? '') : '', // null = the price could not be read: leave the field empty
        }
      }
      return { ...row, [field]: val }
    })
    onChange(next)
  }

  return (
    <div className="space-y-3">
      {value.length > 0 && (
        <div className="ftnc-table-scroll">
          <table className="min-w-full divide-y divide-gray-200 bg-white text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">{__('Service', 'eleva-crm-for-photographers')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase w-32">{__('Price (€)', 'eleva-crm-for-photographers')}</th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">{__('Notes', 'eleva-crm-for-photographers')}</th>
                <th className="px-3 py-2 w-28"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {value.map((row, index) => (
                <tr key={index}>
                  <td className="px-3 py-2">
                    <SearchSelect
                      value={row.service_id}
                      onChange={(val) => updateRow(index, 'service_id', val)}
                      options={services.map((svc) => ({ value: String(svc.id), label: svc.title ?? '' }))}
                      selectedLabel={row.service_title}
                      placeholder={__('Select service', 'eleva-crm-for-photographers')}
                      emptyText={__('No services found', 'eleva-crm-for-photographers')}
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="number"
                      min="0"
                      step="any"
                      value={row.price_override}
                      onChange={(e) => updateRow(index, 'price_override', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder="0.00"
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="text"
                      value={row.notes_override}
                      onChange={(e) => updateRow(index, 'notes_override', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder={__('Optional notes...', 'eleva-crm-for-photographers')}
                    />
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex items-center gap-1 whitespace-nowrap">
                      <RowReorder index={index} count={value.length} onMove={moveRowBy} disabled={moveLocked} />
                      <Button type="button" variant="danger" size="sm" onClick={() => removeRow(index)} aria-label={__('Remove service', 'eleva-crm-for-photographers')}>
                        <Trash2 size={14} />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Button type="button" variant="secondary" size="sm" onClick={addRow}>
        <PlusCircle size={14} />
        {__('Add Service', 'eleva-crm-for-photographers')}
      </Button>
    </div>
  )
}
