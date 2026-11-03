import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from './client'

export function usePaymentTypes() {
  return useQuery({
    queryKey: ['payment-types'],
    queryFn: () => apiFetch('payment-types'),
    staleTime: 5 * 60 * 1000,
  })
}

export function useCreatePaymentType() {
  const qc = useQueryClient()
  return useMutation({
    // A plain label, or { label, surcharge_pct } (Pro sets the percentage).
    mutationFn: (input) =>
      apiFetch('payment-types', {
        method: 'POST',
        body: JSON.stringify(typeof input === 'string' ? { label: input } : input),
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['payment-types'] }),
  })
}

export function useUpdatePaymentType() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...fields }) =>
      apiFetch(`payment-types/${id}`, {
        method: 'PUT',
        body: JSON.stringify(fields),
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['payment-types'] }),
  })
}

export function useDeletePaymentType() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id) => apiFetch(`payment-types/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['payment-types'] }),
  })
}
