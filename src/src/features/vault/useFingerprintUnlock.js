import { useCallback, useEffect, useRef, useState } from 'react'
import { __ } from '../../utils/i18n'
import { fetchFingerprintChallenge, fingerprintUnlock } from '../../api/vault'
import { prfSupported, unlockCredential } from '../../lib/prf'
import { autoPromptAllowed } from './vaultSession'

/**
 * Fingerprint unlock for the lock screen. Looks for devices enrolled for this host name; when there
 * are some it asks for the fingerprint once on its own, only on the lock screen after a page load and
 * only in a window that has the focus (never again by itself), and offers a button.
 * `onUnlocked` runs after the server opened the Vault.
 */
export function useFingerprintUnlock(onUnlocked) {
  const [devices, setDevices] = useState([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const autoRef = useRef(false)
  const onUnlockedRef = useRef(onUnlocked)
  useEffect(() => { onUnlockedRef.current = onUnlocked })

  const load = useCallback(async () => {
    if (!(await prfSupported())) return []
    const res = await fetchFingerprintChallenge(window.location.hostname)
    const list = res?.devices ?? []
    setDevices(list)
    return list
  }, [])

  const attempt = useCallback(async (list) => {
    if (!list.length) return
    setBusy(true)
    setError('')
    try {
      const { device, kekCur, kekNext } = await unlockCredential(list)
      await fingerprintUnlock({ cred_id: device.cred_id, gen: device.gen, kek_cur: kekCur, kek_next: kekNext })
      await onUnlockedRef.current?.()
    } catch (err) {
      if (err?.code === 'cancelled') {
        // The person closed the prompt: nothing to say, the button and the password form are there.
      } else if (err?.status === 409) {
        // Another tab rotated first. Fetch the new salts; the person presses the button again.
        await load().catch(() => {})
        setError(__('The fingerprint setup changed in the meantime. Try again.', 'eleva-crm-for-photographers'))
      } else if (err?.status === 429) {
        setError(err.message)
      } else if (err?.status === 401) {
        setError(__('The fingerprint was not accepted. Use your password and code.', 'eleva-crm-for-photographers'))
      } else if (err?.code === 'insecure') {
        setError(__('The browser refused because of the website address. Fingerprint unlock needs a secure (https) address.', 'eleva-crm-for-photographers'))
      } else {
        setError(__('The fingerprint did not work. Use your password and code.', 'eleva-crm-for-photographers'))
      }
    } finally {
      setBusy(false)
    }
  }, [load])

  useEffect(() => {
    if (autoRef.current) return
    autoRef.current = true
    load()
      .then((list) => { if (list.length && autoPromptAllowed() && document.visibilityState === 'visible' && document.hasFocus()) attempt(list) })
      .catch(() => {})
  }, [load, attempt])

  return { available: devices.length > 0, busy, error, start: () => attempt(devices) }
}
