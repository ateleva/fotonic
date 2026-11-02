import { useRef } from 'react'
import * as Dialog from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import useModalScroll from '../hooks/useModalScroll'

// Windows sit above the WordPress admin bar (z-index 99999) and menu (9990): the backdrop covers the whole screen,
// so the page behind is never half dimmed.
const SIZE_CLASSES = {
  md: 'max-w-md p-6',
  lg: 'max-w-xl p-6',
  xl: 'max-w-2xl p-6',
  full: 'max-w-5xl w-[90vw] h-[85vh] p-6 flex flex-col',
}

// `scroll`: the whole window scrolls when it is taller than the screen (wheel or trackpad anywhere over it, and the
// keyboard), and it takes the focus the moment it opens, so nothing has to be clicked first.
export default function Modal({ open, onClose, title, size = 'md', headerAction = null, scroll = false, children }) {
  const contentRef = useRef(null)
  const noRef = useRef(null)
  useModalScroll(scroll ? contentRef : noRef)

  return (
    <Dialog.Root open={open} onOpenChange={(v) => !v && onClose()}>
      <Dialog.Portal container={document.getElementById('fotonic-app-root')}>
        <Dialog.Overlay className="fixed inset-0 bg-black/40 z-[100000] data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
        <Dialog.Content
          ref={contentRef}
          // By default the first button (the close X) takes the focus, which shows nothing and leaves the keyboard
          // unable to scroll. A scrolling window takes the focus itself.
          onOpenAutoFocus={(e) => {
            if (scroll && contentRef.current) {
              e.preventDefault()
              contentRef.current.focus({ preventScroll: true })
            }
          }}
          className={[
            'fixed z-[100001] left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2',
            'bg-white rounded-lg shadow-xl w-full',
            SIZE_CLASSES[size],
            scroll ? 'max-h-[88vh] overflow-y-auto outline-none' : '',
            'data-[state=open]:animate-in data-[state=closed]:animate-out',
            'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
          ].join(' ')}
        >
          <div className="flex items-center justify-between mb-4 shrink-0">
            <Dialog.Title className="text-base font-semibold text-gray-900">
              {title}
            </Dialog.Title>
            <div className="flex items-center gap-3">
              {headerAction}
              <Dialog.Close asChild>
                <button
                  aria-label="Close"
                  className="text-gray-400 hover:text-gray-600 transition-colors"
                >
                  <X size={18} />
                </button>
              </Dialog.Close>
            </div>
          </div>
          {size === 'full' ? (
            <div className="flex-1 min-h-0">{children}</div>
          ) : (
            children
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}
