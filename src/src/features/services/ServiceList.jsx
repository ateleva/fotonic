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
      label: __('Name'),
      render: (row) => (
        <span className="font-medium text-gray-900">{row.title}</span>
      ),
    },
    {
      key: 'base_price',
      label: __('Base Price'),
      render: (row) => (
        <span className="text-gray-700">{formatPrice(row.base_price)}</span>
      ),
    },
    {
      key: 'notes',
      label: __('Notes'),
      render: (row) => (
        <span className="text-gray-500">{truncate(row.notes)}</span>
      ),
    },
    {
      key: 'actions',
      label: __('Actions'),
      render: (row) => (
        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate(`/services/${row.id}`)}
          >
            <Pencil size={14} />
            {__('Edit')}
          </Button>
        </div>
      ),
    },
  ]

  return (
    <div className="p-6">
      <PageHeader
        title={__('Services')}
        action={
          <Button onClick={() => navigate('/services/new')}>
            <PlusCircle size={15} />
            {__('Add Service')}
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
          emptyMessage={__('No services found. Add your first service.')}
        />
      )}

    </div>
  )
}
