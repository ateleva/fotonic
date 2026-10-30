import { apiFetch } from './client'

const post = (path, data) =>
  apiFetch(path, { method: 'POST', body: JSON.stringify(data ?? {}) })

// ---------------------------------------------------------------------------
// Vault core
// ---------------------------------------------------------------------------

export const fetchVaultStatus = () => apiFetch('vault/status')

export const vaultUnlock = (data) => post('vault/unlock', data)

// Lock button: locks every browser and device. scope 'device' (the idle timer) only locks this browser.
export const vaultLock = (scope) =>
  post('vault/lock', scope === 'device' ? { scope: 'device' } : {})

export const vaultChangePassword = (data) => post('vault/change-password', data)

export const vaultResetTotp = (data) => post('vault/reset-totp', data)

// ---------------------------------------------------------------------------
// Two-phase setup
// ---------------------------------------------------------------------------

/**
 * Stage a new vault (nothing is live yet).
 * POST vault/setup { password } -> { staged: true, qr_uri }
 */
export const vaultSetup = ({ password }) => post('vault/setup', { password })

/**
 * Promote the staged vault after a valid OTP.
 * POST vault/setup/confirm { password, otp } -> { setup: true }
 */
export const vaultSetupConfirm = ({ password, otp }) =>
  post('vault/setup/confirm', { password, otp })

// ---------------------------------------------------------------------------
// Recovery kit
// ---------------------------------------------------------------------------

/**
 * Generate a new recovery kit (replaces the previous secrets) and email it.
 * POST vault/recovery-kit -> { recovery_code, recovery_phrase, email_sent, email_hint }
 */
export const vaultIssueKit = () => post('vault/recovery-kit')

/**
 * Set the kit acknowledgment flag. ack=false forces the kit wizard again.
 * POST vault/recovery-kit/ack { ack } -> { ack }
 */
export const vaultAckKit = (ack) => post('vault/recovery-kit/ack', { ack })

// ---------------------------------------------------------------------------
// Recovery from a secret (phrase or code)
// ---------------------------------------------------------------------------

/**
 * POST vault/recovery/begin { recovery_secret, new_password } -> { recovered: true }
 */
export const vaultRecoveryBegin = ({ recovery_secret, new_password }) =>
  post('vault/recovery/begin', { recovery_secret, new_password })

/**
 * Re-stage a fresh authenticator secret while one is pending.
 * POST vault/totp/pending -> { qr_uri }
 */
export const vaultTotpPending = () => post('vault/totp/pending')

/**
 * POST vault/totp/confirm { otp } -> { confirmed: true }
 */
export const vaultTotpConfirm = ({ otp }) => post('vault/totp/confirm', { otp })

// ---------------------------------------------------------------------------
// Destructive reset
// ---------------------------------------------------------------------------

/**
 * Email an 8-digit reset code.
 * POST vault/reset/request-code -> { sent, email_hint, counts: { customers, works } }
 */
export const vaultResetRequestCode = () => post('vault/reset/request-code')

/**
 * Destructive: wipe the vault configuration.
 * POST vault/reset { confirm, code } OR { confirm, password, otp } -> { reset: true }
 */
export const vaultReset = (data) => post('vault/reset', data)
