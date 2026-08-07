import Modal from './Modal'
import Button from './Button'
import { __ } from '../utils/i18n'

export default function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  message,
  confirmLabel,
  title,
}) {
  const resolvedMessage = message ?? __('Are you sure you want to delete this item? This action cannot be undone.', 'eleva-crm-for-photographers')
  const resolvedConfirmLabel = confirmLabel ?? __('Delete', 'eleva-crm-for-photographers')
  const resolvedTitle = title ?? __('Confirm Delete', 'eleva-crm-for-photographers')

  function handleConfirm() {
    onConfirm()
    onClose()
  }

  return (
    <Modal open={open} onClose={onClose} title={resolvedTitle}>
      <p className="text-sm text-gray-600 mb-6">{resolvedMessage}</p>
      <div className="flex justify-end gap-3">
        <Button variant="secondary" onClick={onClose}>
          {__('Cancel', 'eleva-crm-for-photographers')}
        </Button>
        <Button variant="danger" onClick={handleConfirm}>
          {resolvedConfirmLabel}
        </Button>
      </div>
    </Modal>
  )
}
