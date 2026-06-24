import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Pencil, PlusCircle } from 'lucide-react'
import { useMemoryCards } from '../../api/memory-cards'
import PageHeader from '../../components/PageHeader'
import Button from '../../components/Button'
import Table from '../../components/Table'
import Spinner from '../../components/Spinner'
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

  const cards = Array.isArray(data) ? data : data?.data ?? []

  const columns = [
    {
      key: 'title',
      label: __('Card Name', 'eleva-crm-for-photographers'),
      render: (row) => (
        <span className="font-medium text-gray-900">{row.title}</span>
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
            onClick={() => navigate(`/memory-cards/${row.id}`)}
          >
            <Pencil size={14} />
            {__('Edit', 'eleva-crm-for-photographers')}
          </Button>
        </div>
      ),
    },
  ]

  return (
    <div className="p-6">
      <PageHeader
        title={__('Memory Cards', 'eleva-crm-for-photographers')}
        action={
          <Button onClick={() => navigate('/memory-cards/new')}>
            <PlusCircle size={15} />
            {__('Add Card', 'eleva-crm-for-photographers')}
          </Button>
        }
      />

      <div className="flex flex-wrap gap-2 mb-4">
        {ALL_STATUSES.map(({ slug, label }) => (
          <button
            key={slug}
            type="button"
            onClick={() => setStatusFilter(slug)}
            className={[
              'px-3 py-1.5 rounded-md text-sm font-medium transition-colors border',
              statusFilter === slug
                ? 'bg-indigo-50 border-indigo-300 text-indigo-700'
                : 'bg-white border-gray-300 text-gray-600 hover:bg-gray-50',
            ].join(' ')}
          >
            {label()}
          </button>
        ))}
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
    </div>
  )
}
