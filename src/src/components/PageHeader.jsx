import { useNavigate } from 'react-router-dom'
import { __ } from '../utils/i18n'
import Button from './Button'

export default function PageHeader({ title, action, backTo, onDelete }) {
  const navigate = useNavigate()
  return (
    <div className="flex items-center gap-4 mb-6">
      {backTo && (
        <Button variant="secondary" size="sm" onClick={() => navigate(backTo)}>
          {__('← Back')}
        </Button>
      )}
      <h1 className="text-xl font-semibold text-gray-900">{title}</h1>
      {action && <div className="ml-auto">{action}</div>}
      {onDelete && (
        <Button variant="danger" size="sm" className="ml-auto" onClick={onDelete}>
          {__('Delete')}
        </Button>
      )}
    </div>
  )
}
