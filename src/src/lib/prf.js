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

/** Fingerprint unlock needs a secure page (HTTPS), WebAuthn and the PRF extension. `reason` says which one is missing. */
export async function prfSupport() {
  try {
    if (!window.isSecureContext) return { supported: false, reason: 'insecure' }
    if (!window.PublicKeyCredential || !window.crypto?.subtle) return { supported: false, reason: 'no_webauthn' }
    if (typeof PublicKeyCredential.getClientCapabilities !== 'function') return { supported: false, reason: 'no_prf' }
    const caps = await PublicKeyCredential.getClientCapabilities()
    return caps?.['extension:prf'] === true ? { supported: true, reason: '' } : { supported: false, reason: 'no_prf' }
  } catch {
    return { supported: false, reason: 'no_prf' }
  }
}

export async function prfSupported() {
  return (await prfSupport()).supported
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
 * @throws Error with .code: 'cancelled' | 'prf_unsupported' | 'prf_inconsistent' | 'insecure' | 'already' | 'failed'
 */
export async function enrollCredential({ salt, label, existingIds = [] }) {
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
        // Passkeys already enrolled for this address: when one of them is available on this computer (for example the
        // same iCloud passkey in Safari and in Chrome) the browser refuses to make a second one.
        excludeCredentials: existingIds.map((id) => ({ type: 'public-key', id: b64uDecode(id) })),
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
    if (err?.code && ['cancelled', 'prf_unsupported', 'prf_inconsistent', 'insecure', 'already', 'failed'].includes(err.code)) throw err
    // The browser's own error name, for whoever has to find out why a device refused (never a secret).
    console.warn('[Eleva CRM] fingerprint setup failed:', err?.name, err?.message)
    if (err?.name === 'InvalidStateError') throw failure('already')
    if (err?.name === 'NotAllowedError' || err?.name === 'AbortError') throw failure('cancelled')
    if (err?.name === 'NotSupportedError') throw failure('prf_unsupported')
    if (err?.name === 'SecurityError') throw failure('insecure')
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

/**
 * Ask the device for the secrets of the enrolled credentials of this host (one prompt) and derive the
 * two keys the server needs: the one that opens the wrap now and the one it is wrapped under next.
 *
 * @param {Array<{cred_id:string,salt_cur:string,salt_next:string,gen:number}>} devices From the challenge.
 * @returns {{device: object, kekCur: string, kekNext: string}} base64url keys
 * @throws Error with .code: 'cancelled' | 'prf_unsupported' | 'insecure' | 'failed'
 */
export async function unlockCredential(devices) {
  const evalByCredential = {}
  for (const d of devices) {
    evalByCredential[d.cred_id] = { first: b64uDecode(d.salt_cur), second: b64uDecode(d.salt_next) }
  }
  try {
    const assertion = await navigator.credentials.get({
      publicKey: {
        rpId: window.location.hostname,
        challenge: crypto.getRandomValues(new Uint8Array(32)),
        allowCredentials: devices.map((d) => ({ type: 'public-key', id: b64uDecode(d.cred_id) })),
        userVerification: 'required',
        timeout: 120000,
        extensions: { prf: { evalByCredential } },
      },
    })
    const rawId = new Uint8Array(assertion.rawId)
    const device = devices.find((d) => d.cred_id === b64uEncode(rawId))
    const results = assertion.getClientExtensionResults?.()?.prf?.results
    // Both secrets or nothing: a missing "second" must never turn into an unlock that does not rotate.
    if (!device || !results?.first || !results?.second) throw failure('prf_unsupported')
    const [kekCur, kekNext] = await Promise.all([deriveKek(results.first, rawId), deriveKek(results.second, rawId)])
    return { device, kekCur: b64uEncode(kekCur), kekNext: b64uEncode(kekNext) }
  } catch (err) {
    if (err?.code) throw err
    console.warn('[Eleva CRM] fingerprint unlock failed:', err?.name, err?.message)
    if (err?.name === 'NotAllowedError' || err?.name === 'AbortError') throw failure('cancelled')
    if (err?.name === 'SecurityError') throw failure('insecure')
    throw failure('failed')
  }
}
