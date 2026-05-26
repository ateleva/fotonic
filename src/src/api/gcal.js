import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from './client'

export function useSyncAll() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => apiFetch('gcal/sync-all', { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['calendar'] }),
  })
}

export function useUnlinkWorkGCalEvent(id) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => apiFetch(`works/${id}/gcal-unlink`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['calendar'] }),
  })
}

export function useUnlinkTaskGCalEvent(id) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => apiFetch(`tasks/${id}/gcal-unlink`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['calendar'] }),
  })
}
