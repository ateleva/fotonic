import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Pencil, PlusCircle, Settings2 } from 'lucide-react'
import { useWorks } from '../../api/works'
import { formatDate } from '../../utils/date'
import PageHeader from '../../components/PageHeader'
import Button from '../../components/Button'
import Table from '../../components/Table'
import Badge from '../../components/Badge'
import Spinner from '../../components/Spinner'
import PaymentTypeManager from './PaymentTypeManager'
import { __ } from '../../utils/i18n'

// The reminders overview is a Pro page: its button (with the due count) comes from Pro, behind the
// same two guards as the route in router.jsx.
const RemindersButton = window.FotonicApp?.features?.kanban && window.FotonicProComponents?.RemindersList
  ? window.FotonicProComponents?.RemindersButton ?? null
  : null

function formatPrice(amount) {
  return '€' + Number(amount || 0).toLocaleString('it-IT', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

export default function WorkList() {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [typesOpen, setTypesOpen] = useState(false)

  const params = {}
  if (search) params.search = search
  if (paymentStatus) params.payment_status = paymentStatus

  const { data, isLoading } = useWorks(params)

  const rawWorks = Array.isArray(data) ? data : data?.data ?? []

  // Sort by event_date descending
  const works = [...rawWorks].sort((a, b) => {
    const da = a.event_date ?? ''
    const db = b.event_date ?? ''
    return db.localeCompare(da)
  })

  const columns = [
    {
      key: 'title',
      label: __('Title', 'eleva-crm-for-photographers'),
      render: (row) => (
        <button
          type="button"
          className="border-0 bg-transparent p-0 text-left font-medium text-fotonic-primary underline cursor-pointer hover:opacity-80"
          onClick={() => navigate(`/works/${row.id}`)}
        >
          {row.title}
        </button>
      ),
    },
    {
      key: 'event_date',
      label: __('Event Date', 'eleva-crm-for-photographers'),
      render: (row) => formatDate(row.event_date),
    },
    {
      key: 'customer',
      label: __('Customer', 'eleva-crm-for-photographers'),
      render: (row) => row.customer_title ?? <span className="text-gray-400">—</span>,
    },
    {
      key: 'payment_status',
      label: __('Payment Status', 'eleva-crm-for-photographers'),
      render: (row) => <Badge status={row.payment_status ?? 'unpaid'} />,
    },
    {
      key: 'total_price',
      label: __('Total Price', 'eleva-crm-for-photographers'),
      render: (row) => formatPrice(row.total_price),
    },
    {
      key: 'actions',
      label: __('Actions', 'eleva-crm-for-photographers'),
      render: (row) => (
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => navigate(`/works/${row.id}`)}
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
        title={__('Works', 'eleva-crm-for-photographers')}
        action={
          <div className="flex flex-wrap items-center justify-end gap-2">
            {RemindersButton && <RemindersButton />}
            <Button variant="secondary" size="sm" onClick={() => setTypesOpen(true)}>
              <Settings2 size={14} />
              {__('Manage payment types', 'eleva-crm-for-photographers')}
            </Button>
            <Button size="sm" onClick={() => navigate('/works/new')}>
              <PlusCircle size={14} />
              {__('Add Work', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        }
      />

      <div className="flex flex-wrap gap-3 mb-4">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={__('Search works...', 'eleva-crm-for-photographers')}
          className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-64"
        />
        <select
          value={paymentStatus}
          onChange={(e) => setPaymentStatus(e.target.value)}
          className="rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          style={{ width: 'auto', minWidth: 'max-content', paddingRight: '2rem' }}
        >
          <option value="">{__('All Statuses', 'eleva-crm-for-photographers')}</option>
          <option value="paid">{__('Paid', 'eleva-crm-for-photographers')}</option>
          <option value="partial">{__('Partial', 'eleva-crm-for-photographers')}</option>
          <option value="unpaid">{__('Unpaid', 'eleva-crm-for-photographers')}</option>
        </select>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : (
        <Table
          columns={columns}
          data={works}
          emptyMessage={__('No works found. Add your first work.', 'eleva-crm-for-photographers')}
        />
      )}

      <PaymentTypeManager open={typesOpen} onClose={() => setTypesOpen(false)} />

    </div>
  )
}
