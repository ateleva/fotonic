import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from './client'

function buildQuery(params = {}) {
  const q = new URLSearchParams()
  if (params.search)         q.set('search',         params.search)
  if (params.page)           q.set('page',           params.page)
  if (params.per_page)       q.set('per_page',       params.per_page)
  if (params.payment_status) q.set('payment_status', params.payment_status)
  const qs = q.toString()
  return qs ? `works?${qs}` : 'works'
}

export function useWorks(params = {}) {
  return useQuery({
    queryKey: ['works', params],
    queryFn: () => apiFetch(buildQuery(params)),
  })
}

export function useWork(id) {
  return useQuery({
    queryKey: ['works', id],
    queryFn: () => apiFetch(`works/${id}`),
    enabled: !!id,
  })
}

export function useCreateWork() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data) =>
      apiFetch('works', {
        method: 'POST',
        body: JSON.stringify(data),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['works'] })
      qc.invalidateQueries({ queryKey: ['calendar'] })
    },
  })
}

export function useUpdateWork() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...data }) =>
      apiFetch(`works/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['works'] })
      qc.invalidateQueries({ queryKey: ['calendar'] })
    },
  })
}

export function useDeleteWork() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id) =>
      apiFetch(`works/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['works'] }),
  })
}
