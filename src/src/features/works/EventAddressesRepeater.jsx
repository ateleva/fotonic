import { Trash2, PlusCircle } from 'lucide-react'
import { __ } from '../../utils/i18n'
import Button from '../../components/Button'

const emptyAddress = () => ({ label: '', street: '' })

export default function EventAddressesRepeater({ value = [], onChange }) {
  function addRow() {
    onChange([...value, emptyAddress()])
  }

  function removeRow(index) {
    onChange(value.filter((_, i) => i !== index))
  }

  function updateRow(index, field, val) {
    onChange(value.map((row, i) => (i === index ? { ...row, [field]: val } : row)))
  }

  return (
    <div className="space-y-3">
      {value.length > 0 && (
        <div className="ftnc-table-scroll">
          <table className="min-w-full divide-y divide-gray-200 bg-white text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase w-40">
                  {__('Label', 'eleva-crm-for-photographers')}
                </th>
                <th className="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase">
                  {__('Street', 'eleva-crm-for-photographers')}
                </th>
                <th className="px-3 py-2 w-10"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {value.map((row, index) => (
                <tr key={index}>
                  <td className="px-3 py-2">
                    <input
                      type="text"
                      value={row.label ?? ''}
                      onChange={(e) => updateRow(index, 'label', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder={__('e.g. Church', 'eleva-crm-for-photographers')}
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      type="text"
                      value={row.street ?? ''}
                      onChange={(e) => updateRow(index, 'street', e.target.value)}
                      className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      placeholder={__('Via Roma 1, Milano', 'eleva-crm-for-photographers')}
                    />
                  </td>
                  <td className="px-3 py-2">
                    <Button type="button" variant="danger" size="sm" onClick={() => removeRow(index)} aria-label={__('Remove address', 'eleva-crm-for-photographers')}>
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
        {__('Add Address', 'eleva-crm-for-photographers')}
      </Button>
    </div>
  )
}
