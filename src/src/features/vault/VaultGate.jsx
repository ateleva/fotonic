import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { useVault } from '../../context/VaultContext'
import { fetchVaultStatus, vaultEnrollPhrase } from '../../api/vault'
import Spinner from '../../components/Spinner'
import Button from '../../components/Button'
import Modal from '../../components/Modal'
import VaultLock from './VaultLock'
import RecoveryCodeBanner from './RecoveryCodeBanner'
import { __ } from '../../utils/i18n'
import { ShieldCheck, X } from 'lucide-react'

// ---------------------------------------------------------------------------
// RecoveryPhraseBanner — shown when has_recovery_phrase === false
// ---------------------------------------------------------------------------

function RecoveryPhraseBanner() {
  const [dismissed, setDismissed]   = useState(false)
  const [modalOpen, setModalOpen]   = useState(false)
  const [loading, setLoading]       = useState(false)
  const [error, setError]           = useState(null)
  const [phrase, setPhrase]         = useState(null)
  const [phraseSaved, setPhraseSaved] = useState(false)
  const [copied, setCopied]         = useState(false)

  if (dismissed) return null

  async function handleEnroll() {
    setError(null)
    setLoading(true)
    try {
      const data = await vaultEnrollPhrase()
      setPhrase(data.recovery_phrase)
    } catch (err) {
      setError(err.message || __('Could not generate recovery phrase. Make sure the vault is unlocked.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  function handleCopy() {
    navigator.clipboard.writeText(phrase).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  function handleOpen() {
    setPhrase(null)
    setError(null)
    setPhraseSaved(false)
    setModalOpen(true)
  }

  function handleDone() {
    setModalOpen(false)
    setDismissed(true)
  }

  return (
    <>
      {/* Banner */}
      <div className="flex items-start gap-3 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 mb-4">
        <ShieldCheck size={18} className="text-blue-500 shrink-0 mt-0.5" />
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-blue-900">
            {__('Set up your recovery phrase', 'eleva-crm-for-photographers')}
          </p>
          <p className="text-xs text-blue-700 mt-0.5">
            {__('Add a recovery phrase so you can regain access if you ever forget your vault password. It takes only a few seconds.', 'eleva-crm-for-photographers')}
          </p>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <Button variant="secondary" size="sm" onClick={handleOpen}>
            {__('Set up', 'eleva-crm-for-photographers')}
          </Button>
          <button
            type="button"
            onClick={() => setDismissed(true)}
            className="text-blue-400 hover:text-blue-600 transition-colors"
            aria-label={__('Dismiss', 'eleva-crm-for-photographers')}
          >
            <X size={16} />
          </button>
        </div>
      </div>

      {/* Modal */}
      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={__('Recovery Phrase', 'eleva-crm-for-photographers')}
      >
        <div className="space-y-4">
          {!phrase && (
            <>
              <p className="text-sm text-gray-600">
                {__('A recovery phrase lets you reset your vault password if you ever forget it. Store it somewhere safe offline — it will only be shown once.', 'eleva-crm-for-photographers')}
              </p>
              {error && (
                <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
              )}
              <Button
                variant="primary"
                className="w-full"
                onClick={handleEnroll}
                disabled={loading}
              >
                {loading ? <Spinner size="sm" /> : __('Generate Recovery Phrase', 'eleva-crm-for-photographers')}
              </Button>
            </>
          )}

          {phrase && (
            <div className="space-y-4">
              <div className="rounded-md bg-amber-50 border border-amber-200 p-4">
                <p className="text-xs text-gray-500 mb-2">{__('Your Recovery Phrase', 'eleva-crm-for-photographers')}</p>
                <p className="text-sm font-mono font-semibold text-amber-900 break-all tracking-widest text-center">
                  {phrase}
                </p>
              </div>
              <Button variant="secondary" className="w-full" onClick={handleCopy}>
                {copied ? __('Copied!') : __('Copy to clipboard')}
              </Button>
              <label className="flex items-start gap-3 cursor-pointer">
                <input
                  type="checkbox"
                  checked={phraseSaved}
                  onChange={(e) => setPhraseSaved(e.target.checked)}
                  className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"
                />
                <span className="text-sm text-gray-700">
                  {__('I have saved this phrase offline in a secure location.', 'eleva-crm-for-photographers')}
                </span>
              </label>
              <Button variant="primary" className="w-full" disabled={!phraseSaved} onClick={handleDone}>
                {__('Done', 'eleva-crm-for-photographers')}
              </Button>
            </div>
          )}
        </div>
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------------------
// VaultGate
// ---------------------------------------------------------------------------

export default function VaultGate({ router }) {
  const { silentReopen, isUnlocked, markUnlocked } = useVault()
  const [isTryingReopen, setIsTryingReopen] = useState(false)
  const [reopenDone, setReopenDone]         = useState(false)
  const [reopenResult, setReopenResult]     = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['vault-status'],
    queryFn: fetchVaultStatus,
    refetchOnWindowFocus: true,
  })

  // Once we have status and vault is set-up-but-locked, attempt silent reopen
  useEffect(() => {
    if (!data) return
    if (data.setup !== true || data.unlocked !== false) return
    if (reopenDone) return

    let cancelled = false
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setIsTryingReopen(true)
    silentReopen().then((opened) => {
      if (cancelled) return
      setReopenResult(opened)
      setReopenDone(true)
      setIsTryingReopen(false)
    })

    return () => { cancelled = true }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data])

  // Sync VaultContext when server reports vault open but context doesn't know.
  useEffect(() => {
    const vaultOpen = data?.unlocked === true || reopenResult === true || isUnlocked
    if (vaultOpen && !isUnlocked) markUnlocked()
  }, [data, reopenResult, isUnlocked, markUnlocked])

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <Spinner size="lg" />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-sm text-red-600">
          {__('Could not reach the vault. Please reload the page.')}
        </p>
      </div>
    )
  }

  // Vault not set up — render app (VaultSetup is accessible via settings)
  if (data?.setup === false) {
    return <RouterProvider router={router} />
  }

  // Vault set up but locked — try silent reopen first
  if (data?.setup === true && data?.unlocked === false && !isUnlocked) {
    if (isTryingReopen || !reopenDone) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50">
          <Spinner size="lg" />
        </div>
      )
    }
    if (!reopenResult) {
      return <VaultLock />
    }
  }

  // Vault unlocked
  const isVaultOpen = data?.unlocked === true || reopenResult === true || isUnlocked
  const needsRecoveryPhrase = isVaultOpen && data?.has_recovery_phrase === false
  const needsRecoveryCode   = isVaultOpen && data?.has_recovery_code === false

  return (
    <>
      {(needsRecoveryPhrase || needsRecoveryCode) && (
        <div className="px-6 pt-4">
          {needsRecoveryPhrase && <RecoveryPhraseBanner />}
          {needsRecoveryCode   && <RecoveryCodeBanner />}
        </div>
      )}
      <RouterProvider router={router} />
    </>
  )
}
