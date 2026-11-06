import { Check } from 'lucide-react'
import { __, sprintf } from '../../utils/i18n'

// The five stages of a restore, always on screen so the person knows how much there is to do and where they are.
// Stage 5 covers the running restore and its report. Colours: the theme colour for done and current, gray for what is
// still to come (nothing else, no colour per step).
const RESTORE_STAGES = () => [
  __('Backup', 'eleva-crm-for-photographers'),
  __('Check', 'eleva-crm-for-photographers'),
  __('Your choices', 'eleva-crm-for-photographers'),
  __('Last look', 'eleva-crm-for-photographers'),
  __('Restore', 'eleva-crm-for-photographers'),
]

/**
 * `current` is 1 to 5. `allDone` fills every stage (the restore is finished). `jobPercent` (0 to 100) fills the part of the
 * bar that belongs to stage 5 while the restore runs.
 */
export default function RestoreStepper({ current, allDone = false, jobPercent = null }) {
  const labels = RESTORE_STAGES()
  const total = labels.length
  const done = allDone ? total : current - 1
  const part = !allDone && current === total && jobPercent !== null ? Math.max(0, Math.min(100, jobPercent)) / 100 : 0
  const percent = Math.round(((done + part) / total) * 100)

  return (
    <nav aria-label={__('Restore steps', 'eleva-crm-for-photographers')} className="mb-6">
      <div className="flex items-center justify-between mb-2">
        <p className="text-xs text-gray-500">
          {allDone
            ? __('All steps done', 'eleva-crm-for-photographers')
            : sprintf(__('Step %1$d of %2$d: %3$s', 'eleva-crm-for-photographers'), current, total, labels[current - 1])}
        </p>
        <p className="text-xs font-medium text-gray-600">{percent}%</p>
      </div>
      <div
        className="h-2 w-full overflow-hidden rounded-full bg-gray-100"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent}
      >
        <div className="h-full rounded-full bg-fotonic-primary transition-all" style={{ width: percent + '%' }} />
      </div>
      <ol className="mt-3 grid grid-cols-5 gap-1">
        {labels.map((label, i) => {
          const n = i + 1
          const isDone = allDone || n < current
          const isCurrent = !allDone && n === current
          return (
            <li key={label} className="flex flex-col items-center text-center gap-1" aria-current={isCurrent ? 'step' : undefined}>
              {/* Current and finished stages are the same filled circle with the number in white; a finished one also
                  carries a small tick at its top right corner. */}
              <span
                className={[
                  'relative flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold border',
                  isDone || isCurrent ? 'bg-fotonic-primary border-fotonic-primary text-white' : 'bg-white border-gray-200 text-gray-400',
                ].join(' ')}
              >
                {n}
                {isDone && (
                  <span
                    className="absolute -top-1 -right-1 flex h-3.5 w-3.5 items-center justify-center rounded-full border border-fotonic-primary bg-white text-fotonic-primary"
                    aria-label={__('Done', 'eleva-crm-for-photographers')}
                  >
                    <Check size={9} strokeWidth={3.5} aria-hidden="true" />
                  </span>
                )}
              </span>
              <span className={['text-xs leading-tight', isCurrent ? 'font-bold text-gray-900' : isDone ? 'text-gray-700' : 'text-gray-400'].join(' ')}>
                {label}
              </span>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
