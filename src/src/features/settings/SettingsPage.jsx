import { useState, useEffect } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Shield, Key, ChevronDown, ChevronUp, CheckCircle, XCircle } from 'lucide-react'
import { __ } from '../../utils/i18n'
import { apiFetch } from '../../api/client'
import PageHeader from '../../components/PageHeader'
import VaultSetup from '../vault/VaultSetup'

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
    <div className="border border-gray-200 rounded-md overflow-hidden">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex items-center justify-between w-full px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
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
// Change password form
// ---------------------------------------------------------------------------

function ChangePasswordForm() {
  const [form, setForm] = useState({ current_password: '', otp: '', new_password: '', confirm: '' })
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)

  const mutation = useMutation({
    mutationFn: (data) => apiFetch('vault/change-password', { method: 'POST', body: JSON.stringify(data) }),
    onSuccess: () => {
      setSuccess(true)
      setForm({ current_password: '', otp: '', new_password: '', confirm: '' })
      setError('')
    },
    onError: (err) => setError(err.message),
  })

  function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSuccess(false)
    if (!form.current_password || !form.otp || !form.new_password) {
      setError(__('All fields are required.'))
      return
    }
    if (form.new_password !== form.confirm) {
      setError(__('New passwords do not match.'))
      return
    }
    if (form.new_password.length < 8) {
      setError(__('New password must be at least 8 characters.'))
      return
    }
    mutation.mutate({ current_password: form.current_password, otp: form.otp, new_password: form.new_password })
  }

  const f = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))

  return (
    <form onSubmit={handleSubmit} className="space-y-3 pt-1">
      <div className="grid grid-cols-2 gap-3">
        <FieldRow label={__('Current Password')} required>
          <input type="password" value={form.current_password} onChange={f('current_password')} autoComplete="current-password" className={inputCls} />
        </FieldRow>
        <FieldRow label={__('Current OTP Code')} required>
          <input type="text" inputMode="numeric" maxLength={6} value={form.otp} onChange={f('otp')} placeholder="000000" className={inputCls + ' font-mono tracking-widest'} />
        </FieldRow>
        <FieldRow label={__('New Password')} required>
          <input type="password" value={form.new_password} onChange={f('new_password')} autoComplete="new-password" className={inputCls} />
        </FieldRow>
        <FieldRow label={__('Confirm New Password')} required>
          <input type="password" value={form.confirm} onChange={f('confirm')} autoComplete="new-password" className={inputCls} />
        </FieldRow>
      </div>
      {error && <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>}
      {success && <p className="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{__('Password changed successfully.')}</p>}
      <div className="flex justify-end">
        <button type="submit" disabled={mutation.isPending} className="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
          {mutation.isPending ? __('Saving…') : __('Change Password')}
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
    mutationFn: (data) => apiFetch('vault/reset-totp', { method: 'POST', body: JSON.stringify(data) }),
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
            <img
              src={`https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(qrUri)}`}
              alt={__('TOTP QR Code')}
              width={180}
              height={180}
              className="rounded border border-gray-200 p-2"
            />
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
// Main SettingsPage
// ---------------------------------------------------------------------------

const SmtpSettingsSection    = window.FotonicProComponents?.SmtpSettingsSection    ?? null
const LicenseSettingsSection = window.FotonicProComponents?.LicenseSettingsSection ?? null

export default function SettingsPage() {
  const [showSetup, setShowSetup] = useState(false)

  const { data: vaultStatus } = useQuery({
    queryKey: ['vault-status'],
    queryFn: () => apiFetch('vault/status'),
  })

  // Close setup overlay once vault setup completes (query refetches with setup:true)
  useEffect(() => {
    if (vaultStatus?.setup === true && showSetup) {
      setShowSetup(false)
    }
  }, [vaultStatus?.setup, showSetup])

  return (
    <>
    {showSetup && (
      <div className="fixed inset-0 z-[9999]">
        <VaultSetup />
      </div>
    )}

    <div className="p-6 max-w-2xl space-y-6">
      <PageHeader title={__('Settings')} />

      {LicenseSettingsSection && (
        <SectionCard title={__('Fotonic Pro License')} icon={Key}>
          <LicenseSettingsSection />
        </SectionCard>
      )}

      <SectionCard title={__('Vault')} icon={Shield}>
        <div className="space-y-4">
          <VaultStatusRow status={vaultStatus} />

          {vaultStatus?.setup && (
            <div className="space-y-2 pt-1">
              <Accordion label={__('Change Vault Password')}>
                <ChangePasswordForm />
              </Accordion>
              <Accordion label={__('Reset Authenticator App')}>
                <ResetTotpForm />
              </Accordion>
            </div>
          )}

          {vaultStatus && !vaultStatus.setup && (
            <div className="pt-1 space-y-3">
              <p className="text-sm text-gray-500">
                {__('Enable the Vault to encrypt sensitive customer data (names, emails, addresses). The key is protected by a password + one-time code from your authenticator app.')}
              </p>
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
        </div>
      </SectionCard>

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
