import { useState } from 'react'
import { Link } from 'react-router-dom'
import { AlertTriangle } from 'lucide-react'
import { useShotsHistory } from '../../api/backup-locations'
import Table from '../../components/Table'
import Spinner from '../../components/Spinner'
import Pagination from '../../components/Pagination'
import { usePagedItems } from '../../utils/pagination'
import { formatDate } from '../../utils/date'
import { __ } from '../../utils/i18n'

const fmt = (n) => (n == null ? null : Number(n).toLocaleString('it-IT'))

export default function ShotsHistory() {
  const [year, setYear] = useState('')
  const { data, isLoading } = useShotsHistory(year)
  const allRows = data?.data ?? []
  const paged = usePagedItems(allRows)
  const rows = paged.pageItems
  const years = data?.years ?? []
  const totals = data?.totals ?? { works: 0, taken: 0, delivered: 0 }

  const dash = <span className="text-gray-400">-</span>

  const columns = [
    {
      key: 'title',
      label: __('Work', 'eleva-crm-for-photographers'),
      render: (row) => (
        <Link to={`/works/${row.id}`} className="font-medium text-fotonic-primary underline hover:opacity-80">
          {row.title || __('(no title)', 'eleva-crm-for-photographers')}
        </Link>
      ),
    },
    {
      key: 'event_date',
      label: __('Date', 'eleva-crm-for-photographers'),
      render: (row) => formatDate(row.event_date),
    },
    {
      key: 'shots_taken',
      label: __('Shots taken', 'eleva-crm-for-photographers'),
      render: (row) => fmt(row.shots_taken) ?? dash,
    },
    {
      key: 'shots_delivered',
      label: __('Photos delivered', 'eleva-crm-for-photographers'),
      render: (row) => {
        const over = row.shots_taken != null && row.shots_delivered != null && row.shots_delivered > row.shots_taken
        return (
          <span className="inline-flex items-center gap-1.5">
            {fmt(row.shots_delivered) ?? dash}
            {over && (
              <span
                className="inline-flex text-amber-600"
                role="img"
                title={__('More photos delivered than taken. Check the two numbers in the work.', 'eleva-crm-for-photographers')}
                aria-label={__('More photos delivered than taken. Check the two numbers in the work.', 'eleva-crm-for-photographers')}
              >
                <AlertTriangle size={14} />
              </span>
            )}
          </span>
        )
      },
    },
  ]

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <label className="flex items-center gap-2 text-sm text-gray-600">
          {__('Year', 'eleva-crm-for-photographers')}
          <select
            value={year}
            onChange={(e) => { setYear(e.target.value); paged.setPage(1) }}
            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            style={{ width: 'auto', minWidth: 'max-content', paddingRight: '2rem' }}
          >
            <option value="">{__('All years', 'eleva-crm-for-photographers')}</option>
            {years.map((y) => (
              <option key={y} value={y}>{y}</option>
            ))}
          </select>
        </label>
        <p className="text-sm text-gray-600">
          {__('Works', 'eleva-crm-for-photographers')}: <strong className="text-gray-900">{fmt(totals.works)}</strong>
          <span className="mx-2 text-gray-300" aria-hidden="true">|</span>
          {__('Shots taken', 'eleva-crm-for-photographers')}: <strong className="text-gray-900">{fmt(totals.taken)}</strong>
          <span className="mx-2 text-gray-300" aria-hidden="true">|</span>
          {__('Photos delivered', 'eleva-crm-for-photographers')}: <strong className="text-gray-900">{fmt(totals.delivered)}</strong>
        </p>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : (
        <Table
          columns={columns}
          data={rows}
          emptyMessage={__('No shot counts yet. Add them in the Shots section of a work.', 'eleva-crm-for-photographers')}
        />
      )}

      <Pagination page={paged.page} pages={paged.pages} total={paged.total} size={paged.size} onPage={paged.setPage} />
    </>
  )
}
