/**
 * VaultRecoveryPanel
 *
 * Shown from VaultLock when the user clicks "Lost your password or authenticator?"
 *
 * Modes:
 *   PICK         two cards
 *   HAVE_SECRET  recovery phrase OR code + new password -> POST vault/recovery/begin
 *                (the gate then shows the new-authenticator screen and the kit wizard)
 *   LOST_ALL     point to the kit email first, then the email-gated destructive reset
 *
 * Props:
 *   onBack       {fn}  go back to unlock form
 *   onUnlocked   {fn}  called after recovery began, or ({ reset: true }) after a reset
 */
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, KeyRound, ShieldAlert } from 'lucide-react'
import { fetchVaultStatus, vaultRecoveryBegin } from '../../api/vault'
import { useVault } from '../../context/VaultContext'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import PasswordInput from '../../components/PasswordInput'
import Spinner from '../../components/Spinner'
import ResetVaultFlow from './ResetVaultFlow'
import { __, sprintf } from '../../utils/i18n'
import { MIN_VAULT_PASSWORD } from '../../utils/recoveryKit'

const inputCls =
  'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'

// ---------------------------------------------------------------------------
// HAVE_SECRET: recovery phrase or code + new password
// ---------------------------------------------------------------------------

function HaveSecretForm({ onUnlocked }) {
  const { markUnlocked } = useVault()
  const [form, setForm] = useState({ secret: '', new_password: '', confirm: '' })
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState(null)

  const f = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    if (!form.secret.trim() || !form.new_password || !form.confirm) {
      setError(__('All fields are required.', 'eleva-crm-for-photographers'))
      return
    }
    if (form.new_password !== form.confirm) {
      setError(__('Passwords do not match.', 'eleva-crm-for-photographers'))
      return
    }
    if (form.new_password.length < MIN_VAULT_PASSWORD) {
      setError(__('New password must be at least 8 characters.', 'eleva-crm-for-photographers'))
      return
    }

    setLoading(true)
    try {
      await vaultRecoveryBegin({
        recovery_secret: form.secret.trim(),
        new_password:    form.new_password,
      })
      // Refresh status first so the gate never paints the app from stale totp_pending=false.
      await onUnlocked?.()
      markUnlocked()
    } catch (err) {
      if (err.status === 401) {
        setError(__('This recovery phrase or code is not valid.', 'eleva-crm-for-photographers'))
      } else {
        setError(err.message || __('Recovery failed. Check your recovery phrase or code and try again.', 'eleva-crm-for-photographers'))
      }
      setLoading(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="text-sm text-gray-500">
        {__('Enter your recovery phrase or recovery code and choose a new password. You will then set up a new authenticator.', 'eleva-crm-for-photographers')}
      </p>

      <FormField label={__('Recovery phrase or recovery code', 'eleva-crm-for-photographers')} required htmlFor="rec-secret" error={null}>
        <textarea
          id="rec-secret"
          autoComplete="off"
          spellCheck={false}
          rows={2}
          className={inputCls + ' font-mono resize-none'}
          value={form.secret}
          onChange={f('secret')}
        />
      </FormField>

      <FormField label={__('New Password', 'eleva-crm-for-photographers')} required htmlFor="rec-newpw" error={null}>
        <PasswordInput
          id="rec-newpw"
          autoComplete="new-password"
          className={inputCls}
          value={form.new_password}
          onChange={f('new_password')}
        />
      </FormField>

      <FormField label={__('Confirm New Password', 'eleva-crm-for-photographers')} required htmlFor="rec-confirmpw" error={null}>
        <PasswordInput
          id="rec-confirmpw"
          autoComplete="new-password"
          className={inputCls}
          value={form.confirm}
          onChange={f('confirm')}
        />
      </FormField>

      {error && (
        <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
      )}

      <Button type="submit" variant="primary" className="w-full" disabled={loading}>
        {loading ? <Spinner size="sm" /> : __('Recover Access', 'eleva-crm-for-photographers')}
      </Button>
    </form>
  )
}

// ---------------------------------------------------------------------------
// LOST_ALL: inbox first, then the destructive reset
// ---------------------------------------------------------------------------

function LostAll({ onFound, onReset }) {
  const [showReset, setShowReset] = useState(false)
  const { data: status } = useQuery({
    queryKey: ['vault-status'],
    queryFn: fetchVaultStatus,
  })

  if (showReset) {
    return <ResetVaultFlow onDone={onReset} />
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-gray-600">
        {sprintf(__("Search the inbox of %s for an email titled 'Vault recovery kit'. It holds your phrase and code.", 'eleva-crm-for-photographers'), status?.email_hint ?? '')}
      </p>
      <Button variant="primary" className="w-full" onClick={onFound}>
        {__('I found it', 'eleva-crm-for-photographers')}
      </Button>
      <Button variant="secondary" className="w-full" onClick={() => setShowReset(true)}>
        {__('I have nothing: reset the vault', 'eleva-crm-for-photographers')}
      </Button>
    </div>
  )
}

// ---------------------------------------------------------------------------
// VaultRecoveryPanel - mode picker + sub-flow renderer
// ---------------------------------------------------------------------------

const MODES = {
  PICK:        'pick',
  HAVE_SECRET: 'have_secret',
  LOST_ALL:    'lost_all',
}

export default function VaultRecoveryPanel({ onBack, onUnlocked }) {
  const [mode, setMode] = useState(MODES.PICK)

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={mode === MODES.PICK ? onBack : () => setMode(MODES.PICK)}
          className="text-gray-400 hover:text-gray-600 transition-colors"
          aria-label={__('Back', 'eleva-crm-for-photographers')}
        >
          <ArrowLeft size={18} />
        </button>
        <h2 className="text-base font-semibold text-gray-800">
          {mode === MODES.PICK        && __('Account Recovery', 'eleva-crm-for-photographers')}
          {mode === MODES.HAVE_SECRET && __('Recover Access', 'eleva-crm-for-photographers')}
          {mode === MODES.LOST_ALL    && __('Reset Vault', 'eleva-crm-for-photographers')}
        </h2>
      </div>

      {mode === MODES.PICK && (
        <div className="space-y-3">
          <p className="text-sm text-gray-500">
            {__('What do you need help with?', 'eleva-crm-for-photographers')}
          </p>

          <button
            type="button"
            onClick={() => setMode(MODES.HAVE_SECRET)}
            className="w-full flex items-start gap-3 rounded-lg border border-gray-200 p-4 text-left hover:border-indigo-300 hover:bg-indigo-50 transition-colors"
          >
            <KeyRound size={20} className="text-indigo-500 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-gray-800">
                {__('I have my recovery phrase or code', 'eleva-crm-for-photographers')}
              </p>
              <p className="text-xs text-gray-500 mt-0.5">
                {__('Set a new password and a new authenticator without losing any data.', 'eleva-crm-for-photographers')}
              </p>
            </div>
          </button>

          <button
            type="button"
            onClick={() => setMode(MODES.LOST_ALL)}
            className="w-full flex items-start gap-3 rounded-lg border border-gray-200 p-4 text-left hover:border-indigo-300 hover:bg-indigo-50 transition-colors"
          >
            <ShieldAlert size={20} className="text-amber-500 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-gray-800">
                {__('I lost everything', 'eleva-crm-for-photographers')}
              </p>
              <p className="text-xs text-gray-500 mt-0.5">
                {__('I no longer have my password, authenticator, recovery phrase or code.', 'eleva-crm-for-photographers')}
              </p>
            </div>
          </button>
        </div>
      )}

      {mode === MODES.HAVE_SECRET && (
        <HaveSecretForm onUnlocked={onUnlocked} />
      )}

      {mode === MODES.LOST_ALL && (
        <LostAll
          onFound={() => setMode(MODES.HAVE_SECRET)}
          onReset={() => onUnlocked?.({ reset: true })}
        />
      )}
    </div>
  )
}
