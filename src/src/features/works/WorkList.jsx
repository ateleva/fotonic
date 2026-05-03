import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Pencil, Trash2, PlusCircle } from 'lucide-react'
import { format, parseISO } from 'date-fns'
import { useWorks, useDeleteWork } from '../../api/works'
import PageHeader from '../../components/PageHeader'
import Button from '../../components/Button'
import Table from '../../components/Table'
import Badge from '../../components/Badge'
import Spinner from '../../components/Spinner'
import ConfirmDialog from '../../components/ConfirmDialog'

function formatPrice(amount) {
  return '€' + Number(amount || 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function formatDate(dateStr) {
  if (!dateStr) return '—'
  try { return format(parseISO(dateStr), 'dd MMM yyyy') } catch { return dateStr }
}

export default function WorkList() {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [deleteTarget, setDeleteTarget] = useState(null)

  const params = {}
  if (search) params.search = search
  if (paymentStatus) params.payment_status = paymentStatus

  const { data, isLoading } = useWorks(params)
  const deleteWork = useDeleteWork()
  const rawWorks = Array.isArray(data) ? data : data?.data ?? []
  const works = [...rawWorks].sort((a, b) => (b.event_date ?? '').localeCompare(a.event_date ?? ''))

  const columns = [
    { key: 'title', label: 'Title', render: (row) => <span className="font-medium text-gray-900">{row.title}</span> },
    { key: 'event_date', label: 'Event Date', render: (row) => formatDate(row.event_date) },
    { key: 'customer', label: 'Customer', render: (row) => row.customer_title ?? <span className="text-gray-400">—</span> },
    { key: 'payment_status', label: 'Payment Status', render: (row) => <Badge status={row.payment_status ?? 'unpaid'} /> },
    { key: 'total_price', label: 'Total Price', render: (row) => formatPrice(row.total_price) },
    { key: 'actions', label: 'Actions', render: (row) => (
      <div className="flex items-center gap-2">
        <Button variant="ghost" size="sm" onClick={() => navigate(`/works/${row.id}`)}><Pencil size={14} />Edit</Button>
        <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(row)} className="text-red-500 hover:text-red-700 hover:bg-red-50"><Trash2 size={14} /></Button>
      </div>
    )},
  ]

  return (
    <div className="p-6">
      <PageHeader title="Works" action={<Button onClick={() => navigate('/works/new')}><PlusCircle size={15} />Add Work</Button>} />
      <div className="flex flex-wrap gap-3 mb-4">
        <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search works..." className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-64" />
        <select value={paymentStatus} onChange={(e) => setPaymentStatus(e.target.value)} className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <option value="">All Statuses</option>
          <option value="paid">Paid</option>
          <option value="partial">Partial</option>
          <option value="unpaid">Unpaid</option>
        </select>
      </div>
      {isLoading ? <div className="flex justify-center py-12"><Spinner /></div> : <Table columns={columns} data={works} emptyMessage="No works found. Add your first work." />}
      <ConfirmDialog open={!!deleteTarget} onClose={() => setDeleteTarget(null)} onConfirm={() => deleteWork.mutate(deleteTarget?.id)} message={`Delete work "${deleteTarget?.title}"? This cannot be undone.`} />
    </div>
  )
}
