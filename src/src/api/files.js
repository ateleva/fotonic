const BASE  = window.FotonicApp?.restUrl ?? '/wp-json/fotonic/v1/'
const NONCE = window.FotonicApp?.nonce ?? ''

export async function fetchFileBlob(file) {
  const res = await fetch(`${BASE}vault-download/${file.id}`, {
    headers: { 'X-WP-Nonce': NONCE },
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error(body.message ?? `HTTP ${res.status}`)
  }
  return res.blob()
}

/** Upload one document of a work to the private folder (encrypted when the Vault is on). Returns the file entry. */
export async function uploadWorkFile(file) {
  const body = new FormData()
  body.append('file', file)
  const res = await fetch(`${BASE}work-files`, {
    method: 'POST',
    headers: { 'X-WP-Nonce': NONCE },
    body,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(data.message ?? `HTTP ${res.status}`)
    err.code = data.code
    err.status = res.status
    throw err
  }
  return data
}

/** Documents the last "encrypt existing data" job left in the media library (id, filename, why, uses). */
export async function fetchSkippedWorkFiles() {
  const res = await fetch(`${BASE}work-files/skipped`, { headers: { 'X-WP-Nonce': NONCE } })
  if (!res.ok) throw new Error(`HTTP ${res.status}`)
  const data = await res.json()
  return Array.isArray(data.files) ? data.files : []
}
