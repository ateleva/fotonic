import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from '../../api/client'
import { openRestore, startRestore, closeRestore } from '../../api/restore'
import PageHeader from '../../components/PageHeader'
import Spinner from '../../components/Spinner'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import RestoreUpload from './RestoreUpload'
import RestoreCredentials from './RestoreCredentials'
import RestorePreview from './RestorePreview'
import RestoreMode from './RestoreMode'
import RestoreConflicts from './RestoreConflicts'
import RestoreConfirm from './RestoreConfirm'
import RestoreProgress from './RestoreProgress'
import RestoreReport from './RestoreReport'
import RestoreStepper from './RestoreStepper'
import { JOB_KEY, JOB_FINISHED_KEY, useRestoreJob } from './restoreJob'
import { isSessionGone, formatWhen } from './restoreUtils'
import { buildDecisions, summarize } from './restoreChoices'

// What each screen is called, and which of the five stages it belongs to (see RestoreStepper). The choice of how to
// restore and the differences are both stage 3.
const SCREENS = () => ({
  source: { stage: 1, title: __('Choose the backup', 'eleva-crm-for-photographers') },
  credentials: { stage: 1, title: __('Open the backup', 'eleva-crm-for-photographers') },
  preview: { stage: 2, title: __('Checking the backup', 'eleva-crm-for-photographers') },
  mode: { stage: 3, title: __('How to restore', 'eleva-crm-for-photographers') },
  conflicts: { stage: 3, title: __('What to do with the differences', 'eleva-crm-for-photographers') },
  confirm: { stage: 4, title: __('Last look', 'eleva-crm-for-photographers') },
})

/**
 * The restore screen (route #/restore). Five stages, always shown on top: backup (choose, open, credentials only when it
 * needs them), check against this site, your choices (how, then the differences when adding), last look, restore.
 * `?backup=<file>` (or, from Pro, `?source=drive&ref=<id>`) opens that backup straight away.
 * The state of the steps lives here and nowhere else, so leaving the page forgets it (and closes the opened backup
 * on the server). Once the restore has started the job takes over the screen, and survives a reload.
 */
export default function RestoreWizard() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const queryClient = useQueryClient()
  const { data: job, isLoading: jobLoading } = useRestoreJob()
  const { data: vault } = useQuery({ queryKey: ['vault-status'], queryFn: () => apiFetch('vault/status') })

  const [step, setStep] = useState('source')
  const [source, setSource] = useState(null)
  const [manifest, setManifest] = useState(null)
  const [session, setSession] = useState(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [credError, setCredError] = useState('')
  const [preview, setPreview] = useState(null)
  const [previewRun, setPreviewRun] = useState(0)
  const [mode, setMode] = useState('merge')
  const [typed, setTyped] = useState('')
  const [choices, setChoices] = useState({})
  const [newBulk, setNewBulk] = useState({})
  const [plain, setPlain] = useState(false)
  const [startError, setStartError] = useState(null)
  const [autoRemote, setAutoRemote] = useState(null)

  // What must be cleaned up when the person leaves without starting: the opened backup, or an upload never opened.
  const cleanup = useRef({ session: '', upload: '', started: false })
  useEffect(() => {
    const ref = cleanup.current
    return () => {
      if (ref.started) return
      if (ref.session) closeRestore(ref.session).catch(() => {})
      else if (ref.upload) closeRestore(ref.upload).catch(() => {})
    }
  }, [])

  function discardSource(current) {
    if (current?.kind === 'upload') closeRestore(current.uploadId).catch(() => {})
    cleanup.current.upload = ''
  }

  function reset(message = '') {
    if (cleanup.current.session) closeRestore(cleanup.current.session).catch(() => {})
    else discardSource(source)
    cleanup.current.session = ''
    cleanup.current.upload = ''
    setSource(null)
    setAutoRemote(null)
    setSession(null)
    setManifest(null)
    setPreview(null)
    setChoices({})
    setNewBulk({})
    setMode('merge')
    setTyped('')
    setPlain(false)
    setStartError(null)
    setCredError('')
    setMessage(message)
    setStep('source')
  }

  async function tryOpen(src, secret) {
    setBusy(true)
    setMessage('')
    setCredError('')
    try {
      const res = await openRestore(src, secret)
      cleanup.current.session = res.session_id
      cleanup.current.upload = ''
      setSession(res)
      setManifest(res.manifest)
      setPreview(null)
      setPreviewRun((n) => n + 1)
      setStep('preview')
    } catch (err) {
      if (err.code === 'ftnc_restore_credentials_needed') {
        setManifest(err.data?.manifest ?? null)
        setStep('credentials')
      } else if (err.code === 'ftnc_restore_wrong_credentials' || err.code === 'ftnc_restore_locked') {
        setCredError(err.message)
        setStep('credentials')
      } else {
        // The file is no good (or the server cannot open it): start over, and drop an upload that cannot be used.
        if (src.kind === 'upload') closeRestore(src.uploadId).catch(() => {})
        cleanup.current.upload = ''
        setSource(null)
        setMessage(err.message)
        setStep('source')
      }
    } finally {
      setBusy(false)
    }
  }

  function onChosen(src) {
    setAutoRemote(null) // a link's choice is used once
    setSource(src)
    cleanup.current.upload = src.kind === 'upload' ? src.uploadId : ''
    tryOpen(src, '')
  }

  function onPreviewDone(result) {
    setPreview(result)
    setStep('mode')
  }

  function nextFromMode() {
    if (mode === 'total' || preview.items.length === 0) {
      setStep('confirm')
    } else {
      setStep('conflicts')
    }
  }

  async function start() {
    setBusy(true)
    setStartError(null)
    try {
      const total = mode === 'total'
      const decisions = total ? {} : buildDecisions(preview.items, choices, newBulk)
      const options = {}
      if (plain) options.plain = true
      if (total) {
        options.total = true
        options.confirm = typed.trim() // the server is the real check of the word
      }
      const created = await startRestore(session.session_id, decisions, options)
      cleanup.current.started = true
      queryClient.setQueryData(JOB_FINISHED_KEY, false)
      queryClient.setQueryData(JOB_KEY, created)
    } catch (err) {
      if (isSessionGone(err)) {
        reset(__('The opened backup is no longer available. Choose it again.', 'eleva-crm-for-photographers'))
      } else if (err.code === 'ftnc_restore_decision_required' || err.code === 'ftnc_restore_bad_decision' || err.code === 'ftnc_restore_duplicate_target') {
        setStartError({ code: err.code, message: err.message })
        setStep(mode === 'total' ? 'confirm' : 'conflicts')
      } else {
        setStartError({ code: err.code, message: err.message })
      }
    } finally {
      setBusy(false)
    }
  }

  function goSetupVault() {
    reset()
    navigate('/settings?setup=1')
  }

  // A link from the Settings page (or from Pro) names the backup to open. It is read once (a ref, because StrictMode
  // runs an effect twice and a second open would leave the first session behind), taken off the address, and ignored
  // while a restore is still going on.
  const preselected = useRef(false)
  useEffect(() => {
    if (preselected.current || jobLoading) return
    const file = searchParams.get('backup')
    const remoteSource = searchParams.get('source')
    const remoteRef = searchParams.get('ref')
    if (!file && !(remoteSource && remoteRef)) return
    preselected.current = true
    setSearchParams({}, { replace: true })
    if (job && job.status !== 'dismissed') return
    if (remoteSource && remoteRef && /^[a-z0-9_-]{1,32}$/.test(remoteSource) && /^[A-Za-z0-9_-]{4,200}$/.test(remoteRef)) {
      queueMicrotask(() => setAutoRemote({ source: remoteSource, ref: remoteRef }))
      return
    }
    if (file && /^eleva-backup-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}-[a-f0-9]{8,32}\.zip$/.test(file)) {
      // Not in the effect body itself: opening starts state changes of its own.
      queueMicrotask(() => onChosen({ kind: 'server', file, name: file, size: 0 }))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [jobLoading])

  if (jobLoading) {
    return <div className="p-6 ftnc-pane"><Spinner /></div>
  }

  const showJob = Boolean(job) && job.status !== 'dismissed'
  const screens = SCREENS()
  let heading = __('Restore from a backup', 'eleva-crm-for-photographers')
  let body

  if (showJob) {
    // A restore is running, stopped, finished or just undone (and not yet closed by the person): it takes the screen.
    const hasEnded = ['done', 'error', 'expired', 'undone'].includes(job.status)
    body = hasEnded
      ? <RestoreReport job={job} onClose={() => navigate('/settings')} />
      : <RestoreProgress job={job} />
    heading = hasEnded ? __('Restore report', 'eleva-crm-for-photographers') : __('Restoring your backup', 'eleva-crm-for-photographers')
  } else if (step === 'source') {
    body = <RestoreUpload onChosen={onChosen} busy={busy} error={message} autoRemote={autoRemote} />
  } else if (step === 'credentials') {
    body = (
      <RestoreCredentials
        manifest={manifest}
        busy={busy}
        error={credError}
        onSubmit={(secret) => tryOpen(source, secret)}
      />
    )
  } else if (step === 'preview' && session) {
    body = (
      <RestorePreview
        key={previewRun}
        sessionId={session.session_id}
        onDone={onPreviewDone}
        onRetry={() => setPreviewRun((n) => n + 1)}
        onChooseOther={() => reset()}
        onSessionGone={() => reset(__('The opened backup is no longer available. Choose it again.', 'eleva-crm-for-photographers'))}
      />
    )
  } else if (step === 'mode' && session && preview) {
    body = (
      <RestoreMode
        session={session}
        preview={preview}
        mode={mode}
        onMode={setMode}
        typed={typed}
        onTyped={setTyped}
        onNext={nextFromMode}
      />
    )
  } else if (step === 'conflicts' && preview) {
    body = (
      <RestoreConflicts
        items={preview.items}
        choices={choices}
        onChoices={setChoices}
        newBulk={newBulk}
        onNewBulk={setNewBulk}
        error={startError}
        onBack={() => setStep('mode')}
        onNext={() => { setStartError(null); setStep('confirm') }}
      />
    )
  } else if (step === 'confirm' && session && preview) {
    body = (
      <RestoreConfirm
        mode={mode}
        vault={vault}
        plain={plain}
        onPlain={setPlain}
        preview={preview}
        counts={summarize(preview.items, choices, newBulk)}
        error={startError}
        busy={busy}
        onStart={start}
        onBack={() => setStep(mode === 'total' || preview.items.length === 0 ? 'mode' : 'conflicts')}
        onNewPreview={() => { setStartError(null); setPreview(null); setPreviewRun((n) => n + 1); setStep('preview') }}
        onSetupVault={goSetupVault}
      />
    )
  } else {
    body = <RestoreUpload onChosen={onChosen} busy={busy} error={message} />
  }

  // The stage the person is at. A restore that is running (or was, after a reload) is stage 5.
  const stage = showJob ? 5 : screens[step]?.stage ?? 1
  const jobEnded = showJob && ['done', 'undone'].includes(job.status)
  const panelTitle = showJob ? heading : screens[step]?.title
  const reviewNeeded = Boolean(preview) && mode !== 'total' && preview.items.length > 0
  const sourceName = manifest?.generated_at ? formatWhen(manifest.generated_at) : source?.name
  const showSource = !showJob && source && step !== 'source'

  return (
    <div className="p-6 ftnc-pane">
      <PageHeader title={__('Restore from a backup', 'eleva-crm-for-photographers')} backTo="/settings" backLabel={__('← Leave the restore', 'eleva-crm-for-photographers')} separator={false} />
      <RestoreStepper current={stage} allDone={jobEnded} jobPercent={showJob ? job.percent ?? 0 : null} />

      <div className="ftnc-panel">
        <div className="ftnc-panel__head">
          <h2 className="text-sm font-bold text-gray-800">{panelTitle}</h2>
        </div>
        <div className="ftnc-panel__body">
          {showSource && (
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md border border-gray-200 px-3 py-2">
              <p className="text-sm text-gray-700">
                {sourceName
                  ? sprintf(__('Backup: %s', 'eleva-crm-for-photographers'), sourceName)
                  : __('Backup chosen', 'eleva-crm-for-photographers')}
              </p>
              <Button size="sm" variant="secondary" disabled={busy} onClick={() => reset()}>
                {__('Choose another backup', 'eleva-crm-for-photographers')}
              </Button>
            </div>
          )}
          {step === 'mode' && !showJob && preview && !reviewNeeded && (
            <p className="mb-4 text-xs text-gray-500">
              {mode === 'total'
                ? __('No differences to go through: everything is replaced.', 'eleva-crm-for-photographers')
                : __('No differences to go through: everything in the backup is new or already the same.', 'eleva-crm-for-photographers')}
            </p>
          )}
          {body}
        </div>
      </div>
    </div>
  )
}
