import { encryptField, deterministicEncrypt, decryptField } from '../lib/webcrypto'

const BASE = window.FotonicApp?.restUrl ?? '/wp-json/fotonic/v1/'
const NONCE = window.FotonicApp?.nonce ?? ''

export async function apiFetch(path, options = {}) {
  const res = await fetch(BASE + path, {
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': NONCE,
      ...options.headers,
    },
    ...options,
  })
  if (!res.ok) {
    const err = await res.json().catch(() => ({}))
    throw new Error(err.message ?? `HTTP ${res.status}`)
  }
  return res.json()
}

// ---------------------------------------------------------------------------
// Vault key — set by VaultContext after unlock, cleared on lock.
// Never stored in localStorage / sessionStorage / DB.
// ---------------------------------------------------------------------------

let _vaultKey = null

export function setVaultKey(key) { _vaultKey = key }
export function getVaultKey() { return _vaultKey }

// ---------------------------------------------------------------------------
// PII field definitions (matches PHP Fotonic_Crypto usage)
// ---------------------------------------------------------------------------

const PERSON_RANDOM_FIELDS       = ['first_name', 'last_name', 'nationality', 'instagram_username', 'address', 'tin']
const PERSON_DETERMINISTIC_FIELDS = ['email', 'phone']
const ALL_PERSON_FIELDS           = [...PERSON_RANDOM_FIELDS, ...PERSON_DETERMINISTIC_FIELDS]

// ---------------------------------------------------------------------------
// Customer PII helpers
// ---------------------------------------------------------------------------

/**
 * Encrypt all PII fields in a people array before POSTing to the REST API.
 * No-op if vault key not set or input is not an array.
 */
export async function encryptPeople(people) {
  if (!_vaultKey || !Array.isArray(people)) return people
  return Promise.all(
    people.map(async (person) => {
      const enc = { ...person }
      for (const field of PERSON_RANDOM_FIELDS) {
        if (enc[field]) enc[field] = await encryptField(_vaultKey, String(enc[field]))
      }
      for (const field of PERSON_DETERMINISTIC_FIELDS) {
        if (enc[field]) enc[field] = await deterministicEncrypt(_vaultKey, String(enc[field]))
      }
      return enc
    })
  )
}

/**
 * Decrypt v2:-prefixed PII fields in a people array received from the REST API.
 * v1: fields are left as-is (decrypted server-side by PHP for legacy data).
 */
export async function decryptPeople(people) {
  if (!_vaultKey || !Array.isArray(people)) return people
  return Promise.all(
    people.map(async (person) => {
      const dec = { ...person }
      for (const field of ALL_PERSON_FIELDS) {
        if (dec[field] && typeof dec[field] === 'string' && dec[field].startsWith('v2:')) {
          dec[field] = await decryptField(_vaultKey, dec[field])
        }
      }
      return dec
    })
  )
}

// ---------------------------------------------------------------------------
// Work PII helpers
// ---------------------------------------------------------------------------

/**
 * Encrypt work PII fields (event_addresses[].street, notes) before POST/PUT.
 */
export async function encryptWorkPii(work) {
  if (!_vaultKey) return work
  const enc = { ...work }
  if (enc.notes && typeof enc.notes === 'string' && !enc.notes.startsWith('v2:')) {
    enc.notes = await encryptField(_vaultKey, enc.notes)
  }
  if (Array.isArray(enc.event_addresses)) {
    enc.event_addresses = await Promise.all(
      enc.event_addresses.map(async (addr) => {
        const a = { ...addr }
        if (a.street && typeof a.street === 'string' && !a.street.startsWith('v2:')) {
          a.street = await encryptField(_vaultKey, a.street)
        }
        return a
      })
    )
  }
  return enc
}

/**
 * Decrypt v2:-prefixed work PII fields received from the REST API.
 */
export async function decryptWorkPii(work) {
  if (!_vaultKey) return work
  const dec = { ...work }
  if (dec.notes && typeof dec.notes === 'string' && dec.notes.startsWith('v2:')) {
    dec.notes = await decryptField(_vaultKey, dec.notes)
  }
  if (Array.isArray(dec.event_addresses)) {
    dec.event_addresses = await Promise.all(
      dec.event_addresses.map(async (addr) => {
        const a = { ...addr }
        if (a.street && typeof a.street === 'string' && a.street.startsWith('v2:')) {
          a.street = await decryptField(_vaultKey, a.street)
        }
        return a
      })
    )
  }
  return dec
}
