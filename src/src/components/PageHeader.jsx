import { useNavigate } from 'react-router-dom'
import { __ } from '../utils/i18n'
import Button from './Button'
import TrashShortcut from './TrashShortcut'

// `trashType`: a record type; when one is in the Bin, a small Bin button appears after `action` (always the rightmost).
export default function PageHeader({ title, action, backTo, backLabel, onDelete, separator = true, trashType }) {
  const navigate = useNavigate()
  return (
    <div className={`flex items-center gap-4 mb-6 ftnc-head-wrap${separator ? '' : ' ftnc-head-nosep'}`}>
      {backTo && (
        <Button variant="secondary" size="sm" onClick={() => navigate(backTo)}>
          {backLabel ?? __('← Back', 'eleva-crm-for-photographers')}
        </Button>
      )}
      <h1 className="text-xl font-semibold text-gray-900">{title}</h1>
      {(action || trashType) && (
        <div className="ml-auto ftnc-head-actions flex flex-wrap items-center justify-end gap-2">
          {action}
          {trashType && <TrashShortcut type={trashType} />}
        </div>
      )}
      {onDelete && (
        <Button variant="danger" size="sm" className="ml-auto" onClick={onDelete}>
          {__('Delete', 'eleva-crm-for-photographers')}
        </Button>
      )}
    </div>
  )
}
