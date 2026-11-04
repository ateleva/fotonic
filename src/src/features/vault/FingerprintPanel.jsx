import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Fingerprint, Trash2 } from 'lucide-react'
import { __, sprintf } from '../../utils/i18n'
import { useVault } from '../../context/VaultContext'
import { fetchFingerprintDevices, fingerprintBegin, fingerprintFinish, fingerprintRemove } from '../../api/vault'
import { enrollCredential, guessDeviceName } from '../../lib/prf'
import PasswordInput from '../../components/PasswordInput'
import ConfirmDialog from '../../components/ConfirmDialog'

const inputCls = 'w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'

function Field({ label, children }) {
  return (
    <div className="space-y-1">
      <label className="block text-xs font-medium text-gray-600">{label}</label>
      {children}
    </div>
  )
}

function errorText(err) {
  switch (err?.code) {
    case 'cancelled':
      return __('The fingerprint check was cancelled. Try again when you are ready.', 'eleva-crm-for-photographers')
    case 'prf_unsupported':
      return __('This browser or device cannot do fingerprint unlock.', 'eleva-crm-for-photographers')
    case 'prf_inconsistent':
      return __('The device gave different answers. Try again.', 'eleva-crm-for-photographers')
    case 'already':
      return __('This device already opens the Vault with a fingerprint: its passkey is shared with another browser or device. There is nothing to turn on.', 'eleva-crm-for-photographers')
    case 'insecure':
      return __('The browser refused because of the website address. Fingerprint unlock needs a secure (https) address.', 'eleva-crm-for-photographers')
    case 'failed':
      return __('The device did not complete the setup. Try again with a new code, or use another browser.', 'eleva-crm-for-photographers')
    default:
      return err?.message || __('Could not turn on fingerprint unlock.', 'eleva-crm-for-photographers')
  }
}

/**
 * @param {boolean} supported This browser can turn the fingerprint on. The list and "Remove" show either way,
 *   so a device enrolled elsewhere can still be removed from a browser that cannot enrol.
 */
export default function FingerprintPanel({ supported = true }) {
  const queryClient = useQueryClient()
  const { isUnlocked } = useVault()
  const [form, setForm] = useState({ password: '', otp: '', label: guessDeviceName() })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [done, setDone] = useState(false)
  const [toRemove, setToRemove] = useState(null)
  const [removeError, setRemoveError] = useState('')

  const { data } = useQuery({
    queryKey: ['vault-fingerprint'],
    queryFn: fetchFingerprintDevices,
    // "Last used" changes at every unlock, also from another browser: never show a cached list.
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })
  const devices = data?.devices ?? []

  const set = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setDone(false)
    if (!form.password || !form.otp) {
      setError(__('All fields are required.', 'eleva-crm-for-photographers'))
      return
    }
    setBusy(true)
    try {
      const begin = await fingerprintBegin({ password: form.password, otp: form.otp })
      const label = form.label.trim() || guessDeviceName()
      const { credId, kek, host } = await enrollCredential({ salt: begin.salt, label, existingIds: devices.filter((d) => d.host === window.location.hostname).map((d) => d.cred_id) })
      await fingerprintFinish({ token: begin.token, cred_id: credId, kek, host, label })
      setForm({ password: '', otp: '', label: guessDeviceName() })
      setDone(true)
      await queryClient.invalidateQueries({ queryKey: ['vault-fingerprint'] })
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    } catch (err) {
      setError(errorText(err))
    } finally {
      setBusy(false)
    }
  }

  async function handleRemove() {
    const target = toRemove
    setToRemove(null)
    setRemoveError('')
    try {
      await fingerprintRemove(target.cred_id)
      await queryClient.invalidateQueries({ queryKey: ['vault-fingerprint'] })
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    } catch (err) {
      setRemoveError(err.message || __('Could not remove the device.', 'eleva-crm-for-photographers'))
    }
  }

  const here = window.location.hostname

  return (
    <div className="space-y-3 pt-1">
      <p className="text-xs text-gray-500">
        {__('Open the Vault with a touch instead of typing the password and the code. Your password and code keep working, and you will need them if you lose this device.', 'eleva-crm-for-photographers')}
      </p>
      <ul className="text-xs text-gray-500 space-y-1 list-disc list-inside">
        <li>{__('It uses the check your device already has: fingerprint, face or the device PIN. Anyone who can unlock this device can open the Vault from it.', 'eleva-crm-for-photographers')}</li>
        <li>{__('If you save passkeys in iCloud or Google, whoever controls that account can also use it on other devices.', 'eleva-crm-for-photographers')}</li>
        <li>{__('It works only on this website address. If the address changes, turn it on again.', 'eleva-crm-for-photographers')}</li>
      </ul>

      {devices.length > 0 ? (
        <div className="rounded-md border border-gray-200 divide-y divide-gray-100">
          {devices.map((d) => (
            <div key={d.cred_id} className="px-3 py-2 text-sm text-gray-700">
              <div className="flex items-center gap-2 min-w-0">
                <Fingerprint size={14} className="text-indigo-500 shrink-0" />
                <span className="font-medium min-w-0 break-words">{d.label}</span>
              </div>
              <p className="mt-1 text-xs text-gray-400 break-all">{d.host}</p>
              <p className="mt-1 text-xs text-gray-400">
                {d.last_used
                  ? sprintf(__('Last used: %s', 'eleva-crm-for-photographers'), new Date(d.last_used * 1000).toLocaleString())
                  : __('Not used yet', 'eleva-crm-for-photographers')}
              </p>
              {d.host !== here && (
                <p className="mt-1 text-xs text-amber-700">
                  {sprintf(__('Turned on for %s. It will not work on this address: turn it on again here, or remove it.', 'eleva-crm-for-photographers'), d.host)}
                </p>
              )}
              <div className="mt-2 flex justify-end">
                <button
                  type="button"
                  onClick={() => setToRemove(d)}
                  disabled={!isUnlocked}
                  title={isUnlocked ? undefined : __('Unlock the vault to remove a device.', 'eleva-crm-for-photographers')}
                  className="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                >
                  <Trash2 size={12} />
                  {__('Remove', 'eleva-crm-for-photographers')}
                </button>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <p className="text-xs text-gray-400">{__('No device uses the fingerprint yet.', 'eleva-crm-for-photographers')}</p>
      )}
      {devices.length > 1 && (
        <p className="text-xs text-gray-400">{__('On the same computer, your browsers may share one passkey, so a browser can open the Vault with the entry of another one. That is normal, and "Last used" then shows on that entry.', 'eleva-crm-for-photographers')}</p>
      )}
      {removeError && <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{removeError}</p>}
      <ConfirmDialog
        open={toRemove !== null}
        onClose={() => setToRemove(null)}
        onConfirm={handleRemove}
        title={__('Remove this device', 'eleva-crm-for-photographers')}
        message={__('This device will no longer open the Vault with the fingerprint. Your password and code are not affected.', 'eleva-crm-for-photographers')}
        confirmLabel={__('Remove', 'eleva-crm-for-photographers')}
      />

      {!supported ? (
        <p className="text-xs text-gray-400">{__('This browser cannot turn on fingerprint unlock. You can still remove devices here.', 'eleva-crm-for-photographers')}</p>
      ) : !isUnlocked ? (
        <p className="text-xs text-gray-400">{__('Unlock the vault to turn this on.', 'eleva-crm-for-photographers')}</p>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-3">
          <div className="ftnc-grid-2">
            <Field label={__('Vault Password', 'eleva-crm-for-photographers')}>
              <PasswordInput value={form.password} onChange={set('password')} autoComplete="current-password" className={inputCls} />
            </Field>
            <Field label={__('Current OTP Code', 'eleva-crm-for-photographers')}>
              <input type="text" inputMode="numeric" maxLength={6} value={form.otp} onChange={set('otp')} placeholder="000000" autoComplete="one-time-code" className={inputCls + ' font-mono tracking-widest'} />
            </Field>
            <Field label={__('Device name', 'eleva-crm-for-photographers')}>
              <input type="text" maxLength={60} value={form.label} onChange={set('label')} className={inputCls} />
            </Field>
          </div>
          <p className="text-xs text-gray-400">{__('Your device will ask you to confirm twice. This only happens once. Each code works only once: if you just used it, wait for the next one.', 'eleva-crm-for-photographers')}</p>
          {error && <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>}
          {done && <p className="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{__('Fingerprint unlock is on for this device.', 'eleva-crm-for-photographers')}</p>}
          <div className="flex justify-end">
            <button type="submit" disabled={busy} className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
              <Fingerprint size={14} />
              {busy ? __('Waiting for your device…', 'eleva-crm-for-photographers') : __('Turn on fingerprint unlock', 'eleva-crm-for-photographers')}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
