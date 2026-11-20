import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Pencil, PlusCircle, Settings2, AlertTriangle } from 'lucide-react'
import { useWorks, useWorkYears } from '../../api/works'
import { pickDefaultYear } from '../../utils/years'
import { formatDate } from '../../utils/date'
import PageHeader from '../../components/PageHeader'
import Button from '../../components/Button'
import { useWhatsNew } from '../../api/whatsNew'
import Table from '../../components/Table'
import Badge from '../../components/Badge'
import Spinner from '../../components/Spinner'
import Pagination from '../../components/Pagination'
import { WORKS_PAGE_SIZE } from '../../utils/pagination'
import PaymentTypeManager from './PaymentTypeManager'
import { __ } from '../../utils/i18n'
import { titleOr } from '../../utils/titles'

// The reminders overview is a Pro page: its button (with the due count) comes from Pro, behind the
// same two guards as the route in router.jsx.
const RemindersButton = window.FotonicApp?.features?.kanban && window.FotonicProComponents?.RemindersList
  ? window.FotonicProComponents?.RemindersButton ?? null
  : null

// Pro slots of the list: the collaborator icon before a title, and the Owner select. Both are absent without Pro.
// Same two guards as the Collaborators route: the feature is on AND the component exists (an expired license keeps the
// components but not the route the icon opens).
const WorkOwnerIcon = window.FotonicApp?.features?.collaborators ? window.FotonicProComponents?.WorkOwnerIcon ?? null : null
const WorkOwnerFilter = window.FotonicApp?.features?.collaborators ? window.FotonicProComponents?.WorkOwnerFilter ?? null : null

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
  const [alertFilter, setAlertFilter] = useState(false)
  // null = the person has not chosen yet: the list opens on the current year (or the nearest one with works). '' = every year.
  const [yearChoice, setYearChoice] = useState(null)
  const [owner, setOwner] = useState('')
  const [page, setPage] = useState(1)
  const [typesOpen, setTypesOpen] = useState(false)
  // Since 1.6.6 the payment types hold the extra percentage (Rivalsa INPS): while the notice that says so is
  // unread, the button is outlined, so it is the first thing seen on this page.
  const { data: whatsNew } = useWhatsNew()
  const highlightTypes = (whatsNew?.entries ?? []).some((e) => e.section === 'works' && /:works-payment-types$/.test(e.id))

  const { data: yearsData, isError: yearsFailed } = useWorkYears()
  const years = yearsData?.years
  // Still null while the years are loading: the first request of the list waits for it, so it never flashes every year.
  // If the years cannot be read (an older free plugin, a network error) the list still opens, on the current year.
  const year = yearChoice ?? (years ? pickDefaultYear(years, new Date().getFullYear()) : yearsFailed ? String(new Date().getFullYear()) : null)
  const yearOptions = years ? (year && !years.includes(year) ? [...years, year].sort().reverse() : years) : []

  const params = {}
  if (search) params.search = search
  if (paymentStatus) params.payment_status = paymentStatus
  if (year) params.year = year
  if (owner) params.owner = owner

  // The server sorts (newest event first) and cuts the pages: the browser only ever holds one page.
  const alertParams = { ...params, page, per_page: WORKS_PAGE_SIZE, order: 'desc', ...(alertFilter ? { storage_alert: true } : {}) }
  const { data, isLoading } = useWorks(alertParams, { enabled: year !== null })
  // Every work with a warning, whatever the other filters. The button stays while the filter is on, even at 0,
  // so the person can switch it off.
  const alertCount = data?.alert_count
  const alertsOn = alertFilter

  const rawWorks = Array.isArray(data) ? data : data?.data ?? []

  const works = rawWorks

  const columns = [
    {
      key: 'title',
      label: __('Title', 'eleva-crm-for-photographers'),
      render: (row) => (
        <span className="inline-flex items-center gap-3">
          {/* With Pro, every title keeps the same room on its left (22px, like the lists of collaborators and quotes), so the
              titles line up whether or not the work has the collaborator icon. */}
          {WorkOwnerIcon && (
            <span className="inline-flex items-center justify-center" style={{ width: 22, flexShrink: 0 }}>
              <WorkOwnerIcon row={row} />
            </span>
          )}
          <button
            type="button"
            className="border-0 bg-transparent p-0 text-left font-medium text-fotonic-primary underline cursor-pointer hover:opacity-80"
            onClick={() => navigate(`/works/${row.id}`)}
          >
            {titleOr(row.title, 'work', row.id)}
          </button>
          {row.storage_alert && (
            <span
              className="inline-flex items-center justify-center rounded-full bg-red-600 text-white"
              style={{ width: 22, height: 22 }}
              title={__('A backup location of this work was deleted. Open the work to choose another one.', 'eleva-crm-for-photographers')}
              aria-label={__('A backup location of this work was deleted. Open the work to choose another one.', 'eleva-crm-for-photographers')}
              role="img"
            >
              <AlertTriangle size={13} />
            </span>
          )}
          {row.customer_state && (
            // Same red sign as the backup location alert: the customer of this work is in the Bin (or gone), so the work
            // needs a look. Opening it shows the field with the reason.
            <span
              className="inline-flex items-center justify-center rounded-full bg-red-600 text-white"
              style={{ width: 22, height: 22 }}
              title={row.customer_state === 'trash'
                ? __('The customer of this work is in the Bin. Open the work to choose another customer, or restore this one from the Bin.', 'eleva-crm-for-photographers')
                : __('The customer of this work no longer exists. Open the work to choose another customer.', 'eleva-crm-for-photographers')}
              aria-label={row.customer_state === 'trash'
                ? __('The customer of this work is in the Bin. Open the work to choose another customer, or restore this one from the Bin.', 'eleva-crm-for-photographers')
                : __('The customer of this work no longer exists. Open the work to choose another customer.', 'eleva-crm-for-photographers')}
              role="img"
            >
              <AlertTriangle size={13} />
            </span>
          )}
        </span>
      ),
    },
    {
      key: 'event_date',
      label: __('Date', 'eleva-crm-for-photographers'),
      render: (row) => formatDate(row.event_date),
    },
    {
      key: 'customer',
      label: __('Customer', 'eleva-crm-for-photographers'),
      render: (row) => {
        const name = row.customer_title === null ? titleOr(null, 'customer', row.customer_id) : row.customer_title ?? <span className="text-gray-400">—</span>
        // The name opens the customer, unless that customer is in the Bin or gone (the red sign on the title says so).
        if (!row.customer_id || row.customer_state) return name
        return (
          <button
            type="button"
            className="border-0 bg-transparent p-0 text-left text-fotonic-primary underline cursor-pointer hover:opacity-80"
            onClick={() => navigate(`/customers/${row.customer_id}`)}
          >
            {name}
          </button>
        )
      },
    },
    {
      key: 'payment_status',
      label: __('Payment', 'eleva-crm-for-photographers'),
      render: (row) => <Badge status={row.payment_status ?? 'unpaid'} />,
    },
    {
      key: 'total_price',
      label: __('Total', 'eleva-crm-for-photographers'),
      render: (row) => formatPrice(row.total_price),
    },
    {
      key: 'actions',
      label: __('Actions', 'eleva-crm-for-photographers'),
      sticky: 'right',
      render: (row) => (
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => navigate(`/works/${row.id}`)}
            aria-label={__('Edit work', 'eleva-crm-for-photographers')}
            title={__('Edit work', 'eleva-crm-for-photographers')}
          >
            <Pencil size={14} />
          </Button>
        </div>
      ),
    },
  ]

  return (
    <div className="p-6">
      <PageHeader
        helpSection="works"
        title={__('Works', 'eleva-crm-for-photographers')}
        trashType="ftnc_work"
        action={
          <div className="flex flex-wrap items-center justify-end gap-2">
            {RemindersButton && <RemindersButton />}
            <Button
              variant="secondary"
              onClick={() => setTypesOpen(true)}
              className={highlightTypes ? 'ftnc-attention' : undefined}
            >
              <Settings2 size={14} />
              {__('Manage payment types', 'eleva-crm-for-photographers')}
            </Button>
            <Button onClick={() => navigate('/works/new')}>
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
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
          placeholder={__('Search works...', 'eleva-crm-for-photographers')}
          className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-64"
        />
        <select
          value={year ?? ''}
          onChange={(e) => { setYearChoice(e.target.value); setPage(1) }}
          aria-label={__('Year', 'eleva-crm-for-photographers')}
          className="rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          style={{ width: 'auto', minWidth: 'max-content', paddingRight: '2rem' }}
        >
          <option value="">{__('All years', 'eleva-crm-for-photographers')}</option>
          {yearOptions.map((y) => <option key={y} value={y}>{y}</option>)}
        </select>
        {WorkOwnerFilter && <WorkOwnerFilter value={owner} onChange={(v) => { setOwner(v); setPage(1) }} />}
        <select
          value={paymentStatus}
          onChange={(e) => { setPaymentStatus(e.target.value); setPage(1) }}
          className="rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          style={{ width: 'auto', minWidth: 'max-content', paddingRight: '2rem' }}
        >
          <option value="">{__('All Statuses', 'eleva-crm-for-photographers')}</option>
          <option value="paid">{__('Paid', 'eleva-crm-for-photographers')}</option>
          <option value="partial">{__('Partial', 'eleva-crm-for-photographers')}</option>
          <option value="unpaid">{__('Unpaid', 'eleva-crm-for-photographers')}</option>
        </select>
        {(alertCount > 0 || alertsOn) && (
          <button
            type="button"
            aria-pressed={alertsOn}
            onClick={() => {
              // The warnings count covers every year, so the list must too while this is on.
              if (!alertsOn) setYearChoice('')
              setAlertFilter(!alertsOn)
              setPage(1)
            }}
            className={[
              'inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
              alertsOn
                ? 'border-red-600 bg-red-600 text-white'
                : 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100',
            ].join(' ')}
          >
            <AlertTriangle size={14} />
            {__('Works with warnings', 'eleva-crm-for-photographers')} ({alertCount ?? 0})
          </button>
        )}
      </div>

      {isLoading || year === null ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : (
        <Table
          columns={columns}
          data={works}
          emptyMessage={alertsOn ? __('No works with warnings.', 'eleva-crm-for-photographers') : (WorkOwnerFilter ? __('No works found. Check the Year, Owner and Status filters, then try your search again.', 'eleva-crm-for-photographers') : __('No works found. Check the Year and Status filters, then try your search again.', 'eleva-crm-for-photographers'))}
        />
      )}

      <Pagination page={page} pages={data?.pages ?? 1} total={data?.total ?? 0} size={WORKS_PAGE_SIZE} onPage={setPage} />

      <PaymentTypeManager open={typesOpen} onClose={() => setTypesOpen(false)} />

    </div>
  )
}
