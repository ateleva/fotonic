import { createContext, useCallback, useContext, useState } from 'react'
import { deriveKey } from '../lib/webcrypto'
import { apiFetch, setVaultKey } from '../api/client'

const VaultContext = createContext(null)

export function VaultProvider({ children }) {
  const [derivedKey, setDerivedKey] = useState(null)
  const [isUnlocked, setIsUnlocked] = useState(false)

  const unlock = useCallback(async (password, otp) => {
    // Salt returned only on successful unlock — never exposed on status polls.
    const data = await apiFetch('vault/unlock', {
      method: 'POST',
      body: JSON.stringify({ password, otp }),
    })
    if (!data.salt) throw new Error('Vault salt not returned by server')

    // Browser key derivation requires a secure context (HTTPS).
    // Gracefully skip on plain HTTP (e.g. local dev) — server-side v1 decryption still works.
    if (window.crypto?.subtle) {
      try {
        const key = await deriveKey(password, data.salt)
        setDerivedKey(key)
        setVaultKey(key)
      } catch (e) {
        console.warn('[Fotonic] Browser key derivation failed:', e)
      }
    }

    setIsUnlocked(true)
  }, [])

  const lock = useCallback(async () => {
    setDerivedKey(null)
    setIsUnlocked(false)
    setVaultKey(null)
    await apiFetch('vault/lock', { method: 'POST' }).catch(() => {})
  }, [])

  return (
    <VaultContext.Provider value={{ derivedKey, isUnlocked, unlock, lock }}>
      {children}
    </VaultContext.Provider>
  )
}

export const useVault = () => useContext(VaultContext)
