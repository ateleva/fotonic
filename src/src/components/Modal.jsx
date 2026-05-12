import * as Dialog from '@radix-ui/react-dialog'
import { X } from 'lucide-react'

export default function Modal({ open, onClose, title, children }) {
  return (
    <Dialog.Root open={open} onOpenChange={(v) => !v && onClose()}>
      <Dialog.Portal container={document.getElementById('fotonic-app-root')}>
        <Dialog.Overlay className="fixed inset-0 bg-black/40 z-40 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
        <Dialog.Content
          className={[
            'fixed z-50 left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2',
            'bg-white rounded-lg shadow-xl w-full max-w-md p-6',
            'data-[state=open]:animate-in data-[state=closed]:animate-out',
            'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
          ].join(' ')}
        >
          <div className="flex items-center justify-between mb-4">
            <Dialog.Title className="text-base font-semibold text-gray-900">
              {title}
            </Dialog.Title>
            <Dialog.Close asChild>
              <button
                aria-label="Close"
                className="text-gray-400 hover:text-gray-600 transition-colors"
              >
                <X size={18} />
              </button>
            </Dialog.Close>
          </div>
          {children}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}
