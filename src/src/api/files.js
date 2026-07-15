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
