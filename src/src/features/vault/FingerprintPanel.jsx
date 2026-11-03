import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Fingerprint } from 'lucide-react'
import { __ } from '../../utils/i18n'
import { useVault } from '../../context/VaultContext'
import { fetchFingerprintDevices, fingerprintBegin, fingerprintFinish } from '../../api/vault'
import { enrollCredential, guessDeviceName } from '../../lib/prf'
import PasswordInput from '../../components/PasswordInput'

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
    default:
      return err?.message || __('Could not turn on fingerprint unlock.', 'eleva-crm-for-photographers')
  }
}

export default function FingerprintPanel() {
  const queryClient = useQueryClient()
  const { isUnlocked } = useVault()
  const [form, setForm] = useState({ password: '', otp: '', label: guessDeviceName() })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [done, setDone] = useState(false)

  const { data } = useQuery({
    queryKey: ['vault-fingerprint'],
    queryFn: fetchFingerprintDevices,
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
      const { credId, kek, host } = await enrollCredential({ salt: begin.salt, label })
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

      {devices.length > 0 && (
        <div className="rounded-md border border-gray-200 divide-y divide-gray-100">
          {devices.map((d) => (
            <div key={d.cred_id} className="flex items-center gap-2 px-3 py-2 text-sm text-gray-700">
              <Fingerprint size={14} className="text-indigo-500 shrink-0" />
              <span className="font-medium">{d.label}</span>
              <span className="text-xs text-gray-400">{d.host}</span>
            </div>
          ))}
        </div>
      )}

      {!isUnlocked ? (
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
          <p className="text-xs text-gray-400">{__('Your device will ask you to confirm twice. This only happens once.', 'eleva-crm-for-photographers')}</p>
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
