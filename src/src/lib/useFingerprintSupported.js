import { useEffect, useState } from 'react'
import { prfSupported } from './prf'

/** True once the browser says it can do fingerprint unlock; false while checking or when it cannot. */
export function useFingerprintSupported() {
  const [supported, setSupported] = useState(false)
  useEffect(() => {
    let alive = true
    prfSupported().then((ok) => { if (alive) setSupported(ok) })
    return () => { alive = false }
  }, [])
  return supported
}
