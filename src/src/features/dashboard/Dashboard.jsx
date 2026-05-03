import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { format, parseISO, isAfter, getYear } from 'date-fns'
import { useWorks } from '../../api/works'
import Badge from '../../components/Badge'
import Spinner from '../../components/Spinner'
import Table from '../../components/Table'

function StatCard({ label, value, sub }) {
  return (
    <div className="bg-white rounded-lg border border-gray-200 p-5 flex flex-col gap-1">
      <span className="text-sm text-gray-500">{label}</span>
      <span className="text-2xl font-bold text-gray-900">{value}</span>
      {sub && <span className="text-xs text-gray-400">{sub}</span>}
    </div>
  )
}

function formatPrice(amount) {
  return '€' + Number(amount || 0).toLocaleString('it-IT', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function formatDate(dateStr) {
  if (!dateStr) return '—'
  try {
    return format(parseISO(dateStr), 'dd MMM yyyy')
  } catch {
    return dateStr
  }
}

export default function Dashboard() {
  const navigate = useNavigate()
  const { data, isLoading } = useWorks({ per_page: 100 })
  const works = Array.isArray(data) ? data : data?.data ?? []

  const now = new Date()
  const currentYear = getYear(now)

  const stats = useMemo(() => {
    const thisYearWorks = works.filter((w) => {
      if (!w.event_date) return false
      try {
        return getYear(parseISO(w.event_date)) === currentYear
      } catch {
        return false
      }
    })

    const revenueThisYear = thisYearWorks.reduce((sum, w) => {
      const paid = (w.installments ?? []).filter((i) => i.status === 'paid')
      return sum + paid.reduce((s, i) => s + parseFloat(i.amount || 0), 0)
    }, 0)

    const upcoming = works.filter((w) => {
      if (!w.event_date) return false
      try {
        return isAfter(parseISO(w.event_date), now)
      } catch {
        return false
      }
    })

    const unpaidBalance = works.reduce((sum, w) => {
      const unpaid = (w.installments ?? []).filter((i) => i.status === 'unpaid')
      return sum + unpaid.reduce((s, i) => s + parseFloat(i.amount || 0), 0)
    }, 0)

    return {
      totalWorksThisYear: thisYearWorks.length,
      revenueThisYear,
      upcomingCount: upcoming.length,
      unpaidBalance,
      next5: [...upcoming]
        .sort((a, b) => (a.event_date ?? '').localeCompare(b.event_date ?? ''))
        .slice(0, 5),
    }
  }, [works, currentYear])

  const upcomingColumns = [
    { key: 'title', label: 'Title', render: (row) => <button className="text-indigo-600 hover:underline font-medium text-left" onClick={() => navigate(`/works/${row.id}`)}>{row.title}</button> },
    { key: 'event_date', label: 'Event Date', render: (row) => formatDate(row.event_date) },
    { key: 'customer', label: 'Customer', render: (row) => row.customer_title ?? <span className="text-gray-400">—</span> },
    { key: 'payment_status', label: 'Payment Status', render: (row) => <Badge status={row.payment_status ?? 'unpaid'} /> },
  ]

  if (isLoading) return <div className="flex justify-center py-12"><Spinner /></div>

  return (
    <div className="p-6 space-y-8">
      <h1 className="text-xl font-semibold text-gray-900">Dashboard</h1>
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Works This Year" value={stats.totalWorksThisYear} sub={`Year ${currentYear}`} />
        <StatCard label="Revenue This Year" value={formatPrice(stats.revenueThisYear)} sub="From paid installments" />
        <StatCard label="Upcoming Events" value={stats.upcomingCount} sub="Future event dates" />
        <StatCard label="Unpaid Balance" value={formatPrice(stats.unpaidBalance)} sub="All works combined" />
      </div>
      <div>
        <h2 className="text-base font-semibold text-gray-800 mb-3">Next Upcoming Works</h2>
        {stats.next5.length === 0 ? <p className="text-sm text-gray-400">No upcoming events scheduled.</p> : <Table columns={upcomingColumns} data={stats.next5} emptyMessage="No upcoming events." />}
      </div>
    </div>
  )
}
