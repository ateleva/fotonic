import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Pencil, Trash2, PlusCircle } from 'lucide-react'
import { useServices, useDeleteService } from '../../api/services'
import PageHeader from '../../components/PageHeader'
import Button from '../../components/Button'
import Table from '../../components/Table'
import Spinner from '../../components/Spinner'
import ConfirmDialog from '../../components/ConfirmDialog'

function formatPrice(amount) {
  return '€' + Number(amount || 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function truncate(text, max = 60) {
  if (!text) return '—'
  return text.length > max ? text.slice(0, max) + '…' : text
}

export default function ServiceList() {
  const navigate = useNavigate()
  const [deleteTarget, setDeleteTarget] = useState(null)
  const { data, isLoading } = useServices()
  const deleteService = useDeleteService()
  const services = Array.isArray(data) ? data : data?.data ?? []

  const columns = [
    { key: 'title', label: 'Name', render: (row) => <span className="font-medium text-gray-900">{row.title}</span> },
    { key: 'base_price', label: 'Base Price', render: (row) => <span className="text-gray-700">{formatPrice(row.base_price)}</span> },
    { key: 'notes', label: 'Notes', render: (row) => <span className="text-gray-500">{truncate(row.notes)}</span> },
    { key: 'actions', label: 'Actions', render: (row) => (
      <div className="flex items-center gap-2">
        <Button variant="ghost" size="sm" onClick={() => navigate(`/services/${row.id}`)}><Pencil size={14} />Edit</Button>
        <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(row)} className="text-red-500 hover:text-red-700 hover:bg-red-50"><Trash2 size={14} /></Button>
      </div>
    )},
  ]

  return (
    <div className="p-6">
      <PageHeader title="Services" action={<Button onClick={() => navigate('/services/new')}><PlusCircle size={15} />Add Service</Button>} />
      {isLoading ? <div className="flex justify-center py-12"><Spinner /></div> : <Table columns={columns} data={services} emptyMessage="No services found. Add your first service." />}
      <ConfirmDialog open={!!deleteTarget} onClose={() => setDeleteTarget(null)} onConfirm={() => deleteService.mutate(deleteTarget?.id)} message={`Delete service "${deleteTarget?.title}"? This cannot be undone.`} />
    </div>
  )
}
