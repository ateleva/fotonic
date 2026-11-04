import { useEffect, useState } from 'react'
import { prfSupport } from './prf'

/**
 * What the browser says about fingerprint unlock. `reason` is '' while checking or when supported, else
 * 'insecure' (page not treated as secure), 'no_webauthn' or 'no_prf'.
 */
export function useFingerprintSupport() {
  const [state, setState] = useState({ supported: false, reason: '', checking: true })
  useEffect(() => {
    let alive = true
    prfSupport().then((r) => { if (alive) setState({ ...r, checking: false }) })
    return () => { alive = false }
  }, [])
  return state
}
