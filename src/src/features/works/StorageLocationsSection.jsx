import { Trash2, PlusCircle, AlertTriangle } from 'lucide-react'
import { useBackupLocations } from '../../api/backup-locations'
import Button from '../../components/Button'
import SearchSelect from '../../components/SearchSelect'
import { __ } from '../../utils/i18n'

/**
 * value = [{ location_id, location_title?, deleted? }]
 * onChange = (value) => void
 *
 * A location deleted after it was chosen keeps its row (`deleted: true`) so the admin can see it and pick
 * another one or remove the row. The row is outlined in red until it is fixed and the work is saved.
 */
export default function StorageLocationsSection({ value = [], onChange }) {
  const rows = value ?? []
  const { data } = useBackupLocations()
  const locations = Array.isArray(data) ? data : data?.data ?? []

  function setRows(next) {
    onChange(next)
  }

  function addRow() {
    setRows([...rows, { location_id: '' }])
  }

  function removeRow(idx) {
    setRows(rows.filter((_, i) => i !== idx))
  }

  function pick(idx, val) {
    setRows(rows.map((row, i) => (i === idx ? { location_id: val ? Number(val) : '' } : row)))
  }

  // A location already used by another row is not offered again.
  function optionsFor(idx) {
    const taken = new Set(rows.filter((_, i) => i !== idx).map((r) => Number(r.location_id)))
    return locations.filter((l) => !taken.has(l.id))
  }

  return (
    <div className="ftnc-fields">
      {rows.length > 0 && (
        <div className="space-y-2">
          {rows.map((row, idx) => (
            <div key={idx}>
              <div className="flex items-center gap-2">
                <SearchSelect
                  className="flex-1 min-w-0"
                  value={row.location_id ?? ''}
                  onChange={(val) => pick(idx, val)}
                  invalid={!!row.deleted}
                  options={[
                    ...optionsFor(idx).map((l) => ({ value: String(l.id), label: l.title })),
                    ...(row.deleted
                      ? [{ value: String(row.location_id), label: row.location_title || __('Deleted location', 'eleva-crm-for-photographers'), disabled: true }]
                      : []),
                  ]}
                  selectedLabel={row.deleted ? (row.location_title || __('Deleted location', 'eleva-crm-for-photographers')) : undefined}
                  placeholder={__('Select a backup location…', 'eleva-crm-for-photographers')}
                  emptyText={__('No backup locations found', 'eleva-crm-for-photographers')}
                />
                <Button type="button" variant="danger" size="sm" onClick={() => removeRow(idx)} aria-label={__('Remove', 'eleva-crm-for-photographers')}>
                  <Trash2 size={14} />
                </Button>
              </div>
              {row.deleted && (
                <p role="alert" className="mt-1 flex items-center gap-1.5 text-sm text-red-600">
                  <AlertTriangle size={14} className="shrink-0" />
                  {__('This backup location was deleted. Choose another one or remove the row.', 'eleva-crm-for-photographers')}
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <Button type="button" variant="secondary" size="sm" onClick={addRow}>
          <PlusCircle size={14} />
          {__('Add Backup Location', 'eleva-crm-for-photographers')}
        </Button>
        {/* New tab: this form has unsaved changes that a navigation would throw away. */}
        <a
          href="#/storage/backups/new"
          target="_blank"
          rel="noopener noreferrer"
          className="text-sm text-fotonic-primary underline hover:opacity-80"
        >
          {__('Create a new backup location', 'eleva-crm-for-photographers')}
        </a>
      </div>
    </div>
  )
}
