import { useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { useVault } from '../../context/VaultContext'
import { fetchVaultStatus, vaultTotpPending, vaultTotpConfirm } from '../../api/vault'
import Spinner from '../../components/Spinner'
import Button from '../../components/Button'
import VaultLock from './VaultLock'
import RecoveryKitWizard from './RecoveryKitWizard'
import TotpEnroll from './TotpEnroll'
import { __ } from '../../utils/i18n'

// ---------------------------------------------------------------------------
// PendingTotpScreen - shown after a recovery until the new authenticator is
// confirmed. The pending secret is (re)staged once on mount.
// ---------------------------------------------------------------------------

function PendingTotpScreen() {
  const queryClient = useQueryClient()
  const [qrUri, setQrUri] = useState(null)
  const [error, setError] = useState(null)
  const [attempt, setAttempt] = useState(0)
  const startedRef = useRef(-1)

  useEffect(() => {
    if (startedRef.current === attempt) return
    startedRef.current = attempt
    vaultTotpPending()
      .then((data) => setQrUri(data.qr_uri))
      .catch((err) => setError(err.message || __('Could not prepare the new authenticator. Please try again.', 'eleva-crm-for-photographers')))
  }, [attempt])

  async function handleConfirm(otp) {
    try {
      await vaultTotpConfirm({ otp })
    } catch (err) {
      return { error: err.message || __('Invalid code. Check your authenticator app.', 'eleva-crm-for-photographers') }
    }
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    return {}
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-md p-8">
        <div className="text-center mb-6">
          <h1 className="text-xl font-bold text-gray-900">{__('Set up your new authenticator', 'eleva-crm-for-photographers')}</h1>
          <p className="text-sm text-gray-500 mt-1">
            {__('Your password was reset. Add the vault to your authenticator app again to finish recovery.', 'eleva-crm-for-photographers')}
          </p>
        </div>

        {error && (
          <div className="space-y-4">
            <p className="text-sm text-red-600">{error}</p>
            <Button
              variant="secondary"
              onClick={() => { setError(null); setAttempt((a) => a + 1) }}
            >
              {__('Try again', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        )}

        {!error && !qrUri && (
          <div className="flex justify-center py-8"><Spinner size="lg" /></div>
        )}

        {!error && qrUri && (
          <TotpEnroll qrUri={qrUri} onConfirm={handleConfirm} />
        )}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// VaultGate
// ---------------------------------------------------------------------------

export default function VaultGate({ router }) {
  const { silentReopen, isUnlocked, markUnlocked } = useVault()
  const [isTryingReopen, setIsTryingReopen] = useState(false)
  const [reopenDone, setReopenDone]         = useState(false)

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
    silentReopen().then(() => {
      // On success silentReopen() flips context isUnlocked → true itself.
      if (cancelled) return
      setReopenDone(true)
      setIsTryingReopen(false)
    })

    return () => { cancelled = true }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data])

  // Sync VaultContext when the SERVER reports the vault open but context doesn't
  // know. Only fresh server data may open the gate — never stale local state,
  // otherwise an explicit lock would be immediately resurrected.
  useEffect(() => {
    if (data?.unlocked === true && !isUnlocked) markUnlocked()
  }, [data, isUnlocked, markUnlocked])

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <Spinner size="lg" />
      </div>
    )
  }

  // A failed background refetch keeps the previous data: only block when there is none,
  // otherwise an open wizard would be torn down and its in-state secrets lost.
  if (isError && !data) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-sm text-red-600">
          {__('Could not reach the vault. Please reload the page.', 'eleva-crm-for-photographers')}
        </p>
      </div>
    )
  }

  // Vault not set up — render app (VaultSetup is accessible via settings)
  if (data?.setup === false) {
    return <RouterProvider router={router} />
  }

  // Vault set up but locked. Wait for the initial silent-reopen attempt to
  // settle (avoids flashing the lock screen on load), then gate on the
  // reconciled context state — isUnlocked is the single source of truth.
  if (data?.setup === true && !isUnlocked) {
    if (isTryingReopen || !reopenDone) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50">
          <Spinner size="lg" />
        </div>
      )
    }
    return <VaultLock />
  }

  // Vault unlocked - context is the single source of truth (silentReopen /
  // markUnlocked / unlock all set isUnlocked from the server status).
  // Every branch returns a stable element type so a focus refetch never
  // resets wizard state.
  if (isUnlocked && data?.totp_pending) return <PendingTotpScreen />

  if (isUnlocked && data?.kit_ok === false) return <RecoveryKitWizard />

  return <RouterProvider router={router} />
}
