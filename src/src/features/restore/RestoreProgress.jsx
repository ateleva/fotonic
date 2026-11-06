import { useState } from 'react'
import { Loader, XCircle } from 'lucide-react'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import { JOB_ERROR_KEY, useRestoreActions, useRestoreCell } from './restoreJob'

function stepText(job) {
  if (job.status === 'undoing' || job.step === 'undo') return __('Putting your data back as it was…', 'eleva-crm-for-photographers')
  switch (job.step) {
    case 'safety': return __('Saving a safety copy of your current data…', 'eleva-crm-for-photographers')
    case 'wipe': return __('Removing the current data…', 'eleva-crm-for-photographers')
    case 'options': return __('Finishing…', 'eleva-crm-for-photographers')
    default: return __('Restoring your data…', 'eleva-crm-for-photographers')
  }
}

function etaText(seconds) {
  if (!seconds || seconds < 0) return ''
  if (seconds < 60) return __('Less than a minute left.', 'eleva-crm-for-photographers')
  return sprintf(__('About %d min left.', 'eleva-crm-for-photographers'), Math.ceil(seconds / 60))
}

/**
 * The restore (or its undo) while it runs. RestoreHost does the work; this card only shows it and offers Pause and
 * Resume. The same job is shown as a floating bar on the other pages.
 */
export default function RestoreProgress({ job }) {
  const actions = useRestoreActions()
  const loopError = useRestoreCell(JOB_ERROR_KEY, '')
  const [working, setWorking] = useState(false)
  const percent = job.percent ?? 0

  async function toggle(fn) {
    setWorking(true)
    try { await fn(job.job_id) } catch { actions.refresh() } finally { setWorking(false) }
  }

  const paused = job.status === 'paused'
  return (
    <div className="space-y-4" role="status" aria-live="polite">
      <p className="text-sm text-gray-700 flex items-center gap-2">
        {!paused && <Loader size={14} className="animate-spin" />}
        {paused ? __('The restore is paused.', 'eleva-crm-for-photographers') : stepText(job)}
      </p>
      <div className="flex items-center gap-3">
        <div className="h-2 flex-1 overflow-hidden rounded-full bg-gray-100">
          <div className="h-full rounded-full bg-fotonic-primary transition-all" style={{ width: percent + '%' }} />
        </div>
        <span className="text-sm font-medium text-gray-700">{percent}%</span>
      </div>
      {!paused && etaText(job.eta_seconds) && <p className="text-xs text-gray-500">{etaText(job.eta_seconds)}</p>}
      <p className="text-xs text-gray-500">
        {__('You can read your data, but saving is switched off until this has finished. You can leave this page: it keeps going, and a bar shows how far it is.', 'eleva-crm-for-photographers')}
      </p>
      {loopError && (
        <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2 flex items-start gap-1.5">
          <XCircle size={12} className="shrink-0 mt-0.5" />{loopError}
        </p>
      )}
      {job.status !== 'undoing' && (
        <Button variant="secondary" disabled={working} onClick={() => toggle(paused ? actions.resume : actions.pause)}>
          {paused ? __('Resume', 'eleva-crm-for-photographers') : __('Pause', 'eleva-crm-for-photographers')}
        </Button>
      )}
    </div>
  )
}
