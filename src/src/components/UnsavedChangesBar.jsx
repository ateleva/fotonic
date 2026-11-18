import { useEffect } from 'react'
import { AlertTriangle } from 'lucide-react'
import Button from './Button'
import { __ } from '../utils/i18n'

// Small bar at the top of the screen, shown when the admin tries to leave a record with changes that are not
// saved. Stay keeps the page (and the changes); Leave anyway drops them. Escape = Stay.
export default function UnsavedChangesBar({ onStay, onLeave }) {
  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onStay() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onStay])

  return (
    <div
      role="alertdialog"
      aria-live="assertive"
      aria-label={__('Unsaved changes.', 'eleva-crm-for-photographers')}
      className="fixed left-1/2 -translate-x-1/2 z-[99990] flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-lg"
      style={{ top: 'calc(var(--wp-admin--admin-bar--height, 32px) + 12px)', maxWidth: 'calc(100vw - 32px)' }}
    >
      <span className="flex items-center gap-2 text-sm font-medium text-gray-800">
        <AlertTriangle size={16} className="text-fotonic-primary" aria-hidden="true" />
        {__('Unsaved changes.', 'eleva-crm-for-photographers')}
      </span>
      <span className="flex items-center gap-2">
        <Button size="sm" autoFocus onClick={onStay}>{__('Stay', 'eleva-crm-for-photographers')}</Button>
        <Button size="sm" variant="secondary" onClick={onLeave}>{__('Leave anyway', 'eleva-crm-for-photographers')}</Button>
      </span>
    </div>
  )
}
