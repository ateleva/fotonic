import { useQuery } from '@tanstack/react-query'
import { apiFetch } from './client'

export function useCollaboratorOptions() {
  return useQuery({
    queryKey: ['collaborator-options'],
    queryFn: () => apiFetch('collaborator-options'),
    staleTime: 60_000,
  })
}
