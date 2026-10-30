import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { fetchVaultStatus, vaultUnlock, vaultLock } from '../api/vault'

// A real default (not null) so any render that happens outside VaultProvider,
// or in the brief window before it commits, degrades to "vault appears
// locked, actions are safe no-ops" instead of throwing on destructure. Seen
// in practice as an intermittent crash on Settings (both a bare
// `Cannot destructure property 'idleWarning' of null` and, once, a
// downstream React DOM reconciliation error) that a fresh page load can hit
// during the error-boundary recovery cycle. Shape must match VaultProvider's
// real `value` below.
const VAULT_CONTEXT_DEFAULT = {
  isUnlocked: false,
  idleWarning: false,
  unlock: async () => ({ error: 'no_provider', message: 'Vault is not available yet.' }),
  silentReopen: async () => false,
  lock: async () => {},
  markUnlocked: () => {},
  resetIdle: () => {},
}

const VaultContext = createContext(VAULT_CONTEXT_DEFAULT)

// Idle timer thresholds
const IDLE_WARN_MS  = 13 * 60 * 1000  // 13 min → warning
const IDLE_LOCK_MS  = 15 * 60 * 1000  // 15 min → auto-lock
const IDLE_CHECK_MS = 30 * 1000        // check every 30s

const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll']

export function VaultProvider({ children }) {
  const [isUnlocked, setIsUnlocked] = useState(false)
  const queryClient = useQueryClient()
  const [idleWarning, setIdleWarning] = useState(false)

  // eslint-disable-next-line react-hooks/purity
  const lastActivityRef = useRef(Date.now())
  const timerRef        = useRef(null)

  // ---------------------------------------------------------------------------
  // Idle timer helpers
  // ---------------------------------------------------------------------------

  const stopIdleTimer = useCallback(() => {
    if (timerRef.current) {
      clearInterval(timerRef.current)
      timerRef.current = null
    }
  }, [])

  const lockRef = useRef(null)

  const startIdleTimer = useCallback(() => {
    stopIdleTimer()
    lastActivityRef.current = Date.now()
    timerRef.current = setInterval(() => {
      const elapsed = Date.now() - lastActivityRef.current
      if (elapsed >= IDLE_LOCK_MS) {
        lockRef.current?.('device')
      } else if (elapsed >= IDLE_WARN_MS) {
        setIdleWarning(true)
      }
    }, IDLE_CHECK_MS)
  }, [stopIdleTimer])

  // ---------------------------------------------------------------------------
  // Activity listeners
  // ---------------------------------------------------------------------------

  useEffect(() => {
    const bump = () => { lastActivityRef.current = Date.now() }
    ACTIVITY_EVENTS.forEach((ev) => window.addEventListener(ev, bump, { passive: true }))
    return () => {
      ACTIVITY_EVENTS.forEach((ev) => window.removeEventListener(ev, bump))
    }
  }, [])

  // ---------------------------------------------------------------------------
  // lock
  // ---------------------------------------------------------------------------

  // `scope` is 'device' only from the idle timer; a click handler passes an event, which means "everywhere".
  const lock = useCallback(async (scope) => {
    stopIdleTimer()
    try { await vaultLock(scope === 'device' ? 'device' : undefined) } catch { /* ignore network errors on lock */ }
    setIsUnlocked(false)
    setIdleWarning(false)
  }, [stopIdleTimer])

  // eslint-disable-next-line react-hooks/refs
  lockRef.current = lock

  // ---------------------------------------------------------------------------
  // unlock(password, otp)
  // Calls POST vault/unlock; returns {ok:true} on success or {error:string}.
  // ---------------------------------------------------------------------------

  const unlock = useCallback(async (password, otp) => {
    try {
      await vaultUnlock({ password, otp })
      setIsUnlocked(true)
      setIdleWarning(false)
      startIdleTimer()
      return { ok: true }
    } catch (err) {
      return { error: 'invalid_credentials', message: err.message }
    }
  }, [startIdleTimer])

  // ---------------------------------------------------------------------------
  // silentReopen() — check server status after page refresh
  // ---------------------------------------------------------------------------

  const silentReopen = useCallback(async () => {
    try {
      const data = await fetchVaultStatus()
      if (data?.unlocked === true) {
        setIsUnlocked(true)
        setIdleWarning(false)
        startIdleTimer()
        return true
      }
      return false
    } catch {
      return false
    }
  }, [startIdleTimer])

  // ---------------------------------------------------------------------------
  // markUnlocked() — sync isUnlocked when server reports vault open
  // ---------------------------------------------------------------------------

  const markUnlocked = useCallback(() => {
    setIsUnlocked(true)
    startIdleTimer()
  }, [startIdleTimer])

  // ---------------------------------------------------------------------------
  // resetIdle
  // ---------------------------------------------------------------------------

  const resetIdle = useCallback(() => {
    lastActivityRef.current = Date.now()
    setIdleWarning(false)
  }, [])

  // ---------------------------------------------------------------------------
  // Locked from elsewhere: another tab, browser or device pressed Lock (or the Vault epoch changed:
  // password change, recovery, reset). Most reads do not fail when the Vault is locked, they just
  // return empty personal details, so the SPA asks the server every 30 seconds and when the tab
  // gets the focus back, and shows the lock screen as soon as the server says locked.
  // ---------------------------------------------------------------------------

  useEffect(() => {
    if (!isUnlocked) return undefined
    let busy = false
    const check = async () => {
      if (busy) return
      busy = true
      try {
        const data = await fetchVaultStatus()
        // Fresh server truth goes into the status query too: VaultGate re-opens the gate from that
        // cache (markUnlocked) and would undo the lock below with a stale "unlocked".
        queryClient.setQueryData(['vault-status'], data)
        if (data?.setup === true && data?.unlocked === false) {
          stopIdleTimer()
          setIsUnlocked(false)
          setIdleWarning(false)
        }
      } catch {
        /* network error: keep the screen as it is */
      } finally {
        busy = false
      }
    }
    const onVisible = () => { if (document.visibilityState === 'visible') check() }
    const timer = setInterval(check, 30 * 1000)
    window.addEventListener('focus', check)
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(timer)
      window.removeEventListener('focus', check)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [isUnlocked, stopIdleTimer, queryClient])

  // ---------------------------------------------------------------------------
  // Cleanup on unmount
  // ---------------------------------------------------------------------------

  useEffect(() => {
    return () => { stopIdleTimer() }
  }, [stopIdleTimer])

  // ---------------------------------------------------------------------------
  // Context value
  // ---------------------------------------------------------------------------

  const value = {
    isUnlocked,
    idleWarning,
    unlock,
    silentReopen,
    lock,
    markUnlocked,
    resetIdle,
  }

  return (
    <VaultContext.Provider value={value}>
      {children}
    </VaultContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export const useVault = () => useContext(VaultContext)
