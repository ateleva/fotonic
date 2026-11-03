// Fingerprint unlock helpers: WebAuthn with the PRF extension, no library.
//
// The device answers a salt with a secret only the enrolled credential can produce (PRF). It is
// stretched into a 32-byte key (KEK) with HKDF and sent to the server over HTTPS, which keeps the
// Vault key wrapped under it. Server side: includes/class-vault-fingerprint.php.

const KEK_INFO = 'ftnc-prf-kek-v1'

export function b64uEncode(buf) {
  const bytes = buf instanceof Uint8Array ? buf : new Uint8Array(buf)
  let bin = ''
  for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i])
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

export function b64uDecode(str) {
  const pad = '='.repeat((4 - (str.length % 4)) % 4)
  const bin = atob(str.replace(/-/g, '+').replace(/_/g, '/') + pad)
  const bytes = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i)
  return bytes
}

/** Fingerprint unlock needs HTTPS, WebAuthn and the PRF extension. Anything else hides the feature. */
export async function prfSupported() {
  try {
    if (!window.isSecureContext || !window.PublicKeyCredential || !window.crypto?.subtle) return false
    if (typeof PublicKeyCredential.getClientCapabilities !== 'function') return false
    const caps = await PublicKeyCredential.getClientCapabilities()
    return caps?.['extension:prf'] === true
  } catch {
    return false
  }
}

/** HKDF-SHA256 of the PRF output; the salt is the credential id. Returns 32 bytes. */
export async function deriveKek(prfOutput, credIdBytes) {
  const base = await crypto.subtle.importKey('raw', prfOutput, 'HKDF', false, ['deriveBits'])
  const bits = await crypto.subtle.deriveBits(
    { name: 'HKDF', hash: 'SHA-256', salt: credIdBytes, info: new TextEncoder().encode(KEK_INFO) },
    base,
    256
  )
  return new Uint8Array(bits)
}

function sameBytes(a, b) {
  if (a.length !== b.length) return false
  let diff = 0
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i]
  return diff === 0
}

/**
 * Create the credential on this device and read its secret for `salt` (base64url, from the server).
 * Asks twice (create, then get) and keeps the key only when both answers agree, so a device that
 * does not give a stable secret never gets a wrap it could not open again.
 *
 * @returns {{credId: string, kek: string, host: string}} base64url credential id, base64url KEK
 * @throws Error with .code: 'cancelled' | 'prf_unsupported' | 'prf_inconsistent' | 'failed'
 */
export async function enrollCredential({ salt, label }) {
  const host = window.location.hostname
  const saltBytes = b64uDecode(salt)
  const name = label || 'Eleva CRM'
  try {
    const cred = await navigator.credentials.create({
      publicKey: {
        rp: { id: host, name: 'Eleva CRM' },
        user: { id: crypto.getRandomValues(new Uint8Array(16)), name, displayName: name },
        challenge: crypto.getRandomValues(new Uint8Array(32)),
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
        authenticatorSelection: { authenticatorAttachment: 'platform', residentKey: 'preferred', userVerification: 'required' },
        timeout: 120000,
        extensions: { prf: { eval: { first: saltBytes } } },
      },
    })
    if (!cred) throw failure('failed')
    const rawId = new Uint8Array(cred.rawId)

    const created = cred.getClientExtensionResults?.()?.prf?.results?.first
    const kekFromCreate = created ? await deriveKek(created, rawId) : null

    const assertion = await navigator.credentials.get({
      publicKey: {
        rpId: host,
        challenge: crypto.getRandomValues(new Uint8Array(32)),
        allowCredentials: [{ type: 'public-key', id: rawId }],
        userVerification: 'required',
        timeout: 120000,
        extensions: { prf: { eval: { first: saltBytes } } },
      },
    })
    const got = assertion?.getClientExtensionResults?.()?.prf?.results?.first
    if (!got) throw failure('prf_unsupported')
    const kek = await deriveKek(got, rawId)
    if (kekFromCreate && !sameBytes(kek, kekFromCreate)) throw failure('prf_inconsistent')

    return { credId: b64uEncode(rawId), kek: b64uEncode(kek), host }
  } catch (err) {
    if (err?.code && ['cancelled', 'prf_unsupported', 'prf_inconsistent', 'failed'].includes(err.code)) throw err
    if (err?.name === 'NotAllowedError' || err?.name === 'AbortError') throw failure('cancelled')
    throw failure('failed')
  }
}

function failure(code) {
  const e = new Error(code)
  e.code = code
  return e
}

/** A readable default name for the device, like "Safari on macOS". */
export function guessDeviceName() {
  const ua = navigator.userAgent || ''
  let browser = 'Browser'
  if (/Edg\//.test(ua)) browser = 'Edge'
  else if (/Firefox\//.test(ua)) browser = 'Firefox'
  else if (/Chrome\//.test(ua)) browser = 'Chrome'
  else if (/Safari\//.test(ua)) browser = 'Safari'
  let os = ''
  if (/Mac OS X/.test(ua)) os = 'macOS'
  else if (/Windows/.test(ua)) os = 'Windows'
  else if (/Android/.test(ua)) os = 'Android'
  else if (/iPhone|iPad/.test(ua)) os = 'iOS'
  else if (/Linux/.test(ua)) os = 'Linux'
  return os ? `${browser} on ${os}` : browser
}
