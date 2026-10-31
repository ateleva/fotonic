// Pure helpers of the Vault screens (first-run choice, encrypt-existing job). No React, no i18n, no
// network: they can be checked with plain node (see the phase 04 notes).

export const PHASES = ['scan', 'analyze', 'prepare', 'encrypt', 'finish']

const DISMISS_KEY = 'ftnc_encrypt_dismissed'

/** The first-run choice screen: no Vault and the person was never asked. */
export function showChoice(status) {
  return !!status && status.setup === false && (status.choice ?? '') === ''
}

/**
 * What the encrypt-existing job asks of the screen, given the status and whether the person cancelled
 * it in this browser session.
 *   'modal' : the full screen (a job that is pending, running here or elsewhere, or failed)
 *   'bar'   : the floating bar over the app (paused)
 *   null    : nothing
 * Pending and failed jobs stay hidden after "Cancel and continue later" until the page is reloaded
 * (the next status read recreates a pending job, so without this flag the screen would come straight back).
 */
export function encryptView(status, dismissed) {
  if (!status || !status.encrypt_pending) return null
  const s = status.encrypt?.status
  if (s === 'paused') return 'bar'
  if (s === 'running') return 'modal'
  if (s === 'pending' || s === 'error') return dismissed ? null : 'modal'
  return null
}

/** Index (0 to 4) of the step the job is in, for the list of five steps. */
export function stepIndex(phase) {
  const i = PHASES.indexOf(phase)
  return i < 0 ? 0 : i
}

/** Remaining time as a unit and a number, so the screen can say it in the right language. */
export function etaParts(seconds) {
  if (seconds === null || seconds === undefined || !Number.isFinite(seconds)) return null
  if (seconds < 60) return { unit: 'less' }
  return { unit: 'minutes', n: Math.ceil(seconds / 60) }
}

/** The status answer of a step merged into the cached vault status (never replaces the whole object). */
export function mergeEncrypt(prev, enc) {
  if (!prev || !enc) return prev
  const open = enc.status !== 'done' && enc.status !== 'none'
  return { ...prev, encrypt: enc, encrypt_pending: open }
}

export function readDismissed() {
  try {
    return window.sessionStorage.getItem(DISMISS_KEY) === '1'
  } catch {
    return false
  }
}

export function writeDismissed() {
  try {
    window.sessionStorage.setItem(DISMISS_KEY, '1')
  } catch {
    // Private window or blocked storage: the flag then lives only in the component state.
  }
}
