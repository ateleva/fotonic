import { ArrowUpCircle } from 'lucide-react'
import Modal from './Modal'
import Button from './Button'
import { __ } from '../utils/i18n'
import { pendingUpdates } from '../utils/updates'

// The button of the rail. It is a `.ftnc-navlink` like Settings and Help, so it sits in the same column and has the
// same height with the rail open or collapsed (the label folds away with the others); `.ftnc-update-btn` in index.css
// only adds the moving glow that makes it hard to miss.
export function UpdateButton({ className, onOpen, tipProps }) {
  const label = __('Update available', 'eleva-crm-for-photographers')
  return (
    <button
      type="button"
      onClick={onOpen}
      className={`${className} ftnc-update-btn cursor-pointer w-full text-left`}
      aria-label={label}
      {...tipProps(label)}
    >
      <ArrowUpCircle size={16} />
      <span>{label}</span>
    </button>
  )
}

export function UpdateModal({ open, onClose }) {
  const items = pendingUpdates()
  const url = window.FotonicApp?.updates?.url ?? ''
  const notes = items.map((i) => i.note).filter(Boolean)
  return (
    <Modal open={open} onClose={onClose} title={__('A new version is available', 'eleva-crm-for-photographers')} size="md">
      <ul className="space-y-2 mb-4">
        {items.map((i) => (
          <li key={i.id} className="flex items-baseline justify-between gap-3 text-sm">
            <span className="font-semibold text-gray-900">{i.name}</span>
            <span className="text-gray-500">{i.current} → {i.latest}</span>
          </li>
        ))}
      </ul>
      <p className="text-sm text-gray-700 mb-2">
        {__('Updating is quick: in the page that opens, find the plugin in the list and press Update now.', 'eleva-crm-for-photographers')}
      </p>
      {notes.map((n) => (
        <p key={n} className="text-sm text-gray-700 mb-2">{n}</p>
      ))}
      <div className="flex justify-end gap-2 mt-5">
        <Button variant="secondary" onClick={onClose}>{__('Not now', 'eleva-crm-for-photographers')}</Button>
        <Button variant="primary" onClick={() => { window.location.href = url }}>{__('Go to updates', 'eleva-crm-for-photographers')}</Button>
      </div>
    </Modal>
  )
}
