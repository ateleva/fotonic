import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { __ } from '../../utils/i18n'
import { useWorks } from '../../api/works'
import { formatDate } from '../../utils/date'
import Badge from '../../components/Badge'
import Spinner from '../../components/Spinner'
import Table from '../../components/Table'
import { apiFetch } from '../../api/client'

function formatEuro(amount) {
  return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(amount ?? 0)
}

function workRevenue(work) {
  return (work.installments ?? [])
    .filter((i) => i.status === 'paid')
    .reduce((s, i) => s + parseFloat(i.amount || 0), 0)
}

// Stat card: title on top, main value (big+bold) + main label (small right), then sub-rows
function StatCard({ title, mainValue, mainLabel, rows, children }) {
  return (
    <div className="bg-white rounded-lg border border-gray-200 p-5 flex flex-col gap-2">
      <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">{title}</span>
      <div className="flex items-baseline gap-2">
        <span className="text-2xl font-bold text-gray-900 leading-none">{mainValue}</span>
        {mainLabel && <span className="text-xs text-gray-400">{mainLabel}</span>}
      </div>
      {rows && rows.map((r, i) => (
        <div key={i} className="flex items-baseline gap-2">
          <span className="text-sm font-semibold text-gray-600">{r.value}</span>
          <span className="text-xs text-gray-400">{r.label}</span>
        </div>
      ))}
      {children}
    </div>
  )
}

export default function Dashboard() {
  const navigate = useNavigate()
  const { data: works, isLoading: worksLoading } = useWorks({ per_page: 5, page: 1 })
  const upcomingWorks = Array.isArray(works) ? works : works?.data ?? []

  const { data: stats, isLoading: statsLoading } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: () => apiFetch('dashboard-stats'),
    staleTime: 60_000,
  })

  if (statsLoading || worksLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    )
  }

  const upcomingColumns = [
    {
      key: 'title',
      label: __('Title'),
      render: (row) => (
        <button
          className="border-0 bg-transparent p-0 text-blue-600 hover:underline font-medium text-left cursor-pointer"
          onClick={() => navigate(`/works/${row.id}`)}
        >
          {row.title}
        </button>
      ),
    },
    {
      key: 'event_date',
      label: __('Date'),
      render: (row) => formatDate(row.event_date),
    },
    {
      key: 'customer',
      label: __('Customer'),
      render: (row) => row.customer_title ?? <span className="text-gray-400">—</span>,
    },
    {
      key: 'revenue',
      label: __('Revenue'),
      render: (row) => formatEuro(workRevenue(row)),
    },
    {
      key: 'payment_status',
      label: __('Payment Status'),
      render: (row) => <Badge status={row.payment_status ?? 'unpaid'} />,
    },
  ]

  const paymentTypes = stats?.payment_types ?? []
  const totalPT = paymentTypes.reduce((s, t) => s + t.subtotal, 0)

  return (
    <div className="p-6 space-y-8">
      <h1 className="text-xl font-semibold text-gray-900">{__('Dashboard')}</h1>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        {/* Works */}
        <StatCard
          title={__('Works')}
          mainValue={stats?.works?.this_year ?? 0}
          mainLabel={__('this year')}
          rows={[
            { value: stats?.works?.next_year ?? 0, label: __('next year') },
            { value: stats?.works?.last_year ?? 0, label: __('last year') },
          ]}
        />

        {/* Revenue */}
        <StatCard
          title={__('Revenue')}
          mainValue={formatEuro(stats?.revenue?.this_year)}
          mainLabel={__('this year')}
          rows={[
            { value: formatEuro(stats?.revenue?.next_year), label: __('next year') },
            { value: formatEuro(stats?.revenue?.last_year), label: __('last year') },
          ]}
        />

        {/* Payments to receive */}
        <StatCard
          title={__('Payments to receive')}
          mainValue={formatEuro(stats?.payments_to_receive?.this_year)}
          mainLabel={__('this year')}
          rows={
            stats?.payments_to_receive?.show_last_year
              ? [{ value: formatEuro(stats?.payments_to_receive?.last_year), label: __('last year') }]
              : []
          }
        />

        {/* Payment types used */}
        <div className="bg-white rounded-lg border border-gray-200 p-5 flex flex-col gap-2">
          <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">{__('Payment types used')}</span>
          {paymentTypes.length === 0 ? (
            <span className="text-sm text-gray-400">{__('No data')}</span>
          ) : (
            <div className="flex flex-col gap-1">
              {paymentTypes.map((t) => (
                <div key={t.slug} className="flex items-center gap-2 text-sm">
                  <span className="font-medium text-gray-700 flex-1 truncate">{t.label}</span>
                  <span className="text-gray-600 font-semibold">{formatEuro(t.subtotal)}</span>
                  <span className="text-gray-400 text-xs w-10 text-right">{t.pct}%</span>
                </div>
              ))}
              {totalPT > 0 && (
                <div className="border-t border-gray-100 pt-1 mt-1 flex items-center gap-2 text-sm">
                  <span className="text-gray-400 flex-1">{__('Total')}</span>
                  <span className="font-bold text-gray-800">{formatEuro(totalPT)}</span>
                </div>
              )}
            </div>
          )}
        </div>

      </div>

      {/* Recent Works */}
      <div>
        <h2 className="text-base font-semibold text-gray-800 mb-3">
          {__('Recent Works')}
        </h2>
        {upcomingWorks.length === 0 ? (
          <p className="text-sm text-gray-400">{__('No works found.')}</p>
        ) : (
          <Table
            columns={upcomingColumns}
            data={upcomingWorks}
            emptyMessage={__('No works found.')}
          />
        )}
      </div>
    </div>
  )
}
