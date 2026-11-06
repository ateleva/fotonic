// Sends a backup file to the server in pieces (the server says how big a piece is, about 2 MB), with a progress
// callback, a few automatic retries per piece, and a way to carry on from where it stopped.
//
// This does not use apiFetch: apiFetch replaces its whole header set when a call passes its own headers, which would
// drop the nonce, and a piece is raw bytes, not JSON.

const BASE = window.FotonicApp?.restUrl ?? '/wp-json/fotonic/v1/'
const NONCE = window.FotonicApp?.nonce ?? ''
const RETRIES = 3
const DEFAULT_CHUNK = 2 * 1024 * 1024 // what the server uses; only a fallback for a resume without the value

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

async function call(path, options = {}) {
  const res = await fetch(BASE + path, {
    ...options,
    headers: { 'X-WP-Nonce': NONCE, ...(options.headers ?? {}) },
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    const error = new Error(body.message ?? `HTTP ${res.status}`)
    error.status = res.status
    error.code = body.code
    error.data = body.data
    throw error
  }
  return body
}

const postJson = (path, data) =>
  call(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })

// A server answer that will not change by asking again (the file is refused): do not retry those.
const isFinal = (error) => error.status >= 400 && error.status < 500 && error.status !== 408 && error.status !== 429

/**
 * Upload `file`. Pass `uploadId` (from an earlier, interrupted call) to carry on from the first missing piece.
 * `onProgress( { sent, total, percent } )` is called as pieces arrive. `signal` is an AbortSignal.
 * Resolves to { uploadId }. On a failure the error carries `uploadId` and `chunkSize`, so the caller can call again to resume.
 */
export async function chunkedUpload(file, { onProgress, onUploadId, uploadId = '', chunkSize: knownSize = 0, signal } = {}) {
  let id = uploadId
  // A resumed upload needs the size of a piece that `start` gave (it is on the error of the interrupted call).
  let chunkSize = knownSize
  let totalChunks
  let next = 0

  if (id) {
    const status = await call('restore/upload/status?upload_id=' + encodeURIComponent(id))
    totalChunks = status.total_chunks
    next = status.next_missing ?? totalChunks
    if (!chunkSize) chunkSize = DEFAULT_CHUNK
  } else {
    const started = await postJson('restore/upload/start', { size: file.size })
    id = started.upload_id
    chunkSize = started.chunk_size
    totalChunks = started.total_chunks
  }
  if (onUploadId) onUploadId(id) // so the caller can delete it if the person leaves half way

  const report = (sentChunks) => {
    if (!onProgress) return
    const sent = Math.min(file.size, sentChunks * chunkSize)
    onProgress({ sent, total: file.size, percent: file.size ? Math.round((sent / file.size) * 100) : 100 })
  }

  try {
    report(next)
    for (let index = next; index < totalChunks; index++) {
      if (signal?.aborted) throw new DOMException('Aborted', 'AbortError')
      const piece = file.slice(index * chunkSize, (index + 1) * chunkSize)
      let attempt = 0
      for (;;) {
        try {
          await call(`restore/upload/chunk?upload_id=${encodeURIComponent(id)}&index=${index}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/octet-stream' },
            body: piece,
            signal,
          })
          break
        } catch (error) {
          if (error.name === 'AbortError' || isFinal(error) || ++attempt >= RETRIES) throw error
          await sleep(800 * attempt)
        }
      }
      report(index + 1)
    }
    await postJson('restore/upload/finish', { upload_id: id })
  } catch (error) {
    error.uploadId = id
    error.chunkSize = chunkSize
    throw error
  }
  return { uploadId: id }
}
