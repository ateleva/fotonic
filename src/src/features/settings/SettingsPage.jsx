import { useState, useEffect, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Shield, Key, ChevronDown, ChevronUp, CheckCircle, XCircle, RefreshCw, Trash2, AlertTriangle } from 'lucide-react'
import QRCode from 'qrcode'
import { __ } from '../../utils/i18n'
import { apiFetch } from '../../api/client'
import { useVault } from '../../context/VaultContext'
import {
  vaultChangePassword,
  vaultResetTotp,
  vaultRecoveryRegenerate,
  vaultEnrollPhrase,
  vaultReset,
} from '../../api/vault'
import PageHeader from '../../components/PageHeader'
import Modal from '../../components/Modal'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import VaultSetup from '../vault/VaultSetup'
import RecoveryCodeDisplay from '../vault/RecoveryCodeDisplay'

function QrCanvas({ uri, size = 180 }) {
  const ref = useRef(null)
  useEffect(() => {
    if (ref.current && uri) {
      QRCode.toCanvas(ref.current, uri, { width: size, margin: 2 })
    }
  }, [uri, size])
  return <canvas ref={ref} />
}

function SectionCard({ title, icon: Icon, children }) {
  return (
    <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
      <div className="flex items-center gap-2 px-5 py-4 border-b border-gray-100">
        <Icon size={16} className="text-indigo-500 shrink-0" />
        <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
      </div>
      <div className="px-5 py-4">{children}</div>
    </div>
  )
}

function Accordion({ label, children }) {
  const [open, setOpen] = useState(false)
  return (
    <div className="border border-gray-200 rounded-md overflow-hidden bg-white">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex items-center justify-between w-full px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors cursor-pointer"
      >
        {label}
        {open ? <ChevronUp size={14} className="text-gray-400" /> : <ChevronDown size={14} className="text-gray-400" />}
      </button>
      {open && <div className="px-4 pb-4 pt-2 border-t border-gray-100">{children}</div>}
    </div>
  )
}

function FieldRow({ label, children, required }) {
  return (
    <div className="space-y-1">
      <label className="block text-xs font-medium text-gray-600">
        {label}{required && <span className="text-red-500 ml-0.5">*</span>}
      </label>
      {children}
    </div>
  )
}

const inputCls = 'w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'

// ---------------------------------------------------------------------------
// Vault status badge
// ---------------------------------------------------------------------------

function VaultStatusRow({ status }) {
  if (!status) {
    return <p className="text-sm text-gray-400">{__('Loading…')}</p>
  }
  return (
    <div className="flex flex-wrap gap-3 text-sm">
      <span className={[
        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium',
        status.setup ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600',
      ].join(' ')}>
        {status.setup ? <CheckCircle size={12} /> : <XCircle size={12} />}
        {status.setup ? __('Vault configured') : __('Not configured')}
      </span>
      <span className={[
        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium',
        status.unlocked ? 'bg-indigo-100 text-indigo-700' : 'bg-yellow-100 text-yellow-700',
      ].join(' ')}>
        {status.unlocked ? <CheckCircle size={12} /> : <XCircle size={12} />}
        {status.unlocked ? __('Unlocked') : __('Locked')}
      </span>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Idle warning banner (shown when idleWarning is true from VaultContext)
// ---------------------------------------------------------------------------

function IdleWarningBanner() {
  const { idleWarning, resetIdle } = useVault()
  if (!idleWarning) return null

  return (
    <div className="flex items-center justify-between rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
      <div className="flex items-center gap-2">
        <AlertTriangle size={15} className="text-amber-500 shrink-0" />
        <span>{__("You've been idle for 13 minutes. The vault will lock in 2 minutes.", 'eleva-crm-for-photographers')}</span>
      </div>
      <button
        type="button"
        onClick={resetIdle}
        className="ml-4 shrink-0 text-xs font-medium text-amber-700 underline hover:text-amber-900"
      >
        {__('Stay unlocked', 'eleva-crm-for-photographers')}
      </button>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Change password form
// ---------------------------------------------------------------------------

function ChangePasswordForm() {
  const [form, setForm] = useState({ current_password: '', new_password: '', confirm: '', otp: '' })
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)
  const [loading, setLoading] = useState(false)

  function handleChange(key) {
    return (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSuccess(false)

    if (!form.current_password || !form.new_password || !form.otp) {
      setError(__('All fields are required.'))
      return
    }
    if (form.new_password !== form.confirm) {
      setError(__('New passwords do not match.'))
      return
    }
    if (form.new_password.length < 10) {
      setError(__('New password must be at least 10 characters.'))
      return
    }

    setLoading(true)
    try {
      await vaultChangePassword({
        current_password: form.current_password,
        otp:              form.otp,
        new_password:     form.new_password,
      })
      setSuccess(true)
      setForm({ current_password: '', new_password: '', confirm: '', otp: '' })
    } catch (err) {
      setError(err.message || __('Failed to change password.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-3 pt-1">
      <p className="text-xs text-gray-500">
        {__('Vault auto-locks after 15 minutes of inactivity.', 'eleva-crm-for-photographers')}
      </p>
      <div className="grid grid-cols-2 gap-3">
        <FieldRow label={__('Current Password')} required>
          <input type="password" value={form.current_password} onChange={handleChange('current_password')} autoComplete="current-password" className={inputCls} />
        </FieldRow>
        <FieldRow label={__('Current OTP Code')} required>
          <input type="text" inputMode="numeric" maxLength={6} value={form.otp} onChange={handleChange('otp')} placeholder="000000" autoComplete="one-time-code" className={inputCls + ' font-mono tracking-widest'} />
        </FieldRow>
        <FieldRow label={__('New Password')} required>
          <input type="password" value={form.new_password} onChange={handleChange('new_password')} autoComplete="new-password" className={inputCls} />
        </FieldRow>
        <FieldRow label={__('Confirm New Password')} required>
          <input type="password" value={form.confirm} onChange={handleChange('confirm')} autoComplete="new-password" className={inputCls} />
        </FieldRow>
      </div>
      {error && <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>}
      {success && <p className="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{__('Password changed successfully.')}</p>}
      <div className="flex justify-end">
        <button type="submit" disabled={loading} className="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
          {loading ? __('Saving…') : __('Change Password')}
        </button>
      </div>
    </form>
  )
}

// ---------------------------------------------------------------------------
// Reset TOTP form
// ---------------------------------------------------------------------------

function ResetTotpForm() {
  const [form, setForm] = useState({ password: '', otp: '' })
  const [error, setError] = useState('')
  const [qrUri, setQrUri] = useState(null)

  const mutation = useMutation({
    mutationFn: (data) => vaultResetTotp(data),
    onSuccess: (data) => {
      setQrUri(data.qr_uri)
      setForm({ password: '', otp: '' })
      setError('')
    },
    onError: (err) => setError(err.message),
  })

  function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setQrUri(null)
    if (!form.password || !form.otp) {
      setError(__('Password and OTP code are required.'))
      return
    }
    mutation.mutate(form)
  }

  const f = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  return (
    <div className="space-y-3 pt-1">
      <p className="text-xs text-gray-500">{__('Use this if you lost access to your authenticator app. You will need your current vault password and OTP to confirm your identity.')}</p>
      {qrUri ? (
        <div className="space-y-3">
          <p className="text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">
            {__('New authenticator code generated. Scan the QR code with your app, then unlock the vault to confirm.')}
          </p>
          <div className="flex flex-col items-center gap-3">
            <div className="rounded border border-gray-200 p-2">
              <QrCanvas uri={qrUri} size={180} />
            </div>
            <div className="w-full rounded bg-gray-50 border border-gray-200 p-3">
              <p className="text-xs text-gray-500 mb-1">{__('Or enter this key manually:')}</p>
              <p className="text-xs font-mono font-semibold text-gray-800 break-all">
                {qrUri.match(/secret=([A-Z2-7]+)/i)?.[1] ?? ''}
              </p>
            </div>
            <button type="button" onClick={() => setQrUri(null)} className="text-xs text-gray-400 hover:text-gray-600 underline">
              {__('Reset again')}
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <FieldRow label={__('Vault Password')} required>
              <input type="password" value={form.password} onChange={f('password')} autoComplete="current-password" className={inputCls} />
            </FieldRow>
            <FieldRow label={__('Current OTP Code')} required>
              <input type="text" inputMode="numeric" maxLength={6} value={form.otp} onChange={f('otp')} placeholder="000000" className={inputCls + ' font-mono tracking-widest'} />
            </FieldRow>
          </div>
          {error && <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>}
          <div className="flex justify-end">
            <button type="submit" disabled={mutation.isPending} className="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
              {mutation.isPending ? __('Generating…') : __('Generate New Code')}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Regenerate TOTP recovery code
// ---------------------------------------------------------------------------

function RegenerateRecoveryCode() {
  const queryClient = useQueryClient()
  const [loading, setLoading]           = useState(false)
  const [error, setError]               = useState(null)
  const [recoveryCode, setRecoveryCode] = useState(null)
  const [modalOpen, setModalOpen]       = useState(false)

  async function handleGenerate() {
    setError(null)
    setLoading(true)
    try {
      const data = await vaultRecoveryRegenerate()
      setRecoveryCode(data.recovery_code)
    } catch (err) {
      setError(err.message || __('Failed to generate recovery code. Ensure the vault is unlocked.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  async function handleSaved() {
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    setModalOpen(false)
    setRecoveryCode(null)
  }

  function handleOpen() {
    setRecoveryCode(null)
    setError(null)
    setModalOpen(true)
  }

  return (
    <>
      <div className="pt-1 space-y-2">
        <p className="text-xs text-gray-500">
          {__('Generate a new TOTP recovery code. The previous code will be invalidated immediately.', 'eleva-crm-for-photographers')}
        </p>
        <button
          type="button"
          onClick={handleOpen}
          className="inline-flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
        >
          <RefreshCw size={14} />
          {__('Regenerate Recovery Code', 'eleva-crm-for-photographers')}
        </button>
      </div>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={__('Regenerate Recovery Code', 'eleva-crm-for-photographers')}
      >
        <div className="space-y-4">
          {!recoveryCode && (
            <>
              <p className="text-sm text-gray-600">
                {__('A new recovery code will be generated. Your existing recovery code will no longer work. Store the new code somewhere safe — it will only be shown once.', 'eleva-crm-for-photographers')}
              </p>
              {error && (
                <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
              )}
              <div className="flex justify-end gap-3">
                <Button variant="secondary" onClick={() => setModalOpen(false)}>
                  {__('Cancel', 'eleva-crm-for-photographers')}
                </Button>
                <Button variant="primary" onClick={handleGenerate} disabled={loading}>
                  {loading ? <Spinner size="sm" /> : __('Generate', 'eleva-crm-for-photographers')}
                </Button>
              </div>
            </>
          )}
          {recoveryCode && (
            <RecoveryCodeDisplay
              code={recoveryCode}
              onConfirm={handleSaved}
              confirmLabel={__("I've saved it — Done", 'eleva-crm-for-photographers')}
            />
          )}
        </div>
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------------------
// Recovery Phrase section (server-generated)
// ---------------------------------------------------------------------------

function RecoveryPhraseSection({ hasPhrase }) {
  const queryClient = useQueryClient()
  const { isUnlocked } = useVault()
  const [loading, setLoading]         = useState(false)
  const [error, setError]             = useState(null)
  const [newPhrase, setNewPhrase]     = useState(null)
  const [phraseSaved, setPhraseSaved] = useState(false)
  const [modalOpen, setModalOpen]     = useState(false)
  const [copied, setCopied]           = useState(false)

  async function handleGenerate() {
    setError(null)
    setLoading(true)
    try {
      const data = await vaultEnrollPhrase()
      setNewPhrase(data.recovery_phrase)
    } catch (err) {
      setError(err.message || __('Failed to generate phrase. Ensure the vault is unlocked.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  function handleCopy() {
    navigator.clipboard.writeText(newPhrase).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  function handleOpen() {
    setNewPhrase(null)
    setError(null)
    setPhraseSaved(false)
    setModalOpen(true)
  }

  async function handleDone() {
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    setModalOpen(false)
    setNewPhrase(null)
    setPhraseSaved(false)
  }

  if (!isUnlocked) {
    return (
      <div className="pt-1">
        <p className="text-xs text-gray-400">
          {__('Unlock the vault to manage the recovery phrase.', 'eleva-crm-for-photographers')}
        </p>
      </div>
    )
  }

  return (
    <>
      <div className="pt-1 space-y-2">
        <p className="text-xs text-gray-500">
          {hasPhrase
            ? __('Regenerate a new recovery phrase. The previous phrase will stop working immediately.', 'eleva-crm-for-photographers')
            : __('Set up a recovery phrase so you can reset your password if you ever forget it.', 'eleva-crm-for-photographers')}
        </p>
        <button
          type="button"
          onClick={handleOpen}
          className="inline-flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
        >
          <RefreshCw size={14} />
          {hasPhrase
            ? __('Regenerate Recovery Phrase', 'eleva-crm-for-photographers')
            : __('Set Up Recovery Phrase', 'eleva-crm-for-photographers')}
        </button>
      </div>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={hasPhrase
          ? __('Regenerate Recovery Phrase', 'eleva-crm-for-photographers')
          : __('Set Up Recovery Phrase', 'eleva-crm-for-photographers')}
      >
        <div className="space-y-4">
          {!newPhrase && (
            <>
              <p className="text-sm text-gray-600">
                {hasPhrase
                  ? __('A new recovery phrase will be generated. Your old phrase will no longer work. Store the new phrase somewhere safe offline — it will only be shown once.', 'eleva-crm-for-photographers')
                  : __('A recovery phrase lets you reset your vault password if you ever forget it. Store it somewhere safe offline — it will only be shown once.', 'eleva-crm-for-photographers')}
              </p>
              {error && (
                <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
              )}
              <div className="flex justify-end gap-3">
                <Button variant="secondary" onClick={() => setModalOpen(false)}>
                  {__('Cancel', 'eleva-crm-for-photographers')}
                </Button>
                <Button variant="primary" onClick={handleGenerate} disabled={loading}>
                  {loading ? <Spinner size="sm" /> : __('Generate', 'eleva-crm-for-photographers')}
                </Button>
              </div>
            </>
          )}
          {newPhrase && (
            <div className="space-y-4">
              <div className="rounded-md bg-amber-50 border border-amber-200 p-4">
                <p className="text-xs text-gray-500 mb-2">{__('Your Recovery Phrase', 'eleva-crm-for-photographers')}</p>
                <p className="text-sm font-mono font-semibold text-amber-900 break-all tracking-widest text-center">
                  {newPhrase}
                </p>
              </div>
              <Button variant="secondary" className="w-full" onClick={handleCopy}>
                {copied ? __('Copied!') : __('Copy to clipboard')}
              </Button>
              <label className="flex items-start gap-3 cursor-pointer">
                <input
                  type="checkbox"
                  checked={phraseSaved}
                  onChange={(e) => setPhraseSaved(e.target.checked)}
                  className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"
                />
                <span className="text-sm text-gray-700">
                  {__('I have saved this phrase offline in a secure location.', 'eleva-crm-for-photographers')}
                </span>
              </label>
              <div className="flex justify-end">
                <Button variant="primary" disabled={!phraseSaved} onClick={handleDone}>
                  {__('Done', 'eleva-crm-for-photographers')}
                </Button>
              </div>
            </div>
          )}
        </div>
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------------------
// Reset vault (destructive)
// ---------------------------------------------------------------------------

function ResetVaultAction() {
  const queryClient = useQueryClient()
  const [modalOpen, setModalOpen] = useState(false)
  const [typed, setTyped]         = useState('')
  const [loading, setLoading]     = useState(false)
  const [error, setError]         = useState(null)

  const CONFIRM_WORD = 'RESET'

  async function handleReset() {
    setError(null)
    setLoading(true)
    try {
      await vaultReset()
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
      setModalOpen(false)
    } catch (err) {
      setError(err.message || __('Reset failed.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  function handleClose() {
    setModalOpen(false)
    setTyped('')
    setError(null)
  }

  return (
    <>
      <div className="pt-1 space-y-2">
        <p className="text-xs text-gray-500">
          {__('Permanently wipe the vault configuration. All encrypted data will become inaccessible.', 'eleva-crm-for-photographers')}
        </p>
        <button
          type="button"
          onClick={() => { setTyped(''); setError(null); setModalOpen(true) }}
          className="inline-flex items-center gap-2 rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 transition-colors"
        >
          <Trash2 size={14} />
          {__('Reset Vault', 'eleva-crm-for-photographers')}
        </button>
      </div>

      <Modal
        open={modalOpen}
        onClose={handleClose}
        title={__('Reset Vault', 'eleva-crm-for-photographers')}
      >
        <div className="space-y-4">
          <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3">
            <p className="text-sm font-medium text-red-800">
              {__('This action is irreversible.', 'eleva-crm-for-photographers')}
            </p>
            <p className="text-sm text-red-700 mt-1">
              {__('The vault password, TOTP secret, and recovery credentials will be permanently deleted. All encrypted client data will become unreadable.', 'eleva-crm-for-photographers')}
            </p>
          </div>

          <div className="space-y-1">
            <label className="block text-xs font-medium text-gray-600">
              {__('Type RESET to confirm', 'eleva-crm-for-photographers')}
            </label>
            <input
              type="text"
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              placeholder={CONFIRM_WORD}
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-red-500"
              autoComplete="off"
              spellCheck={false}
            />
          </div>

          {error && (
            <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
          )}

          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={handleClose}>
              {__('Cancel', 'eleva-crm-for-photographers')}
            </Button>
            <Button
              variant="danger"
              onClick={handleReset}
              disabled={typed !== CONFIRM_WORD || loading}
            >
              {loading ? <Spinner size="sm" /> : __('Reset Vault', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        </div>
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------------------
// Main SettingsPage
// ---------------------------------------------------------------------------

const SmtpSettingsSection    = window.FotonicProComponents?.SmtpSettingsSection    ?? null
const LicenseSettingsSection = window.FotonicProComponents?.LicenseSettingsSection ?? null
const GCalSettings           = window.FotonicProComponents?.GCalSettings           ?? null

export default function SettingsPage() {
  const [showSetup, setShowSetup] = useState(false)
  const { idleWarning } = useVault()

  const { data: vaultStatus } = useQuery({
    queryKey: ['vault-status'],
    queryFn: () => apiFetch('vault/status'),
  })

  return (
    <>
    {showSetup && !vaultStatus?.setup && (
      <div className="fixed inset-0 z-[9999]">
        <VaultSetup />
      </div>
    )}

    {/* Global idle warning banner — visible on all settings sub-sections */}
    {idleWarning && (
      <div className="px-6 pt-4">
        <IdleWarningBanner />
      </div>
    )}

    <div className="p-6 space-y-6">
      <PageHeader title={__('Settings')} />

      {LicenseSettingsSection && (
        <SectionCard title={__('Eleva Pro License')} icon={Key}>
          <LicenseSettingsSection />
        </SectionCard>
      )}

      <SectionCard title={__('Vault')} icon={Shield}>
        <div className="space-y-4">
          <VaultStatusRow status={vaultStatus} />

          {vaultStatus && !vaultStatus.setup && (
            <div className="pt-1 space-y-3">
              <div className="rounded-md bg-blue-50 border border-blue-100 p-4 space-y-2">
                <p className="text-sm font-medium text-blue-900">{__('Keep your client data safe')}</p>
                <p className="text-sm text-blue-800">
                  {__('The Vault protects the personal details you store for each client: names, phone numbers, email addresses, and home addresses. Once active, all of this is locked so nobody else can read it, even if they get direct access to your website or database.')}
                </p>
                <ul className="text-sm text-blue-800 space-y-1 list-disc list-inside">
                  <li>{__('Pick a strong master password (at least 10 characters) and store it somewhere safe.')}</li>
                  <li>{__('Download any free authenticator app on your phone (Google Authenticator, Authy, or similar) and scan a QR code to add a second check each time you open the Vault.')}</li>
                  <li>{__('Save the recovery phrase and recovery code offline — these are your fallback if you lose access.')}</li>
                  <li>{__('Lock the Vault when you finish working to keep client details protected.')}</li>
                </ul>
              </div>
              <button
                type="button"
                onClick={() => setShowSetup(true)}
                className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
              >
                <Shield size={14} />
                {__('Set Up Vault')}
              </button>
            </div>
          )}

          {vaultStatus?.setup && (
            <div className="space-y-2">
              <p className="text-sm text-gray-500">
                {__('All client personal details are protected. Unlock the Vault with your password and a one-time code from your authenticator app to view or edit contact information.')}
              </p>
              <p className="text-xs text-gray-400">
                {__('Vault auto-locks after 15 minutes of inactivity.', 'eleva-crm-for-photographers')}
              </p>
              <div className="space-y-2 pt-1">
                <Accordion label={__('Change Vault Password')}>
                  <ChangePasswordForm />
                </Accordion>
                <Accordion label={__('Reset Authenticator App')}>
                  <ResetTotpForm />
                </Accordion>
                <Accordion label={__('TOTP Recovery Code')}>
                  <RegenerateRecoveryCode />
                </Accordion>
                <Accordion label={__('Recovery Phrase')}>
                  <RecoveryPhraseSection hasPhrase={vaultStatus?.has_recovery_phrase === true} />
                </Accordion>
                <Accordion label={__('Reset Vault')}>
                  <ResetVaultAction />
                </Accordion>
              </div>
            </div>
          )}
        </div>
      </SectionCard>

      {GCalSettings && (
        <SectionCard title={__('Google Calendar')} icon={Key}>
          <GCalSettings />
        </SectionCard>
      )}

      {SmtpSettingsSection && (
        <SectionCard title={__('Email Notifications')} icon={Key}>
          <SmtpSettingsSection />
        </SectionCard>
      )}

      {!SmtpSettingsSection && window.FotonicApp?.isPro && (
        <SectionCard title={__('Email Notifications')} icon={Key}>
          <p className="text-sm text-gray-400">{__('Reload the page to load email settings.')}</p>
        </SectionCard>
      )}
    </div>
    </>
  )
}
