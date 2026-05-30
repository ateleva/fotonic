import { useQuery } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { apiFetch } from '../../api/client'
import Spinner from '../../components/Spinner'
import VaultSetup from './VaultSetup'
import VaultLock from './VaultLock'
import RecoveryCodeBanner from './RecoveryCodeBanner'
import { __ } from '../../utils/i18n'

export default function VaultGate({ router }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['vault-status'],
    queryFn: () => apiFetch('vault/status'),
    // Refetch on window focus so that after unlock the CRM appears without a manual reload
    refetchOnWindowFocus: true,
  })

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

  if (data?.setup === false) {
    // Vault not configured yet — allow CRM access without encryption
    return <RouterProvider router={router} />
  }

  if (data?.setup === true && data?.unlocked === false) {
    return <VaultLock />
  }

  // unlocked === true
  // Show recovery-code banner if vault is set up, unlocked, scheme>=2, and has no recovery code yet
  const needsRecoveryCode =
    data?.setup === true &&
    data?.unlocked === true &&
    (data?.scheme ?? 0) >= 2 &&
    data?.has_recovery === false

  return (
    <>
      {needsRecoveryCode && (
        <div className="px-6 pt-4">
          <RecoveryCodeBanner />
        </div>
      )}
      <RouterProvider router={router} />
    </>
  )
}
