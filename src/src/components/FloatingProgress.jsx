import Button from './Button'

/**
 * A small card over the corner of the screen (it never moves the page) that shows the progress of a
 * long job, with a button to pause or resume it. It must be rendered inside #fotonic-app-root: the
 * Tailwind classes are scoped to that root.
 */
export default function FloatingProgress({ title, detail, percent = 0, actionLabel, onAction, busy = false }) {
  const pct = Math.max(0, Math.min(100, Math.round(percent)))
  return (
    <div
      role="status"
      aria-live="polite"
      className="fixed bottom-6 right-6 z-[9999] w-80 rounded-xl border border-gray-200 bg-white p-4 shadow-lg"
    >
      <div className="flex items-start justify-between gap-3">
        <p className="text-sm font-semibold text-gray-900">{title}</p>
        <span className="text-sm font-medium text-gray-700">{pct}%</span>
      </div>
      <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
        <div className="h-full rounded-full bg-fotonic-primary transition-all" style={{ width: pct + '%' }} />
      </div>
      {detail && <p className="mt-2 text-xs text-gray-500">{detail}</p>}
      {onAction && (
        <div className="mt-3 flex justify-end">
          <Button size="sm" variant="secondary" onClick={onAction} disabled={busy}>
            {actionLabel}
          </Button>
        </div>
      )}
    </div>
  )
}
