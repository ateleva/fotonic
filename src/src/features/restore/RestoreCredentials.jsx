import { useState } from 'react'
import { XCircle, Info } from 'lucide-react'
import PasswordInput from '../../components/PasswordInput'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import { formatWhen } from './restoreUtils'

/**
 * Step 2: this backup was made by another Vault (or by this one while it is locked), so it asks for the password,
 * the recovery code or the recovery phrase that belonged to that Vault WHEN THE BACKUP WAS MADE. The same text
 * field takes all three. Nothing is stored: the secret goes to the server once and is not kept.
 */
export default function RestoreCredentials({ manifest, onSubmit, busy, error }) {
  const [secret, setSecret] = useState('')
  const made = formatWhen(manifest?.generated_at)

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => { e.preventDefault(); if (secret.trim() && !busy) onSubmit(secret) }}
    >
      {made && (
        <p className="text-sm text-gray-700">
          {sprintf(__('This backup was made on %s.', 'eleva-crm-for-photographers'), made)}
        </p>
      )}
      <p className="text-sm text-gray-600">
        {__('The backup is locked. To open it, enter the Vault password, the recovery code or the recovery phrase that were valid on the day the backup was made. Without one of them the backup cannot be opened by anyone.', 'eleva-crm-for-photographers')}
      </p>
      <div className="rounded-md bg-blue-50 border border-blue-100 p-3 flex items-start gap-2">
        <Info size={14} className="text-blue-700 shrink-0 mt-0.5" />
        <p className="text-xs text-blue-900">
          {__('If you changed your password or made a new recovery kit after this backup was created, the new ones do not open it. Use the ones you had then.', 'eleva-crm-for-photographers')}
        </p>
      </div>
      <div>
        <label htmlFor="ftnc-restore-secret" className="block text-sm font-medium text-gray-700 mb-1">
          {__('Password, recovery code or recovery phrase', 'eleva-crm-for-photographers')}
        </label>
        <PasswordInput
          id="ftnc-restore-secret"
          value={secret}
          onChange={(e) => setSecret(e.target.value)}
          autoComplete="off"
          autoFocus
          className="rounded-md border border-gray-300 px-3 py-2 text-sm"
        />
      </div>
      {error && (
        <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2 flex items-start gap-1.5">
          <XCircle size={12} className="shrink-0 mt-0.5" />{error}
        </p>
      )}
      <div className="flex gap-3">
        <Button type="submit" variant="primary" disabled={busy || !secret.trim()}>
          {busy ? __('Opening…', 'eleva-crm-for-photographers') : __('Open the backup', 'eleva-crm-for-photographers')}
        </Button>
      </div>
    </form>
  )
}
