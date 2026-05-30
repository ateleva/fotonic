/**
 * RecoveryCodeBanner
 *
 * Non-blocking banner shown when vault is unlocked, configured (scheme>=2),
 * and has_recovery === false.
 *
 * Calls vault/recovery/regenerate, then shows the code via RecoveryCodeDisplay.
 * Dismissible (hides for this session) or permanent once the code is saved.
 */
import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ShieldAlert, X } from 'lucide-react'
import Modal from '../../components/Modal'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import RecoveryCodeDisplay from './RecoveryCodeDisplay'
import { vaultRecoveryRegenerate } from '../../api/vault'
import { __ } from '../../utils/i18n'

export default function RecoveryCodeBanner() {
  const queryClient = useQueryClient()
  const [dismissed, setDismissed]   = useState(false)
  const [modalOpen, setModalOpen]   = useState(false)
  const [loading, setLoading]       = useState(false)
  const [error, setError]           = useState(null)
  const [recoveryCode, setRecoveryCode] = useState(null)

  if (dismissed) return null

  async function handleGenerate() {
    setError(null)
    setLoading(true)
    try {
      const data = await vaultRecoveryRegenerate()
      setRecoveryCode(data.recovery_code)
    } catch (err) {
      setError(err.message || __('Could not generate recovery code. Make sure the vault is unlocked.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  async function handleCodeSaved() {
    // Invalidate status so has_recovery reflects the new state
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    setModalOpen(false)
    setDismissed(true)
  }

  function handleOpenModal() {
    setRecoveryCode(null)
    setError(null)
    setModalOpen(true)
  }

  return (
    <>
      {/* Banner */}
      <div className="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 mb-4">
        <ShieldAlert size={18} className="text-amber-500 shrink-0 mt-0.5" />
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-amber-900">
            {__('Set up your recovery code', 'eleva-crm-for-photographers')}
          </p>
          <p className="text-xs text-amber-700 mt-0.5">
            {__('Your vault has no recovery code. Generate one now so you can regain access if you ever lose your password or authenticator.', 'eleva-crm-for-photographers')}
          </p>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <Button
            variant="secondary"
            size="sm"
            onClick={handleOpenModal}
          >
            {__('Set up', 'eleva-crm-for-photographers')}
          </Button>
          <button
            type="button"
            onClick={() => setDismissed(true)}
            className="text-amber-400 hover:text-amber-600 transition-colors"
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
        title={__('Recovery Code', 'eleva-crm-for-photographers')}
      >
        <div className="space-y-4">
          {!recoveryCode && (
            <>
              <p className="text-sm text-gray-600">
                {__('Generate a one-time recovery code. Store it somewhere safe — it will only be shown once.', 'eleva-crm-for-photographers')}
              </p>
              {error && (
                <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
              )}
              <Button
                variant="primary"
                className="w-full"
                onClick={handleGenerate}
                disabled={loading}
              >
                {loading ? <Spinner size="sm" /> : __('Generate Recovery Code', 'eleva-crm-for-photographers')}
              </Button>
            </>
          )}

          {recoveryCode && (
            <RecoveryCodeDisplay
              code={recoveryCode}
              onConfirm={handleCodeSaved}
              confirmLabel={__("I've saved it — Done", 'eleva-crm-for-photographers')}
            />
          )}
        </div>
      </Modal>
    </>
  )
}
