import { useEffect, useState } from 'react'
import { Loader, XCircle, Lock } from 'lucide-react'
import { previewRestore } from '../../api/restore'
import { useVault } from '../../context/VaultContext'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import { datasetLabel, isSessionGone, sleep } from './restoreUtils'

/**
 * Step 3: compares the backup with the records on this site, batch by batch, and writes nothing. When it is over it
 * hands the counts and the list of records that need a look (everything that is not identical) to the wizard.
 * The values it receives for the differences are personal data: they live in this component's state and in the
 * wizard's state only, never in the query cache or in storage.
 */
export default function RestorePreview({ sessionId, onDone, onRetry, onChooseOther, onSessionGone }) {
  const { resetIdle } = useVault()
  const [phase, setPhase] = useState(null)
  const [error, setError] = useState(null) // { code, message }

  useEffect(() => {
    let stopped = false
    async function run() {
      let items = []
      let last = null
      let first = true
      while (!stopped) {
        let res
        try {
          res = await previewRestore(sessionId, first)
        } catch (err) {
          if (stopped) return
          if (err?.code === 'ftnc_restore_busy') {
            await sleep(1000)
            continue
          }
          if (isSessionGone(err)) {
            onSessionGone()
            return
          }
          setError({ code: err?.code, message: err?.message })
          return
        }
        if (stopped) return
        first = false
        last = res
        items = items.concat(res.items ?? [])
        if (res.phase) setPhase(res.phase)
        resetIdle()
        if (res.done) break
      }
      if (!stopped && last) {
        onDone({
          counts: last.counts,
          items,
          unimportable: last.unimportable ?? [],
          attachments: last.attachments ?? 0,
        })
      }
    }
    run()
    return () => { stopped = true }
    // The wizard starts the preview again by giving this component a new `key`, never by a re-render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessionId])

  if (error) {
    const locked = error.code === 'vault_locked'
    return (
      <div className="space-y-4">
        <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2 flex items-start gap-1.5">
          {locked ? <Lock size={12} className="shrink-0 mt-0.5" /> : <XCircle size={12} className="shrink-0 mt-0.5" />}
          {error.message || __('The backup could not be checked.', 'eleva-crm-for-photographers')}
        </p>
        <div className="flex gap-3">
          <Button variant="primary" onClick={onRetry}>
            {__('Try again', 'eleva-crm-for-photographers')}
          </Button>
          <Button variant="secondary" onClick={onChooseOther}>
            {__('Choose another backup', 'eleva-crm-for-photographers')}
          </Button>
        </div>
      </div>
    )
  }

  const percent = phase && phase.total ? Math.round((phase.done / phase.total) * 100) : 0
  return (
    <div className="space-y-3" role="status" aria-live="polite">
      <p className="text-sm text-gray-700 flex items-center gap-2">
        <Loader size={14} className="animate-spin" />
        {__('Checking your backup against the data on this site…', 'eleva-crm-for-photographers')}
      </p>
      <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
        <div className="h-full rounded-full bg-fotonic-primary transition-all" style={{ width: percent + '%' }} />
      </div>
      {phase && (
        <p className="text-xs text-gray-500">
          {sprintf(__('%1$s: %2$d of %3$d', 'eleva-crm-for-photographers'), datasetLabel(phase.dataset), phase.done, phase.total)}
        </p>
      )}
      <p className="text-xs text-gray-400">{__('Nothing is changed yet.', 'eleva-crm-for-photographers')}</p>
    </div>
  )
}
