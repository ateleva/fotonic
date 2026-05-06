import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { Pencil, UserPlus } from 'lucide-react'
import { useCustomers } from '../../api/customers'
import PageHeader from '../../components/PageHeader'
import Button from '../../components/Button'
import Table from '../../components/Table'
import Spinner from '../../components/Spinner'

function useDebounce(value, delay = 300) {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])
  return debounced
}

export default function CustomerList() {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const debouncedSearch = useDebounce(search)

  const { data, isLoading } = useCustomers(
    debouncedSearch ? { search: debouncedSearch } : {}
  )

  const customers = Array.isArray(data) ? data : data?.data ?? []

  const columns = [
    {
      key: 'title',
      label: 'Title',
      render: (row) => (
        <span className="font-medium text-gray-900">{row.title}</span>
      ),
    },
    {
      key: 'main_contact',
      label: 'Main Contact',
      render: (row) => {
        const main = (row.people ?? []).find((p) => p.is_main)
        if (!main) return <span className="text-gray-400">—</span>
        return (
          <span>
            {main.first_name} {main.last_name}
          </span>
        )
      },
    },
    {
      key: 'email',
      label: 'Email',
      render: (row) => {
        const main = (row.people ?? []).find((p) => p.is_main)
        return main?.email ? (
          <span className="text-gray-600">{main.email}</span>
        ) : (
          <span className="text-gray-400">—</span>
        )
      },
    },
    {
      key: 'phone',
      label: 'Phone',
      render: (row) => {
        const main = (row.people ?? []).find((p) => p.is_main)
        return main?.phone ? (
          <span className="text-gray-600">{main.phone}</span>
        ) : (
          <span className="text-gray-400">—</span>
        )
      },
    },
    {
      key: 'actions',
      label: 'Actions',
      render: (row) => (
        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate(`/customers/${row.id}`)}
            aria-label="Edit customer"
          >
            <Pencil size={14} />
            Edit
          </Button>
        </div>
      ),
    },
  ]

  return (
    <div className="p-6">
      <PageHeader
        title="Customers"
        action={
          <Button onClick={() => navigate('/customers/new')}>
            <UserPlus size={15} />
            Add Customer
          </Button>
        }
      />

      <div className="mb-4">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search customers..."
          className="w-full max-w-sm rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
        />
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : (
        <Table
          columns={columns}
          data={customers}
          emptyMessage="No customers found. Add your first customer."
        />
      )}

    </div>
  )
}
