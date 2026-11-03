import { Trash2, PlusCircle } from 'lucide-react'
import Button from '../../components/Button'
import SearchSelect from '../../components/SearchSelect'
import { __, sprintf } from '../../utils/i18n'

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
                <th className="px-3 py-2 w-10"></th>
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
                    {(() => {
                      // A service can carry a percentage added on top of the typed price (Pro sets it).
                      // The field keeps what the admin types; the suffix says it will be increased.
                      const pct = Number(services.find((svc) => String(svc.id) === String(row.service_id))?.surcharge_pct) || 0
                      return (
                        <div className="relative">
                          <input
                            type="number"
                            min="0"
                            step="any"
                            value={row.price_override}
                            onChange={(e) => updateRow(index, 'price_override', e.target.value)}
                            className={`w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500${pct > 0 ? ' pr-12' : ''}`}
                            placeholder="0.00"
                          />
                          {pct > 0 && (
                            <span
                              className="absolute right-2 top-1/2 -translate-y-1/2 cursor-help rounded bg-amber-50 px-1 text-xs font-medium text-amber-700"
                              title={sprintf(__('This service adds %s%% to the price you enter. The total of the work already includes it.', 'eleva-crm-for-photographers'), pct)}
                            >
                              +{pct}%
                            </span>
                          )}
                        </div>
                      )
                    })()}
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
                    <Button type="button" variant="danger" size="sm" onClick={() => removeRow(index)} aria-label={__('Remove service', 'eleva-crm-for-photographers')}>
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
        {__('Add Service', 'eleva-crm-for-photographers')}
      </Button>
    </div>
  )
}
