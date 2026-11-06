import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { useVault } from '../../context/VaultContext'
import { runRestore, undoRestore } from '../../api/restore'
import FloatingProgress from '../../components/FloatingProgress'
import { __ } from '../../utils/i18n'
import {
  JOB_KEY, JOB_ERROR_KEY, JOB_FINISHED_KEY, useRestoreJob, useRestoreActions, useRestoreCell,
} from './restoreJob'
import { sleep } from './restoreUtils'

/**
 * Mounted once in the Layout. It is the only code that drives a restore (or its undo): one step per call until the
 * job is over, so the work goes on while the person moves around the app, and a reload picks it up again from the
 * server. It also shows the floating bar when the person is not on the restore screen.
 *
 * Rules: a busy answer (another tab holds the step) waits and tries again; a locked Vault stops quietly (the lock
 * screen takes over and the job goes on after the unlock); any other error stops the loop and is shown, and the
 * real state is read from the server.
 */
export default function RestoreHost() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const { resetIdle } = useVault()
  const { data: job } = useRestoreJob()
  const actions = useRestoreActions()
  const finishedHere = useRestoreCell(JOB_FINISHED_KEY, false)
  const [working, setWorking] = useState(false)

  const jobId = job?.job_id
  const driving = job?.status === 'running' || job?.status === 'undoing'

  useEffect(() => {
    if (!driving || !jobId) return undefined
    let stopped = false

    async function loop() {
      while (!stopped) {
        const current = queryClient.getQueryData(JOB_KEY)
        if (!current || (current.status !== 'running' && current.status !== 'undoing')) return
        let res
        try {
          res = current.status === 'undoing' ? await undoRestore(jobId) : await runRestore(jobId)
        } catch (err) {
          if (stopped) return
          if (err?.code === 'ftnc_restore_busy') {
            await sleep(1500)
            continue
          }
          if (err?.code === 'ftnc_restore_other_browser') {
            // Another person or browser drives this restore: only watch it (the job status is refreshed by its own query).
            queryClient.invalidateQueries({ queryKey: JOB_KEY })
            await sleep(5000)
            continue
          }
          if (err?.code === 'vault_locked') {
            queryClient.invalidateQueries({ queryKey: ['vault-status'] })
            return
          }
          queryClient.setQueryData(JOB_ERROR_KEY, err?.message || __('Something went wrong while restoring. Try again.', 'eleva-crm-for-photographers'))
          queryClient.invalidateQueries({ queryKey: JOB_KEY })
          return
        }
        if (stopped) return
        queryClient.setQueryData(JOB_ERROR_KEY, '')
        queryClient.setQueryData(JOB_KEY, res)
        resetIdle()
        if (res.status === 'done' || res.status === 'undone') {
          queryClient.setQueryData(JOB_FINISHED_KEY, true)
        }
      }
    }
    loop()
    return () => { stopped = true }
  }, [driving, jobId, queryClient, resetIdle])

  const onRestorePage = pathname.startsWith('/restore')
  if (!job || onRestorePage) return null

  const percent = job.percent ?? 0
  const open = () => navigate('/restore')

  async function toggle(fn) {
    setWorking(true)
    try {
      await fn(jobId)
    } catch {
      actions.refresh()
    } finally {
      setWorking(false)
    }
  }

  if (job.status === 'running') {
    return (
      <FloatingProgress
        title={__('Restoring your backup', 'eleva-crm-for-photographers')}
        detail={__('Keep working if you like. You can read your data, but saving is switched off until the restore has finished.', 'eleva-crm-for-photographers')}
        percent={percent}
        actionLabel={__('Pause', 'eleva-crm-for-photographers')}
        onAction={() => toggle(actions.pause)}
        busy={working}
      />
    )
  }
  if (job.status === 'paused') {
    return (
      <FloatingProgress
        title={__('Restore paused', 'eleva-crm-for-photographers')}
        detail={__('You can read your data. Saving is switched off until the restore has finished.', 'eleva-crm-for-photographers')}
        percent={percent}
        actionLabel={__('Resume', 'eleva-crm-for-photographers')}
        onAction={() => toggle(actions.resume)}
        busy={working}
      />
    )
  }
  if (job.status === 'undoing') {
    return (
      <FloatingProgress
        title={__('Undoing the restore', 'eleva-crm-for-photographers')}
        detail={__('Please wait. Your data is being put back as it was.', 'eleva-crm-for-photographers')}
        percent={percent}
      />
    )
  }
  if (job.status === 'error' || job.status === 'expired') {
    return (
      <FloatingProgress
        title={__('The restore needs your attention', 'eleva-crm-for-photographers')}
        detail={__('It stopped before the end. Open it to see what to do.', 'eleva-crm-for-photographers')}
        percent={percent}
        actionLabel={__('Open', 'eleva-crm-for-photographers')}
        onAction={open}
      />
    )
  }
  if (job.status === 'done' && finishedHere) {
    return (
      <FloatingProgress
        title={__('Restore finished', 'eleva-crm-for-photographers')}
        detail={__('Open the report to see what was restored.', 'eleva-crm-for-photographers')}
        percent={100}
        actionLabel={__('See the report', 'eleva-crm-for-photographers')}
        onAction={open}
      />
    )
  }
  return null
}
