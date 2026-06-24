const encoder = new TextEncoder()
const decoder = new TextDecoder()

function b64ToBytes(b64) {
  const bin = atob(b64)
  const arr = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i)
  return arr
}

function bytesToB64(arr) {
  return btoa(String.fromCharCode(...new Uint8Array(arr)))
}

/**
 * Derive AES-GCM-256 CryptoKey from vault password + base64-encoded salt.
 * Salt is stored in wp_options as base64_encode(random_bytes(32)).
 * Kept at 100k iterations for backward compat / migration of existing v2 data.
 */
export async function deriveKey(password, saltB64) {
  const keyMaterial = await crypto.subtle.importKey(
    'raw',
    encoder.encode(password),
    'PBKDF2',
    false,
    ['deriveKey']
  )
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt: b64ToBytes(saltB64), iterations: 100_000, hash: 'SHA-256' },
    keyMaterial,
    { name: 'AES-GCM', length: 256 },
    true,
    ['encrypt', 'decrypt']
  )
}

/**
 * Derive KEK from password + salt (600k PBKDF2 SHA-256).
 * Returns non-extractable AES-GCM key scoped to wrapKey / unwrapKey.
 * @param {string} password
 * @param {string} saltB64  — base64-encoded 16-byte salt
 * @returns {Promise<CryptoKey>}
 */
export async function deriveKEK(password, saltB64) {
  const keyMaterial = await crypto.subtle.importKey(
    'raw', encoder.encode(password), 'PBKDF2', false, ['deriveKey']
  )
  const salt = Uint8Array.from(atob(saltB64), c => c.charCodeAt(0))
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: 600_000, hash: 'SHA-256' },
    keyMaterial,
    { name: 'AES-GCM', length: 256 },
    false,        // non-extractable
    ['wrapKey', 'unwrapKey']
  )
}

/**
 * Generate random DEK (extractable — needed for wrapDEK at setup).
 * @returns {Promise<CryptoKey>}
 */
export async function generateDEK() {
  return crypto.subtle.generateKey(
    { name: 'AES-GCM', length: 256 },
    true,   // extractable (must export raw for wrapKey)
    ['encrypt', 'decrypt']
  )
}

/**
 * Wrap DEK under KEK. Returns 'v3:' + base64(iv12 || GCM-ciphertext+tag).
 * @param {CryptoKey} dek  — extractable
 * @param {CryptoKey} kek  — non-extractable, wrapKey usage
 * @returns {Promise<string>}
 */
export async function wrapDEK(dek, kek) {
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const wrapped = await crypto.subtle.wrapKey('raw', dek, kek, { name: 'AES-GCM', iv })
  const buf = new Uint8Array(iv.length + wrapped.byteLength)
  buf.set(iv, 0)
  buf.set(new Uint8Array(wrapped), iv.length)
  return 'v3:' + btoa(String.fromCharCode(...buf))
}

/**
 * Unwrap DEK from server blob. Returns NON-EXTRACTABLE CryptoKey.
 * Throws DOMException if KEK is wrong (GCM auth tag fails).
 * @param {string} blobB64  — 'v3:' + base64(iv12 || ct)
 * @param {CryptoKey} kek
 * @returns {Promise<CryptoKey>}
 */
export async function unwrapDEK(blobB64, kek) {
  if (!blobB64.startsWith('v3:')) throw new Error('Not a v3 wrapped DEK')
  const raw = Uint8Array.from(atob(blobB64.slice(3)), c => c.charCodeAt(0))
  const iv  = raw.slice(0, 12)
  const ct  = raw.slice(12)
  return crypto.subtle.unwrapKey(
    'raw', ct, kek,
    { name: 'AES-GCM', iv },
    { name: 'AES-GCM', length: 256 },
    false,     // NON-extractable
    ['encrypt', 'decrypt']
  )
}

/**
 * Generate random base64-encoded 16-byte salt.
 * @returns {string}
 */
export function generateSaltB64() {
  const salt = crypto.getRandomValues(new Uint8Array(16))
  return btoa(String.fromCharCode(...salt))
}

/**
 * Encrypt plaintext with AES-GCM. Returns "v2:<base64(iv[12] + ciphertext)>".
 * Random IV each call — not suitable for searchable fields.
 */
export async function encryptField(cryptoKey, plaintext) {
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const cipherBuf = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    cryptoKey,
    encoder.encode(String(plaintext))
  )
  const combined = new Uint8Array(12 + cipherBuf.byteLength)
  combined.set(iv, 0)
  combined.set(new Uint8Array(cipherBuf), 12)
  return 'v2:' + bytesToB64(combined)
}

/**
 * Decrypt a "v2:..." ciphertext. Returns null for v1: (PHP-side) or non-prefixed values.
 */
export async function decryptField(cryptoKey, ciphertext) {
  if (!ciphertext || typeof ciphertext !== 'string') return null
  if (!ciphertext.startsWith('v2:')) return null
  try {
    const combined = b64ToBytes(ciphertext.slice(3))
    const iv = combined.slice(0, 12)
    const data = combined.slice(12)
    const plainBuf = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, cryptoKey, data)
    return decoder.decode(plainBuf)
  } catch {
    return null
  }
}

/**
 * Deterministic AES-GCM encrypt — same input always produces same ciphertext.
 * Used for searchable fields (email, phone).
 * IV is SHA-256(valueBytes)[0:12] — key-independent so this works with
 * both extractable (v2/deriveKey) and non-extractable (v3/DEK) keys.
 * Returns "v2:<base64(fixedIV[12] + ciphertext)>".
 */
export async function deterministicEncrypt(cryptoKey, value) {
  const valueBytes = encoder.encode(String(value))
  const hashBuf = await crypto.subtle.digest('SHA-256', valueBytes)
  const iv = new Uint8Array(hashBuf).slice(0, 12)
  const cipherBuf = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    cryptoKey,
    valueBytes
  )
  const result = new Uint8Array(12 + cipherBuf.byteLength)
  result.set(iv, 0)
  result.set(new Uint8Array(cipherBuf), 12)
  return 'v2:' + bytesToB64(result)
}
