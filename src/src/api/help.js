import { useQuery } from '@tanstack/react-query'
import { apiFetch } from './client'

// { sections: { key: label }, cards: [{ id, section, order, first_steps, requires, available, unavailable, question, answer, keywords }] }
// for the user's language; loaded only when the Help page opens.
export function useHelp() {
  return useQuery({
    queryKey: ['help'],
    queryFn: () => apiFetch('help'),
    staleTime: 30 * 60 * 1000,
  })
}
