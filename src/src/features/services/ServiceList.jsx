import { useNavigate } from 'react-router-dom'
import { Pencil, PlusCircle } from 'lucide-react'
import { useServices } from '../../api/services'
import PageHeader from '../../components/PageHeader'
import Button from '../../components/Button'
import Table from '../../components/Table'
import Spinner from '../../components/Spinner'
import { __ } from '../../utils/i18n'

function formatPrice(amount) {
  return '€' + Number(amount || 0).toLocaleString('it-IT', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function truncate(text, max = 60) {
  if (!text) return '—'
  return text.length > max ? text.slice(0, max) + '…' : text
}

export default function ServiceList() {
  const navigate = useNavigate()

  const { data, isLoading } = useServices()

  const services = Array.isArray(data) ? data : data?.data ?? []

  const columns = [
    {
      key: 'title',
      label: __('Name', 'eleva-crm-for-photographers'),
      render: (row) => (
        <button
          type="button"
          className="border-0 bg-transparent p-0 text-left font-medium text-fotonic-primary underline cursor-pointer hover:opacity-80"
          onClick={() => navigate(`/services/${row.id}`)}
        >
          {row.title}
        </button>
      ),
    },
    {
      key: 'base_price',
      label: __('Base Price', 'eleva-crm-for-photographers'),
      render: (row) => (
        <span className="text-gray-700">{formatPrice(row.base_price)}</span>
      ),
    },
    {
      key: 'notes',
      label: __('Notes', 'eleva-crm-for-photographers'),
      render: (row) => (
        <span className="text-gray-500">{truncate(row.notes)}</span>
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
            onClick={() => navigate(`/services/${row.id}`)}
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
        title={__('Services', 'eleva-crm-for-photographers')}
        action={
          <Button onClick={() => navigate('/services/new')}>
            <PlusCircle size={15} />
            {__('Add Service', 'eleva-crm-for-photographers')}
          </Button>
        }
      />

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : (
        <Table
          columns={columns}
          data={services}
          emptyMessage={__('No services found. Add your first service.', 'eleva-crm-for-photographers')}
        />
      )}

    </div>
  )
}
