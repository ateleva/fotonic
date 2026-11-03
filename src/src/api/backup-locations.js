import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from './client'

export function useBackupLocations() {
  return useQuery({
    queryKey: ['backup-locations'],
    queryFn: () => apiFetch('backup-locations'),
  })
}

export function useBackupLocation(id) {
  return useQuery({
    queryKey: ['backup-locations', id],
    queryFn: () => apiFetch(`backup-locations/${id}`),
    enabled: !!id,
  })
}

export function useCreateBackupLocation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data) =>
      apiFetch('backup-locations', {
        method: 'POST',
        body: JSON.stringify(data),
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['backup-locations'] }),
  })
}

export function useUpdateBackupLocation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...data }) =>
      apiFetch(`backup-locations/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['backup-locations'] }),
  })
}

// A deleted location leaves a flag on every work that used it: refresh the works too.
export function useDeleteBackupLocation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id) =>
      apiFetch(`backup-locations/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['backup-locations'] })
      qc.invalidateQueries({ queryKey: ['works'] })
    },
  })
}

export function useShotsHistory(year = '') {
  return useQuery({
    queryKey: ['shots-history', year],
    queryFn: () => apiFetch(`storage/shots${year ? `?year=${encodeURIComponent(year)}` : ''}`),
  })
}
