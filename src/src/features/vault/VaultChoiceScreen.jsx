import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ShieldCheck, ShieldOff, KeyRound, EyeOff } from 'lucide-react'
import { setVaultChoice } from '../../api/vault'
import Button from '../../components/Button'
import GateScreen, { GateCard, GateNote } from '../../components/GateScreen'
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
    return <VaultSetup onBack={() => setSettingUp(false)} />
  }

  return (
    <GateScreen>
      <GateCard
        size="full"
        icon={ShieldCheck}
        title={__('Do you want to protect personal data with the Vault?', 'eleva-crm-for-photographers')}
        subtitle={[
          __('The Vault is an extra layer of security. You choose a password and add a code from an authenticator app, and every time you open Eleva CRM after locking it you unlock it with both.', 'eleva-crm-for-photographers'),
          __('That is the only cost: one more password to enter.', 'eleva-crm-for-photographers'),
          __('If your computer or phone has a fingerprint reader or face unlock, you can then turn on unlocking with a touch from Settings, under Vault.', 'eleva-crm-for-photographers'),
        ]}
        footer={(
          <>
            <Button variant="secondary" disabled={busy} onClick={handleNotNow}>
              {__('Not now', 'eleva-crm-for-photographers')}
            </Button>
            <Button disabled={busy} onClick={() => setSettingUp(true)}>
              {__('Protect personal data with the Vault (recommended)', 'eleva-crm-for-photographers')}
            </Button>
          </>
        )}
      >
        <div className="grid gap-3 md:grid-cols-2">
          <GateNote accent icon={ShieldCheck} title={__('What the Vault protects', 'eleva-crm-for-photographers')}>
            {__('Personal details of your clients (names, emails, phone numbers, addresses, tax codes), event addresses, your notes and your service catalog (titles, prices and notes). With Eleva CRM Pro, also your collaborators, suppliers, products, expense notes and quote notes.', 'eleva-crm-for-photographers')}
          </GateNote>
          <GateNote icon={EyeOff} title={__('What stays readable', 'eleva-crm-for-photographers')}>
            {__('Dates, work prices and payments, statistics, and the titles of your clients, works and quotes.', 'eleva-crm-for-photographers')}
          </GateNote>
          <GateNote icon={ShieldOff} title={__('If you choose Not now', 'eleva-crm-for-photographers')}>
            {__('Your personal data is stored as plain text in the database, and entering it without the Vault does not make it safer. Encrypted backups, including the ones sent to Google Drive with Eleva CRM Pro, are not available. You can turn the Vault on later from Settings: the data you already entered is encrypted at that moment.', 'eleva-crm-for-photographers')}
          </GateNote>
          <GateNote icon={KeyRound} title={__('Your responsibility', 'eleva-crm-for-photographers')}>
            {__('The Vault is a security extra, and keeping its keys safe is up to you, like the keys of a crypto wallet. Store your password, your recovery code and your recovery phrase in a safe place. If you lose all of them and reset the Vault, the encrypted data is lost for good, while the data that stays readable is kept. With the recovery code or the recovery phrase you lose nothing: recovering is not resetting.', 'eleva-crm-for-photographers')}
          </GateNote>
        </div>
        {error && <p className="mt-3 text-sm text-red-600">{error}</p>}
      </GateCard>
    </GateScreen>
  )
}
