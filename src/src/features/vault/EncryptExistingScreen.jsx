import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Circle, Loader2 } from 'lucide-react'
import { useVault } from '../../context/VaultContext'
import { encryptExistingStep, pauseEncrypt, cancelEncrypt } from '../../api/vault'
import { PHASES, stepIndex, etaParts, mergeEncrypt } from '../../utils/vaultFlow'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'

const BUSY_WAIT_MS = 1500

function stepLabel(phase) {
  switch (phase) {
    case 'scan': return __('Reading your data', 'eleva-crm-for-photographers')
    case 'analyze': return __('Analyzing your data', 'eleva-crm-for-photographers')
    case 'prepare': return __('Preparing the encryption', 'eleva-crm-for-photographers')
    case 'encrypt': return __('Encrypting your data', 'eleva-crm-for-photographers')
    default: return __('Finishing the encryption', 'eleva-crm-for-photographers')
  }
}

function errorText(code, fallback) {
  if (code === 'self_test_failed') {
    return __('The Vault key could not encrypt a test text. Try again. If it keeps failing, cancel and ask for help.', 'eleva-crm-for-photographers')
  }
  if (code === 'incomplete') {
    return __('Some data could not be encrypted after several tries. Try again, or cancel and continue later.', 'eleva-crm-for-photographers')
  }
  return fallback || __('Something went wrong while encrypting. Try again.', 'eleva-crm-for-photographers')
}

function etaText(seconds) {
  const eta = etaParts(seconds)
  if (!eta) return ''
  if (eta.unit === 'less') return __('Less than a minute left.', 'eleva-crm-for-photographers')
  return sprintf(__('About %d min left.', 'eleva-crm-for-photographers'), eta.n)
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * The full screen that encrypts the data entered before the Vault (or before a release added protected
 * fields). It has no close button: leaving it half way is safe (the job continues where it stopped), but
 * while it runs nothing may be edited. Pause turns it into the floating bar; "Cancel and continue later"
 * is offered only on an error, and hides the screen for this session.
 *
 * Exactly one loop runs per tab, started by Start, Resume, Retry or by opening the screen while the job is
 * already running, and stopped when the tab unmounts.
 */
export default function EncryptExistingScreen({ encrypt, onDismiss }) {
  const queryClient = useQueryClient()
  const { resetIdle } = useVault()
  const resetIdleRef = useRef(resetIdle)
  useEffect(() => { resetIdleRef.current = resetIdle }, [resetIdle])

  const [driving, setDriving] = useState(encrypt?.status === 'running')
  const [localError, setLocalError] = useState(null)
  const [working, setWorking] = useState(false)

  useEffect(() => {
    if (!driving) return undefined
    let stopped = false

    async function loop() {
      while (!stopped) {
        let res
        try {
          res = await encryptExistingStep()
        } catch (err) {
          if (stopped) return
          setDriving(false)
          // A locked Vault shows the lock screen; after the unlock a running job continues by itself.
          if (err?.code === 'vault_locked') {
            queryClient.invalidateQueries({ queryKey: ['vault-status'] })
            return
          }
          setLocalError(err?.message || __('Something went wrong while encrypting. Try again.', 'eleva-crm-for-photographers'))
          return
        }
        if (stopped) return
        queryClient.setQueryData(['vault-status'], (old) => mergeEncrypt(old, res))
        resetIdleRef.current?.() // a long job must not be cut by the 15 minute idle lock
        if (res.status === 'done' || res.status === 'none') {
          setDriving(false)
          queryClient.invalidateQueries({ queryKey: ['vault-status'] })
          return
        }
        if (res.status === 'paused' || res.status === 'error') {
          setDriving(false)
          return
        }
        if (res.busy) await sleep(BUSY_WAIT_MS)
      }
    }

    loop()
    return () => { stopped = true }
  }, [driving, queryClient])

  async function guarded(action) {
    setWorking(true)
    setLocalError(null)
    try {
      return await action()
    } catch (err) {
      setLocalError(err?.message || __('Something went wrong while encrypting. Try again.', 'eleva-crm-for-photographers'))
      return null
    } finally {
      setWorking(false)
    }
  }

  async function handlePause() {
    setDriving(false)
    const res = await guarded(pauseEncrypt)
    if (res) queryClient.setQueryData(['vault-status'], (old) => mergeEncrypt(old, res))
  }

  async function handleCancel() {
    await guarded(cancelEncrypt)
    onDismiss()
  }

  const status = encrypt?.status
  const phase = encrypt?.phase || 'scan'
  const current = stepIndex(phase)
  const isError = status === 'error'
  const showSteps = status === 'running' || isError || driving
  const percent = Math.max(0, Math.min(100, encrypt?.percent ?? 0))

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-lg p-8">
        <div className="text-center mb-6">
          <span className="text-3xl">&#x1F510;</span>
          <h1 className="mt-2 text-xl font-bold text-gray-900">
            {__('Encrypting the data you already entered', 'eleva-crm-for-photographers')}
          </h1>
        </div>

        {!showSteps && (
          <div className="space-y-4">
            <p className="text-sm text-gray-700">
              {__('Your Vault is on, but some data you entered earlier is still stored as plain text. Eleva CRM will now encrypt it, so it is protected like the rest.', 'eleva-crm-for-photographers')}
            </p>
            <ul className="text-sm text-gray-700 space-y-1 list-disc list-inside">
              <li>{__('Close any other tab or window of Eleva CRM, and do not make changes while this runs.', 'eleva-crm-for-photographers')}</li>
              <li>{__('It can take a few minutes on a large archive. Keep this window open.', 'eleva-crm-for-photographers')}</li>
              <li>{__('If you must stop, use Pause: your data stays readable, and saving is disabled until you resume.', 'eleva-crm-for-photographers')}</li>
            </ul>
            <div className="flex justify-center pt-2">
              <Button size="lg" onClick={() => { setLocalError(null); setDriving(true) }}>
                {__('Start encryption', 'eleva-crm-for-photographers')}
              </Button>
            </div>
          </div>
        )}

        {showSteps && (
          <div className="space-y-5">
            <ol className="space-y-2">
              {PHASES.map((p, i) => {
                const done = i < current
                const active = i === current && !isError
                return (
                  <li key={p} className={['flex items-center gap-2 text-sm', done ? 'text-green-700' : active ? 'text-gray-900 font-medium' : 'text-gray-400'].join(' ')}>
                    {done ? <CheckCircle2 size={16} /> : active ? <Loader2 size={16} className="animate-spin" /> : <Circle size={16} />}
                    <span>{stepLabel(p)}</span>
                  </li>
                )
              })}
            </ol>

            <div>
              <div className="h-3 w-full overflow-hidden rounded-full bg-gray-100">
                <div className="h-full rounded-full bg-fotonic-primary transition-all" style={{ width: percent + '%' }} />
              </div>
              <div className="mt-2 flex items-center justify-between text-xs text-gray-500">
                <span>
                  {encrypt?.records > 0 && current >= 2
                    ? sprintf(__('%1$d of %2$d records need encryption.', 'eleva-crm-for-photographers'), encrypt.to_encrypt, encrypt.records)
                    : ''}
                </span>
                <span>{percent}% {etaText(encrypt?.eta_seconds)}</span>
              </div>
            </div>

            <p className="text-xs text-gray-500">
              {__('Keep this window open and do not make changes while it runs.', 'eleva-crm-for-photographers')}
            </p>
          </div>
        )}

        {(isError || localError) && (
          <p className="mt-4 rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
            {localError || errorText(encrypt?.error)}
          </p>
        )}

        {isError && !driving && (
          <div className="mt-4 flex justify-center gap-3">
            <Button disabled={working} onClick={() => { setLocalError(null); setDriving(true) }}>
              {__('Try again', 'eleva-crm-for-photographers')}
            </Button>
            <Button variant="secondary" disabled={working} onClick={handleCancel}>
              {__('Cancel and continue later', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        )}

        {!isError && localError && !driving && (
          <div className="mt-4 flex justify-center">
            <Button onClick={() => { setLocalError(null); setDriving(true) }}>
              {__('Try again', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        )}

        {showSteps && !isError && driving && (
          <div className="mt-6 flex justify-center">
            <Button variant="secondary" disabled={working} onClick={handlePause}>
              {__('Pause', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
