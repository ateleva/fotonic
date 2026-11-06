import { apiFetch } from './client'

const post = (path, data) =>
  apiFetch(path, { method: 'POST', body: JSON.stringify(data ?? {}) })

// Backups already on this site (the alternative to uploading one).
export const fetchRestoreBackups = () => apiFetch('restore/backups')

// `source` is { kind: 'upload', uploadId } or { kind: 'server', file }. The secret is optional: the Vault of this site
// opens a backup of its own with no password.
export const openRestore = (source, secret) =>
  post('restore/open', {
    ...(source.kind === 'upload' ? { upload_id: source.uploadId } : { backup_file: source.file }),
    ...(secret ? { secret } : {}),
  })

// One batch of the preview per call, until `done`.
export const previewRestore = (sessionId, restart) =>
  post('restore/preview', { session_id: sessionId, restart: Boolean(restart) })

export const startRestore = (sessionId, decisions, options = {}) =>
  post('restore/start', { session_id: sessionId, decisions, ...options })

export const runRestore = (jobId) => post('restore/run', { job_id: jobId })
export const pauseRestore = (jobId) => post('restore/pause', { job_id: jobId })
export const resumeRestore = (jobId) => post('restore/resume', { job_id: jobId })
export const undoRestore = (jobId) => post('restore/undo', { job_id: jobId })
export const dismissRestore = (jobId) => post('restore/dismiss', { job_id: jobId })

// { job: status | null }: lets the screen find its way back after a reload.
export const fetchRestoreStatus = () => apiFetch('restore/status')

// Ends a restore session (or abandons an upload): both ids are 32 hex characters.
export const closeRestore = (id) => apiFetch('restore/' + id, { method: 'DELETE' })

// Backups kept somewhere else (Eleva CRM Pro adds Google Drive): [{ source, label, items: [{ ref, name, size, created }] }].
export const fetchRestoreSources = () => apiFetch('restore/sources')

/**
 * Brings a remote backup into an upload, a few pieces per call, until the server says `done`. Pass the `uploadId` of an
 * interrupted attempt to carry on. On a failure the error carries `uploadId` (when the download had begun).
 * Resolves to { uploadId }.
 */
export async function fetchRemoteBackup(source, ref, { uploadId = '', onProgress, onUploadId, signal } = {}) {
  let id = uploadId
  for (;;) {
    if (signal?.aborted) throw new DOMException('Aborted', 'AbortError')
    let res
    try {
      res = await post('restore/fetch', { source, ref, upload_id: id })
    } catch (err) {
      err.uploadId = err.data?.upload_id || id
      throw err
    }
    id = res.upload_id
    if (onUploadId) onUploadId(id)
    if (onProgress) onProgress(Math.round((100 * res.received) / Math.max(1, res.total_chunks)))
    if (res.done) return { uploadId: id }
  }
}
