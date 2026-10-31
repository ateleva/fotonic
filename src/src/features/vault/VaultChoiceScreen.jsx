import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Shield, ShieldOff, KeyRound, EyeOff } from 'lucide-react'
import { setVaultChoice } from '../../api/vault'
import Button from '../../components/Button'
import VaultSetup from './VaultSetup'
import { __ } from '../../utils/i18n'

/**
 * Shown once, before the app, to a site that has no Vault and was never asked. The person chooses
 * with the facts in front of them: what the Vault protects, what "Not now" means, and whose job it is
 * to keep the keys. "Not now" is remembered (option fotonic_vault_choice) and never asked again; the
 * Vault can be turned on any time from Settings.
 */
export default function VaultChoiceScreen() {
  const queryClient = useQueryClient()
  const [settingUp, setSettingUp] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function handleNotNow() {
    setBusy(true)
    setError(null)
    try {
      await setVaultChoice('skipped')
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    } catch (err) {
      setError(err?.message || __('An error occurred. Please try again.', 'eleva-crm-for-photographers'))
      setBusy(false)
    }
  }

  if (settingUp) {
    return (
      <div className="relative">
        <div className="absolute top-4 left-4 z-10">
          <Button variant="secondary" size="sm" onClick={() => setSettingUp(false)}>
            {__('Back', 'eleva-crm-for-photographers')}
          </Button>
        </div>
        <VaultSetup />
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-2xl p-8 space-y-5">
        <div className="text-center">
          <span className="text-3xl">&#x1F510;</span>
          <h1 className="mt-2 text-xl font-bold text-gray-900">
            {__('Do you want to protect personal data with the Vault?', 'eleva-crm-for-photographers')}
          </h1>
          <p className="mt-2 text-sm text-gray-600">
            {__('The Vault is an extra layer of security. You choose a password and add a code from an authenticator app, and every time you open Eleva CRM after locking it you unlock it with both. That is the only cost: one more password to enter.', 'eleva-crm-for-photographers')}
          </p>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div className="rounded-md border border-green-200 bg-green-50 p-4 space-y-1">
            <p className="flex items-center gap-2 text-sm font-semibold text-green-900"><Shield size={15} />{__('What the Vault protects', 'eleva-crm-for-photographers')}</p>
            <p className="text-sm text-green-800">
              {__('Personal details of your clients (names, emails, phone numbers, addresses, tax codes), event addresses, your notes and your service catalog (titles, prices and notes). With Eleva CRM Pro, also your collaborators, suppliers, products, expense notes and quote notes.', 'eleva-crm-for-photographers')}
            </p>
          </div>
          <div className="rounded-md border border-gray-200 bg-gray-50 p-4 space-y-1">
            <p className="flex items-center gap-2 text-sm font-semibold text-gray-800"><EyeOff size={15} />{__('What stays readable', 'eleva-crm-for-photographers')}</p>
            <p className="text-sm text-gray-700">
              {__('Dates, work prices and payments, statistics, and the titles of your clients, works and quotes.', 'eleva-crm-for-photographers')}
            </p>
          </div>
        </div>

        <div className="rounded-md border border-amber-200 bg-amber-50 p-4 space-y-1">
          <p className="flex items-center gap-2 text-sm font-semibold text-amber-900"><ShieldOff size={15} />{__('If you choose Not now', 'eleva-crm-for-photographers')}</p>
          <p className="text-sm text-amber-800">
            {__('Your personal data is stored as plain text in the database, and entering it without the Vault does not make it safer. Encrypted backups, including the ones sent to Google Drive with Eleva CRM Pro, are not available. You can turn the Vault on later from Settings: the data you already entered is encrypted at that moment.', 'eleva-crm-for-photographers')}
          </p>
        </div>

        <div className="rounded-md border border-blue-200 bg-blue-50 p-4 space-y-1">
          <p className="flex items-center gap-2 text-sm font-semibold text-blue-900"><KeyRound size={15} />{__('Your responsibility', 'eleva-crm-for-photographers')}</p>
          <p className="text-sm text-blue-800">
            {__('The Vault is a security extra, and keeping its keys safe is up to you, like the keys of a crypto wallet. Store your password, your recovery code and your recovery phrase in a safe place. If you lose all of them and reset the Vault, the encrypted data is lost for good, while the data that stays readable is kept. With the recovery code or the recovery phrase you lose nothing: recovering is not resetting.', 'eleva-crm-for-photographers')}
          </p>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
          <Button size="lg" disabled={busy} onClick={() => setSettingUp(true)}>
            {__('Protect personal data with the Vault (recommended)', 'eleva-crm-for-photographers')}
          </Button>
          <Button size="lg" variant="secondary" disabled={busy} onClick={handleNotNow}>
            {__('Not now', 'eleva-crm-for-photographers')}
          </Button>
        </div>
      </div>
    </div>
  )
}
