import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import { fetchVaultStatus, vaultUnlock, vaultLock } from '../api/vault'

const VaultContext = createContext(null)

// Idle timer thresholds
const IDLE_WARN_MS  = 13 * 60 * 1000  // 13 min → warning
const IDLE_LOCK_MS  = 15 * 60 * 1000  // 15 min → auto-lock
const IDLE_CHECK_MS = 30 * 1000        // check every 30s

const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll']

export function VaultProvider({ children }) {
  const [isUnlocked, setIsUnlocked] = useState(false)
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
        lockRef.current?.()
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

  const lock = useCallback(async () => {
    stopIdleTimer()
    try { await vaultLock() } catch { /* ignore network errors on lock */ }
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
