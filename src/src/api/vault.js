import { apiFetch } from './client'

// ---------------------------------------------------------------------------
// Vault core
// ---------------------------------------------------------------------------

export const fetchVaultStatus = () => apiFetch('vault/status')

export const vaultSetup = (data) =>
  apiFetch('vault/setup', { method: 'POST', body: JSON.stringify(data) })

export const vaultUnlock = (data) =>
  apiFetch('vault/unlock', { method: 'POST', body: JSON.stringify(data) })

export const vaultLock = () =>
  apiFetch('vault/lock', { method: 'POST' })

export const vaultChangePassword = (data) =>
  apiFetch('vault/change-password', { method: 'POST', body: JSON.stringify(data) })

// ---------------------------------------------------------------------------
// Recovery endpoints
// ---------------------------------------------------------------------------

/**
 * Lost password (have recovery code): resets password, unlocks vault.
 * POST vault/recovery/reset-password { recovery_code, new_password }
 * → { reset: true }
 */
export const vaultRecoveryResetPassword = (data) =>
  apiFetch('vault/recovery/reset-password', { method: 'POST', body: JSON.stringify(data) })

/**
 * Lost authenticator (know password): resets TOTP secret.
 * POST vault/recovery/reset-totp { password, recovery_code }
 * → { qr_uri, totp_secret }
 */
export const vaultRecoveryResetTotp = (data) =>
  apiFetch('vault/recovery/reset-totp', { method: 'POST', body: JSON.stringify(data) })

/**
 * Regenerate recovery code while vault is unlocked.
 * POST vault/recovery/regenerate {}
 * → { recovery_code }  (shown ONCE)
 */
export const vaultRecoveryRegenerate = () =>
  apiFetch('vault/recovery/regenerate', { method: 'POST', body: JSON.stringify({}) })

/**
 * Destructive: wipe vault config entirely.
 * POST vault/reset {}
 * → { reset: true }
 */
export const vaultReset = () =>
  apiFetch('vault/reset', { method: 'POST', body: JSON.stringify({}) })
