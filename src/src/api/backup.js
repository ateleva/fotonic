import { apiFetch } from './client'

const BASE  = window.FotonicApp?.restUrl ?? '/wp-json/fotonic/v1/'
const NONCE = window.FotonicApp?.nonce ?? ''

export const fetchBackupStatus = () => apiFetch('backup/status')

export const createBackup = () =>
  apiFetch('backup/create', { method: 'POST', body: JSON.stringify({}) })

/**
 * Streams the archive as a blob. Must go through fetch() + X-WP-Nonce header,
 * never a plain <a href>: an anchor navigation can't send the header, so
 * wp_verify_nonce() would always fail. Same pattern as fetchFileBlob in files.js.
 */
export async function fetchBackupBlob(token) {
  const res = await fetch(`${BASE}backup/download/${token}`, {
    headers: { 'X-WP-Nonce': NONCE },
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error(body.message ?? `HTTP ${res.status}`)
  }
  return res.blob()
}
