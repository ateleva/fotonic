import { useQuery } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { apiFetch } from '../../api/client'
import Spinner from '../../components/Spinner'
import VaultSetup from './VaultSetup'
import VaultLock from './VaultLock'
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
    // Vault not configured yet (Phase C) — allow CRM access without encryption
    return <RouterProvider router={router} />
  }

  if (data?.setup === true && data?.unlocked === false) {
    return <VaultLock />
  }

  // unlocked === true
  return <RouterProvider router={router} />
}
