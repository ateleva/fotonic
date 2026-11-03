import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Pencil } from 'lucide-react'
import { useMemoryCards } from '../../api/memory-cards'
import Button from '../../components/Button'
import Table from '../../components/Table'
import Spinner from '../../components/Spinner'
import Pagination from '../../components/Pagination'
import { usePagedItems } from '../../utils/pagination'
import FilterPills from '../storage/FilterPills'
import { __ } from '../../utils/i18n'

const STATUS_STYLES = {
  free:      'bg-green-100 text-green-800',
  in_use:    'bg-blue-100 text-blue-800',
  backed_up: 'bg-teal-100 text-teal-800',
  damaged:   'bg-red-100 text-red-800',
}

function CardStatusBadge({ status, label }) {
  const cls = STATUS_STYLES[status] ?? 'bg-gray-100 text-gray-700'
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}`}>
      {label || status}
    </span>
  )
}

const ALL_STATUSES = [
  { slug: '',          label: () => __('All', 'eleva-crm-for-photographers') },
  { slug: 'free',      label: () => __('Ready', 'eleva-crm-for-photographers') },
  { slug: 'in_use',    label: () => __('In Use', 'eleva-crm-for-photographers') },
  { slug: 'backed_up', label: () => __('Backed Up', 'eleva-crm-for-photographers') },
  { slug: 'damaged',   label: () => __('Damaged', 'eleva-crm-for-photographers') },
]

export default function MemoryCardList() {
  const navigate = useNavigate()
  const [statusFilter, setStatusFilter] = useState('')

  const params = statusFilter ? { status: statusFilter } : {}
  const { data, isLoading } = useMemoryCards(params)
  // The stats count every card whatever the filter, so they come from the unfiltered list (same cache entry
  // as the table when no filter is chosen).
  const { data: allData } = useMemoryCards({})

  const filteredCards = Array.isArray(data) ? data : data?.data ?? []
  const paged = usePagedItems(filteredCards)
  const cards = paged.pageItems
  const allCards = Array.isArray(allData) ? allData : allData?.data ?? []
  const count = (status) => allCards.filter((c) => c.status === status).length

  const columns = [
    {
      key: 'title',
      label: __('Card Name', 'eleva-crm-for-photographers'),
      render: (row) => (
        <button
          type="button"
          className="border-0 bg-transparent p-0 text-left font-medium text-fotonic-primary underline cursor-pointer hover:opacity-80"
          onClick={() => navigate(`/storage/cards/${row.id}`)}
        >
          {row.title}
        </button>
      ),
    },
    {
      key: 'status',
      label: __('Status', 'eleva-crm-for-photographers'),
      render: (row) => (
        <CardStatusBadge status={row.status} label={row.status_label} />
      ),
    },
    {
      key: 'in_use_work',
      label: __('In Work', 'eleva-crm-for-photographers'),
      render: (row) =>
        row.in_use_work ? (
          <button
            type="button"
            onClick={() => navigate(`/works/${row.in_use_work.id}`)}
            className="text-sm text-indigo-600 hover:underline"
          >
            {row.in_use_work.title}
          </button>
        ) : (
          <span className="text-gray-400">—</span>
        ),
    },
    {
      key: 'actions',
      label: __('Actions', 'eleva-crm-for-photographers'),
      render: (row) => (
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => navigate(`/storage/cards/${row.id}`)}
          >
            <Pencil size={14} />
            {__('Edit', 'eleva-crm-for-photographers')}
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>

      <div className="mb-4">
        <FilterPills
          value={statusFilter}
          onChange={(key) => { setStatusFilter(key); paged.setPage(1) }}
          options={ALL_STATUSES.map(({ slug, label }) => ({
            key: slug,
            label: label(),
            count: slug === '' ? allCards.length : count(slug),
          }))}
        />
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : (
        <Table
          columns={columns}
          data={cards}
          emptyMessage={__('No memory cards found. Add your first card.', 'eleva-crm-for-photographers')}
        />
      )}

      <Pagination page={paged.page} pages={paged.pages} total={paged.total} size={paged.size} onPage={paged.setPage} />
    </>
  )
}
