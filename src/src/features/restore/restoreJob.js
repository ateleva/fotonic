import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchRestoreStatus, pauseRestore, resumeRestore, undoRestore, dismissRestore,
} from '../../api/restore'

// The restore job lives in the query cache under one key. RestoreHost (mounted once in the Layout) is the only code
// that drives it; every screen only reads it and asks for pause, resume, undo or dismiss.
export const JOB_KEY = ['restore-job']
export const JOB_ERROR_KEY = ['restore-job-error']
export const JOB_FINISHED_KEY = ['restore-job-finished']

export function useRestoreJob() {
  return useQuery({
    queryKey: JOB_KEY,
    queryFn: async () => (await fetchRestoreStatus()).job ?? null,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
  })
}

// A small cache cell for text or flags the host shares with the screens.
export function useRestoreCell(key, initial) {
  const { data } = useQuery({ queryKey: key, queryFn: () => initial, staleTime: Infinity, initialData: initial })
  return data
}

export function useRestoreActions() {
  const queryClient = useQueryClient()
  const put = (job) => {
    queryClient.setQueryData(JOB_ERROR_KEY, '')
    queryClient.setQueryData(JOB_KEY, job)
    return job
  }
  const refresh = () => queryClient.invalidateQueries({ queryKey: JOB_KEY })
  return {
    pause: async (id) => put(await pauseRestore(id)),
    resume: async (id) => put(await resumeRestore(id)),
    // The first call undoes one batch and puts the job in `undoing`; the host carries on from there.
    undo: async (id) => put(await undoRestore(id)),
    dismiss: async (id) => {
      const job = await dismissRestore(id)
      queryClient.setQueryData(JOB_FINISHED_KEY, false)
      return put(job)
    },
    refresh,
  }
}
