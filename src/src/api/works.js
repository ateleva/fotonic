import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { apiFetch } from './client'

function buildQuery(params = {}) {
  const q = new URLSearchParams()
  if (params.search)         q.set('search',         params.search)
  if (params.page)           q.set('page',           params.page)
  if (params.per_page)       q.set('per_page',       params.per_page)
  if (params.payment_status) q.set('payment_status', params.payment_status)
  if (params.customer_id)    q.set('customer_id',    params.customer_id)
  if (params.storage_alert)  q.set('storage_alert',  '1')
  if (params.order)          q.set('order',          params.order)
  const qs = q.toString()
  return qs ? `works?${qs}` : 'works'
}

export function useWorks(params = {}) {
  return useQuery({
    queryKey: ['works', params],
    queryFn: () => apiFetch(buildQuery(params)),
    // Changing page or filter keeps the old rows on screen until the new ones arrive: no flash of a spinner.
    placeholderData: keepPreviousData,
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
      qc.invalidateQueries({ queryKey: ['memory-cards'] })
      qc.invalidateQueries({ queryKey: ['backup-locations'] })
      qc.invalidateQueries({ queryKey: ['shots-history'] })
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
      qc.invalidateQueries({ queryKey: ['memory-cards'] })
      qc.invalidateQueries({ queryKey: ['backup-locations'] })
      qc.invalidateQueries({ queryKey: ['shots-history'] })
    },
  })
}

export function useDeleteWork() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id) =>
      apiFetch(`works/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['works'] })
      qc.invalidateQueries({ queryKey: ['memory-cards'] })
      qc.invalidateQueries({ queryKey: ['backup-locations'] })
      qc.invalidateQueries({ queryKey: ['shots-history'] })
    },
  })
}
