import { useQuery } from '@tanstack/react-query'
import { apiFetch } from './client'

export function useCalendarWorks(from, to) {
  return useQuery({
    queryKey: ['calendar', from, to],
    queryFn: () => apiFetch(`calendar?from=${from}&to=${to}`),
    staleTime: 60_000,
    enabled: !!from && !!to,
  })
}
