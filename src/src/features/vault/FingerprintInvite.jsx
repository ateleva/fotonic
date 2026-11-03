import { useEffect, useState } from 'react'
import { Fingerprint } from 'lucide-react'
import { __ } from '../../utils/i18n'
import { fetchFingerprintChallenge } from '../../api/vault'
import { prfSupported } from '../../lib/prf'
import Button from '../../components/Button'

const DISMISSED_KEY = 'ftnc_fp_invite_dismissed'

function read(storage, key) {
  try { return storage.getItem(key) } catch { return null }
}
function write(storage, key, value) {
  try { storage.setItem(key, value) } catch { /* ignore */ }
}

/**
 * One small card, once: after unlocking with the password, if this browser can do it and nobody has
 * turned it on for this address yet, offer the fingerprint. "Not now" is remembered for good.
 * Rendered inside #fotonic-app-root (Tailwind is scoped there), like the floating progress bar.
 */
export default function FingerprintInvite() {
  const [show, setShow] = useState(false)

  useEffect(() => {
    if (read(window.sessionStorage, 'ftnc_fp_invite') !== '1') return undefined
    if (read(window.localStorage, DISMISSED_KEY) === '1') return undefined
    let alive = true
    ;(async () => {
      try {
        if (!(await prfSupported())) return
        const res = await fetchFingerprintChallenge(window.location.hostname)
        if (alive && (res?.devices ?? []).length === 0) setShow(true)
      } catch { /* no invite */ }
    })()
    return () => { alive = false }
  }, [])

  if (!show) return null

  const close = () => {
    write(window.localStorage, DISMISSED_KEY, '1')
    try { window.sessionStorage.removeItem('ftnc_fp_invite') } catch { /* ignore */ }
    setShow(false)
  }

  return (
    <div role="status" className="fixed bottom-6 right-6 z-[9999] w-80 rounded-xl border border-gray-200 bg-white p-4 shadow-lg">
      <div className="flex items-start gap-3">
        <Fingerprint size={20} className="mt-0.5 shrink-0 text-indigo-500" />
        <div>
          <p className="text-sm font-semibold text-gray-900">{__('Unlock faster', 'eleva-crm-for-photographers')}</p>
          <p className="mt-1 text-xs text-gray-500">{__('Next time, open the Vault with a touch. It takes a few seconds to set up.', 'eleva-crm-for-photographers')}</p>
        </div>
      </div>
      <div className="mt-3 flex justify-end gap-2">
        <Button size="sm" variant="secondary" onClick={close}>{__('Not now', 'eleva-crm-for-photographers')}</Button>
        <Button size="sm" variant="primary" onClick={() => { close(); window.location.hash = '#/settings' }}>{__('Turn on', 'eleva-crm-for-photographers')}</Button>
      </div>
    </div>
  )
}
