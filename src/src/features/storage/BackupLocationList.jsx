import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Pencil } from 'lucide-react'
import { useBackupLocations } from '../../api/backup-locations'
import Button from '../../components/Button'
import Table from '../../components/Table'
import Spinner from '../../components/Spinner'
import Pagination from '../../components/Pagination'
import { usePagedItems } from '../../utils/pagination'
import FilterPills from './FilterPills'
import { __ } from '../../utils/i18n'

const STATUS_STYLES = {
  active:  'bg-green-100 text-green-800',
  full:    'bg-amber-100 text-amber-800',
  retired: 'bg-gray-100 text-gray-600',
}

export function LocationStatusBadge({ status, label }) {
  const cls = STATUS_STYLES[status] ?? 'bg-gray-100 text-gray-700'
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
      {label || status}
    </span>
  )
}

export default function BackupLocationList() {
  const navigate = useNavigate()
  const [statusFilter, setStatusFilter] = useState('')
  const { data, isLoading } = useBackupLocations()
  const allLocations = Array.isArray(data) ? data : data?.data ?? []
  const filteredLocations = statusFilter ? allLocations.filter((l) => l.status === statusFilter) : allLocations
  const paged = usePagedItems(filteredLocations)
  const locations = paged.pageItems

  const count = (status) => allLocations.filter((l) => l.status === status).length
  const filters = [
    { key: '',        label: __('All', 'eleva-crm-for-photographers'),     count: allLocations.length },
    { key: 'active',  label: __('Active', 'eleva-crm-for-photographers'),  count: count('active') },
    { key: 'full',    label: __('Full', 'eleva-crm-for-photographers'),    count: count('full') },
    { key: 'retired', label: __('Retired', 'eleva-crm-for-photographers'), count: count('retired') },
  ]

  const columns = [
    {
      key: 'title',
      label: __('Name', 'eleva-crm-for-photographers'),
      render: (row) => (
        <button
          type="button"
          className="border-0 bg-transparent p-0 text-left font-medium text-fotonic-primary underline cursor-pointer hover:opacity-80"
          onClick={() => navigate(`/storage/backups/${row.id}`)}
        >
          {row.title}
        </button>
      ),
    },
    {
      key: 'work_count',
      label: __('Works', 'eleva-crm-for-photographers'),
      render: (row) => row.work_count,
    },
    {
      key: 'type',
      label: __('Type', 'eleva-crm-for-photographers'),
      render: (row) => row.type || <span className="text-gray-400">-</span>,
    },
    {
      key: 'capacity',
      label: __('Capacity', 'eleva-crm-for-photographers'),
      render: (row) => row.capacity || <span className="text-gray-400">-</span>,
    },
    {
      key: 'status',
      label: __('Status', 'eleva-crm-for-photographers'),
      render: (row) => <LocationStatusBadge status={row.status} label={row.status_label} />,
    },
    {
      key: 'actions',
      label: __('Actions', 'eleva-crm-for-photographers'),
      render: (row) => (
        <Button variant="secondary" size="sm" onClick={() => navigate(`/storage/backups/${row.id}`)}>
          <Pencil size={14} />
          {__('Edit', 'eleva-crm-for-photographers')}
        </Button>
      ),
    },
  ]

  return (
    <>
      <div className="mb-4">
        <FilterPills value={statusFilter} onChange={(key) => { setStatusFilter(key); paged.setPage(1) }} options={filters} />
      </div>
      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : (
        <Table
          columns={columns}
          data={locations}
          emptyMessage={__('No backup locations yet. Add your first one: a hard drive, a NAS, a cloud service…', 'eleva-crm-for-photographers')}
        />
      )}

      <Pagination page={paged.page} pages={paged.pages} total={paged.total} size={paged.size} onPage={paged.setPage} />
    </>
  )
}
