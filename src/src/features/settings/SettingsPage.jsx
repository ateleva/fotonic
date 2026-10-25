import { useState, useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Shield, Key, Archive, UploadCloud, ChevronDown, ChevronUp, CheckCircle, XCircle, RefreshCw, Trash2, AlertTriangle, Building2 } from 'lucide-react'
import { __ } from '../../utils/i18n'
import { apiFetch } from '../../api/client'
import { useVault } from '../../context/VaultContext'
import {
  vaultChangePassword,
  vaultResetTotp,
  vaultAckKit,
} from '../../api/vault'
import PageHeader from '../../components/PageHeader'
import Modal from '../../components/Modal'
import VaultSetup from '../vault/VaultSetup'
import ResetVaultFlow from '../vault/ResetVaultFlow'
import { QrCanvas } from '../vault/TotpEnroll'
import ConfirmDialog from '../../components/ConfirmDialog'
import { MIN_VAULT_PASSWORD } from '../../utils/recoveryKit'
import BackupSettingsSection from './BackupSettingsSection'

function SectionCard({ id, title, icon: Icon, children }) {
  // `id` is an anchor target for extensions (e.g. a header shortcut that scrolls to a section).
  // The scroll margin (style attribute) keeps the card clear of the pane edge when scrolled into view.
  return (
    <div className="ftnc-panel" id={id} style={id ? { scrollMarginTop: '24px' } : undefined}>
      <div className="ftnc-panel__head">
        <Icon size={16} className="text-indigo-500 shrink-0" />
        <h2 className="text-sm font-bold text-gray-800">{title}</h2>
      </div>
      <div className="ftnc-panel__body">{children}</div>
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
    return <p className="text-sm text-gray-400">{__('Loading…', 'eleva-crm-for-photographers')}</p>
  }
  return (
    <div className="flex flex-wrap gap-3 text-sm">
      <span className={[
        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium',
        status.setup ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600',
      ].join(' ')}>
        {status.setup ? <CheckCircle size={12} /> : <XCircle size={12} />}
        {status.setup ? __('Vault configured', 'eleva-crm-for-photographers') : __('Not configured', 'eleva-crm-for-photographers')}
      </span>
      <span className={[
        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium',
        status.unlocked ? 'bg-indigo-100 text-indigo-700' : 'bg-yellow-100 text-yellow-700',
      ].join(' ')}>
        {status.unlocked ? <CheckCircle size={12} /> : <XCircle size={12} />}
        {status.unlocked ? __('Unlocked', 'eleva-crm-for-photographers') : __('Locked', 'eleva-crm-for-photographers')}
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
    <div className="flex items-center justify-between rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
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
      setError(__('All fields are required.', 'eleva-crm-for-photographers'))
      return
    }
    if (form.new_password !== form.confirm) {
      setError(__('New passwords do not match.', 'eleva-crm-for-photographers'))
      return
    }
    if (form.new_password.length < MIN_VAULT_PASSWORD) {
      setError(__('New password must be at least 8 characters.', 'eleva-crm-for-photographers'))
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
      setError(err.message || __('Failed to change password.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-3 pt-1">
      <p className="text-xs text-gray-500">
        {__('Vault auto-locks after 15 minutes of inactivity.', 'eleva-crm-for-photographers')}
      </p>
      <div className="ftnc-grid-2">
        <FieldRow label={__('Current Password', 'eleva-crm-for-photographers')} required>
          <input type="password" value={form.current_password} onChange={handleChange('current_password')} autoComplete="current-password" className={inputCls} />
        </FieldRow>
        <FieldRow label={__('Current OTP Code', 'eleva-crm-for-photographers')} required>
          <input type="text" inputMode="numeric" maxLength={6} value={form.otp} onChange={handleChange('otp')} placeholder="000000" autoComplete="one-time-code" className={inputCls + ' font-mono tracking-widest'} />
        </FieldRow>
        <FieldRow label={__('New Password', 'eleva-crm-for-photographers')} required>
          <input type="password" value={form.new_password} onChange={handleChange('new_password')} autoComplete="new-password" className={inputCls} />
        </FieldRow>
        <FieldRow label={__('Confirm New Password', 'eleva-crm-for-photographers')} required>
          <input type="password" value={form.confirm} onChange={handleChange('confirm')} autoComplete="new-password" className={inputCls} />
        </FieldRow>
      </div>
      {error && <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>}
      {success && <p className="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{__('Password changed successfully.', 'eleva-crm-for-photographers')}</p>}
      <div className="flex justify-end">
        <button type="submit" disabled={loading} className="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
          {loading ? __('Saving…', 'eleva-crm-for-photographers') : __('Change Password', 'eleva-crm-for-photographers')}
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
      setError(__('Password and OTP code are required.', 'eleva-crm-for-photographers'))
      return
    }
    mutation.mutate(form)
  }

  const f = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  return (
    <div className="space-y-3 pt-1">
      <p className="text-xs text-gray-500">{__('Use this if you lost access to your authenticator app. You will need your current vault password and OTP to confirm your identity.', 'eleva-crm-for-photographers')}</p>
      {qrUri ? (
        <div className="space-y-3">
          <p className="text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">
            {__('New authenticator code generated. Scan the QR code with your app, then unlock the vault to confirm.', 'eleva-crm-for-photographers')}
          </p>
          <div className="flex flex-col items-center gap-3">
            <div className="rounded border border-gray-200 p-2">
              <QrCanvas uri={qrUri} size={180} />
            </div>
            <div className="w-full rounded bg-gray-50 border border-gray-200 p-3">
              <p className="text-xs text-gray-500 mb-1">{__('Or enter this key manually:', 'eleva-crm-for-photographers')}</p>
              <p className="text-xs font-mono font-semibold text-gray-800 break-all">
                {qrUri.match(/secret=([A-Z2-7]+)/i)?.[1] ?? ''}
              </p>
            </div>
            <button type="button" onClick={() => setQrUri(null)} className="text-xs text-gray-400 hover:text-gray-600 underline">
              {__('Reset again', 'eleva-crm-for-photographers')}
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-3">
          <div className="ftnc-grid-2">
            <FieldRow label={__('Vault Password', 'eleva-crm-for-photographers')} required>
              <input type="password" value={form.password} onChange={f('password')} autoComplete="current-password" className={inputCls} />
            </FieldRow>
            <FieldRow label={__('Current OTP Code', 'eleva-crm-for-photographers')} required>
              <input type="text" inputMode="numeric" maxLength={6} value={form.otp} onChange={f('otp')} placeholder="000000" className={inputCls + ' font-mono tracking-widest'} />
            </FieldRow>
          </div>
          {error && <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>}
          <div className="flex justify-end">
            <button type="submit" disabled={mutation.isPending} className="rounded-md bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
              {mutation.isPending ? __('Generating…', 'eleva-crm-for-photographers') : __('Generate New Code', 'eleva-crm-for-photographers')}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// New recovery kit (phrase + code, replaces the current ones)
// ---------------------------------------------------------------------------

function NewRecoveryKitAction() {
  const queryClient = useQueryClient()
  const { isUnlocked } = useVault()
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [loading, setLoading]         = useState(false)
  const [error, setError]             = useState(null)

  async function handleConfirm() {
    setError(null)
    setLoading(true)
    try {
      await vaultAckKit(false)
      // The gate now opens the recovery kit wizard.
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    } catch (err) {
      setError(err.message || __('Could not start a new recovery kit. Ensure the vault is unlocked.', 'eleva-crm-for-photographers'))
      setLoading(false)
    }
  }

  if (!isUnlocked) {
    return (
      <div className="pt-1">
        <p className="text-xs text-gray-400">
          {__('Unlock the vault to manage the recovery kit.', 'eleva-crm-for-photographers')}
        </p>
      </div>
    )
  }

  return (
    <>
      <div className="pt-1 space-y-2">
        <p className="text-xs text-gray-500">
          {__('Generate a new recovery phrase and recovery code. They replace the current ones, which stop working as soon as the new kit is issued. The kit is also emailed to the site administrator address.', 'eleva-crm-for-photographers')}
        </p>
        {error && (
          <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
        )}
        <button
          type="button"
          onClick={() => setConfirmOpen(true)}
          disabled={loading}
          className="inline-flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50"
        >
          <RefreshCw size={14} />
          {__('Generate a new recovery kit', 'eleva-crm-for-photographers')}
        </button>
      </div>

      <ConfirmDialog
        open={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={handleConfirm}
        title={__('New recovery kit', 'eleva-crm-for-photographers')}
        message={__('Your current recovery phrase and recovery code will stop working once the new kit is issued. Continue?', 'eleva-crm-for-photographers')}
        confirmLabel={__('Continue', 'eleva-crm-for-photographers')}
      />
    </>
  )
}

// ---------------------------------------------------------------------------
// Reset vault (destructive)
// ---------------------------------------------------------------------------

function ResetVaultAction() {
  const queryClient = useQueryClient()
  const [modalOpen, setModalOpen] = useState(false)

  async function handleDone() {
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    setModalOpen(false)
  }

  return (
    <>
      <div className="pt-1 space-y-2">
        <p className="text-xs text-gray-500">
          {__('Permanently wipe the vault configuration. All encrypted data will become inaccessible.', 'eleva-crm-for-photographers')}
        </p>
        <button
          type="button"
          onClick={() => setModalOpen(true)}
          className="inline-flex items-center gap-2 rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 transition-colors"
        >
          <Trash2 size={14} />
          {__('Reset Vault', 'eleva-crm-for-photographers')}
        </button>
      </div>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={__('Reset Vault', 'eleva-crm-for-photographers')}
      >
        <ResetVaultFlow allowCredentials onDone={handleDone} />
      </Modal>
    </>
  )
}

// ---------------------------------------------------------------------------
// Main SettingsPage
// ---------------------------------------------------------------------------

const LicenseSettingsSection = window.FotonicProComponents?.LicenseSettingsSection ?? null
const GCalSettings           = window.FotonicProComponents?.GCalSettings           ?? null
const ProBackupSettingsSection = window.FotonicProComponents?.BackupSettingsSection ?? null
const SettingsHeaderActions = window.FotonicProComponents?.SettingsHeaderActions ?? null
const CompanySettingsSection = window.FotonicProComponents?.CompanySettingsSection ?? null

export default function SettingsPage() {
  const [showSetup, setShowSetup] = useState(false)
  const [searchParams, setSearchParams] = useSearchParams()
  const { idleWarning } = useVault()
  // '#/settings?setup=1' (e.g. after a destructive reset) opens the setup wizard.
  const wantsSetup = searchParams.get('setup') === '1'

  const { data: vaultStatus } = useQuery({
    queryKey: ['vault-status'],
    queryFn: () => apiFetch('vault/status'),
  })

  // Drop the param once the vault exists so a later reset does not reopen the wizard unasked.
  useEffect(() => {
    if (wantsSetup && vaultStatus?.setup) setSearchParams({}, { replace: true })
  }, [wantsSetup, vaultStatus?.setup, setSearchParams])

  return (
    <>
    {(showSetup || wantsSetup) && vaultStatus && !vaultStatus.setup && (
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

    {/* PageHeader carries its own `mb-6`, so it stays OUTSIDE `.ftnc-stack`:
        inside, its margin would add to the stack's 40px section gap and the
        header→first-card distance would grow instead of tightening. Same
        structure WorkForm and CustomerForm already use. */}
    <div className="p-6 ftnc-pane">
      <PageHeader
        title={__('Settings', 'eleva-crm-for-photographers')}
        action={SettingsHeaderActions ? <SettingsHeaderActions /> : null}
      />

      <div className="ftnc-stack">
        {LicenseSettingsSection && (
          <SectionCard id="ftnc-sec-license" title={__('Eleva Pro License', 'eleva-crm-for-photographers')} icon={Key}>
            <LicenseSettingsSection />
          </SectionCard>
        )}

        <SectionCard id="ftnc-sec-vault" title={__('Vault', 'eleva-crm-for-photographers')} icon={Shield}>
          <div className="ftnc-fields">
            <VaultStatusRow status={vaultStatus} />

            {vaultStatus && !vaultStatus.setup && (
              <div className="pt-1 space-y-3">
                <div className="rounded-md bg-blue-50 border border-blue-100 p-4 space-y-2">
                  <p className="text-sm font-medium text-blue-900">{__('Keep your client data safe', 'eleva-crm-for-photographers')}</p>
                  <p className="ftnc-prose text-sm text-blue-800">
                    {__('The Vault protects the personal details you store for each client: names, phone numbers, email addresses, and home addresses. Once active, all of this is locked so nobody else can read it, even if they get direct access to your website or database.', 'eleva-crm-for-photographers')}
                  </p>
                  <ul className="text-sm text-blue-800 space-y-1 list-disc list-inside">
                    <li>{__('Pick a strong master password (at least 8 characters) and store it somewhere safe.', 'eleva-crm-for-photographers')}</li>
                    <li>{__('Download any free authenticator app on your phone (Google Authenticator, Authy, or similar) and scan a QR code to add a second check each time you open the Vault.', 'eleva-crm-for-photographers')}</li>
                    <li>{__('Right after setup you will get a recovery phrase and a recovery code, also emailed to the site administrator. Keep them safe: they are your fallback if you lose access.', 'eleva-crm-for-photographers')}</li>
                    <li>{__('Lock the Vault when you finish working to keep client details protected.', 'eleva-crm-for-photographers')}</li>
                  </ul>
                </div>
                <button
                  type="button"
                  onClick={() => setShowSetup(true)}
                  className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <Shield size={14} />
                  {__('Set Up Vault', 'eleva-crm-for-photographers')}
                </button>
              </div>
            )}

            {vaultStatus?.setup && (
              <div className="space-y-2">
                <p className="text-sm text-gray-500">
                  {__('All client personal details are protected. Unlock the Vault with your password and a one-time code from your authenticator app to view or edit contact information.', 'eleva-crm-for-photographers')}
                </p>
                <p className="text-xs text-gray-400">
                  {__('Vault auto-locks after 15 minutes of inactivity.', 'eleva-crm-for-photographers')}
                </p>
                <div className="space-y-2 pt-1">
                  <Accordion label={__('Change Vault Password', 'eleva-crm-for-photographers')}>
                    <ChangePasswordForm />
                  </Accordion>
                  <Accordion label={__('Reset Authenticator App', 'eleva-crm-for-photographers')}>
                    <ResetTotpForm />
                  </Accordion>
                  <Accordion label={__('Recovery kit', 'eleva-crm-for-photographers')}>
                    <NewRecoveryKitAction />
                  </Accordion>
                  <Accordion label={__('Reset Vault', 'eleva-crm-for-photographers')}>
                    <ResetVaultAction />
                  </Accordion>
                </div>
              </div>
            )}
          </div>
        </SectionCard>

        {/* Placed directly below Vault, and above Pro's Drive panel slot:
            the free manual download reads as the baseline, Drive as the upgrade. */}
        <SectionCard id="ftnc-sec-backups" title={__('Backups', 'eleva-crm-for-photographers')} icon={Archive}>
          <BackupSettingsSection onSetupVault={() => setShowSetup(true)} />
        </SectionCard>

        {ProBackupSettingsSection && window.FotonicApp?.features?.backup && (
          <SectionCard id="ftnc-sec-auto-backups" title={__('Automatic Backups', 'eleva-crm-for-photographers')} icon={UploadCloud}>
            <ProBackupSettingsSection />
          </SectionCard>
        )}

        {window.FotonicApp?.isPro && !window.FotonicApp?.features?.backup && (
          <SectionCard id="ftnc-sec-auto-backups" title={__('Automatic Backups', 'eleva-crm-for-photographers')} icon={UploadCloud}>
            <p className="text-sm text-gray-400">
              {__('Automatic backups to Google Drive require an active Eleva Pro license.', 'eleva-crm-for-photographers')}
            </p>
          </SectionCard>
        )}

        {GCalSettings && (
          <SectionCard id="ftnc-sec-gcal" title={__('Google Calendar', 'eleva-crm-for-photographers')} icon={Key}>
            <GCalSettings />
          </SectionCard>
        )}

        {CompanySettingsSection && window.FotonicApp?.features?.quotes && (
          <SectionCard id="ftnc-sec-company" title={__('Company details', 'eleva-crm-for-photographers')} icon={Building2}>
            <CompanySettingsSection />
          </SectionCard>
        )}
      </div>
    </div>
    </>
  )
}
