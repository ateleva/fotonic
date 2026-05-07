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
 * Used for searchable fields (email, phone). Fixed IV derived from key material.
 * Returns "v2:<base64(fixedIV[12] + ciphertext)>".
 */
export async function deterministicEncrypt(cryptoKey, value) {
  const rawKey = await crypto.subtle.exportKey('raw', cryptoKey)
  const hashBuf = await crypto.subtle.digest('SHA-256', rawKey)
  const iv = new Uint8Array(hashBuf).slice(0, 12)
  const cipherBuf = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    cryptoKey,
    encoder.encode(String(value))
  )
  const combined = new Uint8Array(12 + cipherBuf.byteLength)
  combined.set(iv, 0)
  combined.set(new Uint8Array(cipherBuf), 12)
  return 'v2:' + bytesToB64(combined)
}
