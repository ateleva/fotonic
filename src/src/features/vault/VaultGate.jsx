import { useQuery } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { apiFetch } from '../../api/client'
import { router } from '../../router'
import Spinner from '../../components/Spinner'
import VaultSetup from './VaultSetup'
import VaultLock from './VaultLock'

export default function VaultGate() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['vault-status'],
    queryFn: () => apiFetch('vault/status'),
    refetchOnWindowFocus: true,
  })

  if (isLoading) return <div className="min-h-screen flex items-center justify-center bg-gray-50"><Spinner size="lg" /></div>
  if (isError) return <div className="min-h-screen flex items-center justify-center bg-gray-50"><p className="text-sm text-red-600">Could not reach the vault. Please reload the page.</p></div>
  if (data?.setup === false) return <RouterProvider router={router} />
  if (data?.setup === true && data?.unlocked === false) return <VaultLock />
  return <RouterProvider router={router} />
}
