import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from './client'

export function useMemoryCards(params = {}) {
  const qs = params.status ? `?status=${encodeURIComponent(params.status)}` : ''
  return useQuery({
    queryKey: ['memory-cards', params],
    queryFn: () => apiFetch(`memory-cards${qs}`),
  })
}

export function useMemoryCard(id) {
  return useQuery({
    queryKey: ['memory-cards', id],
    queryFn: () => apiFetch(`memory-cards/${id}`),
    enabled: !!id,
  })
}

export function useCreateMemoryCard() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data) =>
      apiFetch('memory-cards', {
        method: 'POST',
        body: JSON.stringify(data),
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['memory-cards'] }),
  })
}

export function useUpdateMemoryCard() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...data }) =>
      apiFetch(`memory-cards/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['memory-cards'] }),
  })
}

export function useDeleteMemoryCard() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id) =>
      apiFetch(`memory-cards/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['memory-cards'] }),
  })
}
