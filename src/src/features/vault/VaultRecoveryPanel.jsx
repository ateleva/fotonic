/**
 * VaultRecoveryPanel
 *
 * Shown from VaultLock when the user clicks "Lost your password or authenticator?"
 *
 * Two sub-flows:
 *   A) Lost authenticator (know password + have recovery code)
 *      → POST vault/recovery/reset-totp { password, recovery_code }
 *      → returns { qr_uri, totp_secret }
 *      → shows new QR for re-enrollment; user must re-unlock normally.
 *
 *   B) Lost password (have recovery phrase)
 *      → POST vault/recovery/reset-password-phrase { recovery_phrase, new_password }
 *      → returns { reset: true }
 *      → on success call onUnlocked() (caller invalidates vault-status)
 *
 * Props:
 *   onBack       {fn}  go back to unlock form
 *   onUnlocked   {fn}  called after successful recovery that opens the vault
 */
import { useState, useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { ArrowLeft, KeyRound, ShieldAlert } from 'lucide-react'
import { vaultRecoveryResetTotp, vaultRecoveryResetPasswordPhrase } from '../../api/vault'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import Spinner from '../../components/Spinner'
import { __ } from '../../utils/i18n'

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function QrCanvas({ uri, size = 180 }) {
  const ref = useRef(null)
  useEffect(() => {
    if (ref.current && uri) {
      QRCode.toCanvas(ref.current, uri, { width: size, margin: 2 })
    }
  }, [uri, size])
  return <canvas ref={ref} />
}

const inputCls =
  'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'

// ---------------------------------------------------------------------------
// Flow A: Lost authenticator — know password + have recovery code
// ---------------------------------------------------------------------------

function FlowLostAuthenticator({ onBack }) {
  const [form, setForm]   = useState({ password: '', recovery_code: '' })
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState(null)
  const [newQrUri, setNewQrUri]         = useState(null)
  const [newTotpSecret, setNewTotpSecret] = useState(null)

  const f = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    if (!form.password || !form.recovery_code) {
      setError(__('All fields are required.', 'eleva-crm-for-photographers'))
      return
    }
    setLoading(true)
    try {
      const data = await vaultRecoveryResetTotp({
        password:      form.password,
        recovery_code: form.recovery_code.trim(),
      })

      const secret = data.totp_secret
        ?? data.qr_uri?.match(/secret=([A-Z2-7]+)/i)?.[1]
        ?? ''

      setNewQrUri(data.qr_uri)
      setNewTotpSecret(secret)
    } catch (err) {
      setError(err.message || __('Recovery failed. Check your password and recovery code.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  if (newQrUri) {
    return (
      <div className="space-y-4">
        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3">
          <p className="text-sm font-medium text-green-800">
            {__('Authenticator reset. Scan the QR code to re-add your vault to your authenticator app, then unlock normally.', 'eleva-crm-for-photographers')}
          </p>
        </div>

        <div className="flex flex-col items-center gap-3">
          <div className="rounded-md border border-gray-200 p-2">
            <QrCanvas uri={newQrUri} size={180} />
          </div>
          {newTotpSecret && (
            <div className="w-full rounded-md bg-gray-50 border border-gray-200 p-3">
              <p className="text-xs text-gray-500 mb-1">{__('Manual entry key:', 'eleva-crm-for-photographers')}</p>
              <p className="text-sm font-mono font-semibold text-gray-800 break-all">{newTotpSecret}</p>
            </div>
          )}
        </div>

        <Button variant="primary" className="w-full" onClick={onBack}>
          {__('Done — go to unlock screen', 'eleva-crm-for-photographers')}
        </Button>
      </div>
    )
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="text-sm text-gray-500">
        {__('Enter your vault password and the one-time recovery code to generate a new authenticator entry.', 'eleva-crm-for-photographers')}
      </p>

      <FormField label={__('Vault Password', 'eleva-crm-for-photographers')} required htmlFor="rec-password" error={null}>
        <input
          id="rec-password"
          type="password"
          autoComplete="current-password"
          className={inputCls}
          value={form.password}
          onChange={f('password')}
        />
      </FormField>

      <FormField label={__('Recovery Code', 'eleva-crm-for-photographers')} required htmlFor="rec-code-a" error={null}>
        <input
          id="rec-code-a"
          type="text"
          autoComplete="off"
          placeholder="XXXX-XXXX-XXXX-XXXX"
          className={inputCls + ' font-mono'}
          value={form.recovery_code}
          onChange={f('recovery_code')}
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
// Flow B: Lost password — have recovery phrase, set new password
// ---------------------------------------------------------------------------

function FlowLostPassword({ onBack, onUnlocked }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ recovery_phrase: '', new_password: '', confirm: '' })
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState(null)
  const [success, setSuccess] = useState(false)

  const f = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    if (!form.recovery_phrase || !form.new_password || !form.confirm) {
      setError(__('All fields are required.', 'eleva-crm-for-photographers'))
      return
    }
    if (form.new_password !== form.confirm) {
      setError(__('Passwords do not match.', 'eleva-crm-for-photographers'))
      return
    }
    if (form.new_password.length < 10) {
      setError(__('New password must be at least 10 characters.', 'eleva-crm-for-photographers'))
      return
    }

    setLoading(true)
    try {
      await vaultRecoveryResetPasswordPhrase({
        recovery_phrase: form.recovery_phrase.trim(),
        new_password:    form.new_password,
      })
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
      setSuccess(true)
      onUnlocked?.()
    } catch (err) {
      setError(err.message || __('Recovery failed. Check your recovery phrase and try again.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  if (success) {
    return (
      <div className="space-y-4">
        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3">
          <p className="text-sm font-medium text-green-800">
            {__('Password updated successfully. Please unlock the vault with your new password.', 'eleva-crm-for-photographers')}
          </p>
        </div>
        <Button variant="primary" className="w-full" onClick={onBack}>
          {__('Go to unlock screen', 'eleva-crm-for-photographers')}
        </Button>
      </div>
    )
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <p className="text-sm text-gray-500">
        {__('Enter your recovery phrase and a new password to reset vault access.', 'eleva-crm-for-photographers')}
      </p>

      <FormField label={__('Recovery Phrase', 'eleva-crm-for-photographers')} required htmlFor="rec-phrase" error={null}>
        <textarea
          id="rec-phrase"
          autoComplete="off"
          placeholder="XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX"
          rows={2}
          className={inputCls + ' font-mono resize-none'}
          value={form.recovery_phrase}
          onChange={f('recovery_phrase')}
        />
      </FormField>

      <FormField label={__('New Password', 'eleva-crm-for-photographers')} required htmlFor="rec-newpw" error={null}>
        <input
          id="rec-newpw"
          type="password"
          autoComplete="new-password"
          className={inputCls}
          value={form.new_password}
          onChange={f('new_password')}
        />
      </FormField>

      <FormField label={__('Confirm New Password', 'eleva-crm-for-photographers')} required htmlFor="rec-confirmpw" error={null}>
        <input
          id="rec-confirmpw"
          type="password"
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
        {loading ? <Spinner size="sm" /> : __('Reset Password', 'eleva-crm-for-photographers')}
      </Button>
    </form>
  )
}

// ---------------------------------------------------------------------------
// VaultRecoveryPanel — mode picker + sub-flow renderer
// ---------------------------------------------------------------------------

const MODES = {
  PICK:          'pick',
  LOST_AUTH:     'lost_auth',
  LOST_PASSWORD: 'lost_password',
}

export default function VaultRecoveryPanel({ onBack, onUnlocked }) {
  const [mode, setMode] = useState(MODES.PICK)

  return (
    <div className="space-y-5">
      {/* Header */}
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
          {mode === MODES.PICK          && __('Account Recovery', 'eleva-crm-for-photographers')}
          {mode === MODES.LOST_AUTH     && __('Reset Authenticator', 'eleva-crm-for-photographers')}
          {mode === MODES.LOST_PASSWORD && __('Reset Password', 'eleva-crm-for-photographers')}
        </h2>
      </div>

      {/* Mode picker */}
      {mode === MODES.PICK && (
        <div className="space-y-3">
          <p className="text-sm text-gray-500">
            {__('What do you need help with?', 'eleva-crm-for-photographers')}
          </p>

          <button
            type="button"
            onClick={() => setMode(MODES.LOST_AUTH)}
            className="w-full flex items-start gap-3 rounded-lg border border-gray-200 p-4 text-left hover:border-indigo-300 hover:bg-indigo-50 transition-colors"
          >
            <KeyRound size={20} className="text-indigo-500 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-gray-800">
                {__('Lost authenticator (I know my password)', 'eleva-crm-for-photographers')}
              </p>
              <p className="text-xs text-gray-500 mt-0.5">
                {__("I can't generate a one-time code but I remember my vault password and have my recovery code.", 'eleva-crm-for-photographers')}
              </p>
            </div>
          </button>

          <button
            type="button"
            onClick={() => setMode(MODES.LOST_PASSWORD)}
            className="w-full flex items-start gap-3 rounded-lg border border-gray-200 p-4 text-left hover:border-indigo-300 hover:bg-indigo-50 transition-colors"
          >
            <ShieldAlert size={20} className="text-amber-500 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-gray-800">
                {__('Lost password (I have my recovery phrase)', 'eleva-crm-for-photographers')}
              </p>
              <p className="text-xs text-gray-500 mt-0.5">
                {__("I forgot my vault password but I saved the recovery phrase.", 'eleva-crm-for-photographers')}
              </p>
            </div>
          </button>
        </div>
      )}

      {mode === MODES.LOST_AUTH && (
        <FlowLostAuthenticator onBack={onBack} />
      )}

      {mode === MODES.LOST_PASSWORD && (
        <FlowLostPassword onBack={() => setMode(MODES.PICK)} onUnlocked={onUnlocked} />
      )}
    </div>
  )
}
