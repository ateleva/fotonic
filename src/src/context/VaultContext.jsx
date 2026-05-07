import { createContext, useCallback, useContext, useState } from 'react'
import { deriveKey } from '../lib/webcrypto'
import { apiFetch, setVaultKey } from '../api/client'

const VaultContext = createContext(null)

export function VaultProvider({ children }) {
  const [derivedKey, setDerivedKey] = useState(null)
  const [isUnlocked, setIsUnlocked] = useState(false)

  const unlock = useCallback(async (password, otp) => {
    await apiFetch('vault/unlock', {
      method: 'POST',
      body: JSON.stringify({ password, otp }),
    })
    const status = await apiFetch('vault/status')
    if (!status.salt) throw new Error('Vault salt not returned by server')
    const key = await deriveKey(password, status.salt)
    setDerivedKey(key)
    setIsUnlocked(true)
    setVaultKey(key)
    return key
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
