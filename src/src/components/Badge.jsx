import { __ } from '../utils/i18n'

const styles = {
  paid:    'bg-green-100 text-green-800',
  partial: 'bg-yellow-100 text-yellow-800',
  unpaid:  'bg-red-100 text-red-800',
}

export default function Badge({ status }) {
  const labels = {
    paid:    __('Paid', 'eleva-crm-for-photographers'),
    partial: __('Partial', 'eleva-crm-for-photographers'),
    unpaid:  __('Unpaid', 'eleva-crm-for-photographers'),
  }
  const s = status?.toLowerCase() ?? 'unpaid'
  return (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${styles[s] ?? 'bg-gray-100 text-gray-700'}`}
    >
      {labels[s] ?? status}
    </span>
  )
}
