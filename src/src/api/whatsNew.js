import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from './client'

const KEY = ['whats-new']

// What this admin has not seen yet: { entries: [{id, plugin, since, section, title, body, banner}], overview }.
export function useWhatsNew() {
  return useQuery({
    queryKey: KEY,
    queryFn: () => apiFetch('whats-new'),
    staleTime: 5 * 60 * 1000,
  })
}

// Closing is final: the ids are stored for this admin on the server, and dropped from the cache right away
// so the icon and the banner disappear without waiting for the answer.
export function useDismissWhatsNew() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (ids) => apiFetch('whats-new/dismiss', { method: 'POST', body: JSON.stringify({ ids }) }),
    onMutate: (ids) => {
      qc.setQueryData(KEY, (old) => {
        if (!old) return old
        const gone = new Set(ids)
        const entries = (old.entries ?? []).filter((e) => !gone.has(e.id))
        return {
          ...old,
          entries,
          overview: { ...old.overview, show: old.overview?.show && !gone.has(old.overview?.id) && entries.length > 0 },
        }
      })
    },
  })
}

export function useChangelog() {
  return useQuery({
    queryKey: ['changelog'],
    queryFn: () => apiFetch('changelog'),
    staleTime: 30 * 60 * 1000,
  })
}
