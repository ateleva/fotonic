/**
 * ResetVaultFlow
 *
 * Destructive last-resort reset. Two ways to authorise it:
 *   - email code (works locked or unlocked): "Email me a reset code" -> code + RESET
 *   - password + authenticator code (only while unlocked, `allowCredentials`)
 *
 * Props:
 *   allowCredentials {bool}  offer the password + OTP path (Settings, vault unlocked)
 *   onDone           {fn}    called after a successful reset
 */
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchVaultStatus, vaultResetRequestCode, vaultReset } from '../../api/vault'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import PasswordInput from '../../components/PasswordInput'
import { __, sprintf } from '../../utils/i18n'

const CONFIRM_WORD = 'RESET'

const inputCls =
  'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500'

function ErrorBox({ children }) {
  return <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{children}</p>
}

function TypeResetField({ value, onChange }) {
  return (
    <div className="space-y-1">
      <label htmlFor="reset-confirm" className="block text-xs font-medium text-gray-600">
        {__('Type RESET to confirm', 'eleva-crm-for-photographers')}
      </label>
      <input
        id="reset-confirm"
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={CONFIRM_WORD}
        className={inputCls + ' font-mono'}
        autoComplete="off"
        spellCheck={false}
      />
    </div>
  )
}

export default function ResetVaultFlow({ allowCredentials = false, onDone }) {
  const { data: status } = useQuery({
    queryKey: ['vault-status'],
    queryFn: fetchVaultStatus,
  })

  const [method, setMethod]     = useState(allowCredentials ? 'credentials' : 'code')
  const [requested, setRequested] = useState(null) // { sent, emailHint, counts }
  const [code, setCode]         = useState('')
  const [password, setPassword] = useState('')
  const [otp, setOtp]           = useState('')
  const [typed, setTyped]       = useState('')
  const [loading, setLoading]   = useState(false)
  const [error, setError]       = useState(null)

  async function handleRequest() {
    setError(null)
    setLoading(true)
    try {
      const data = await vaultResetRequestCode()
      setRequested({
        sent:      data.sent === true,
        emailHint: data.email_hint || status?.email_hint || '',
        counts:    data.counts ?? { customers: 0, works: 0 },
      })
    } catch (err) {
      setError(err.message || __('Could not send the reset code. Please try again.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  async function handleReset() {
    setError(null)
    setLoading(true)
    try {
      if (method === 'credentials') {
        await vaultReset({ confirm: typed, password, otp })
      } else {
        await vaultReset({ confirm: typed, code: code.trim() })
      }
      await onDone?.()
    } catch (err) {
      setError(err.message || __('Reset failed.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  function switchMethod(next) {
    setMethod(next)
    setError(null)
    setTyped('')
  }

  const confirmed = typed === CONFIRM_WORD

  // ---- Password + authenticator path ----
  if (method === 'credentials') {
    return (
      <div className="space-y-4">
        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3">
          <p className="text-sm font-medium text-red-800">
            {__('This action is irreversible.', 'eleva-crm-for-photographers')}
          </p>
          <p className="text-sm text-red-700 mt-1">
            {__('The vault password, TOTP secret, and recovery credentials will be permanently deleted. All encrypted client data will become unreadable, and so will the quote PDFs already generated.', 'eleva-crm-for-photographers')}
          </p>
        </div>

        <div className="space-y-1">
          <label htmlFor="reset-password" className="block text-xs font-medium text-gray-600">
            {__('Vault Password', 'eleva-crm-for-photographers')}
          </label>
          <PasswordInput
            id="reset-password"
            autoComplete="current-password"
            className={inputCls}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </div>

        <div className="space-y-1">
          <label htmlFor="reset-otp" className="block text-xs font-medium text-gray-600">
            {__('Current OTP Code', 'eleva-crm-for-photographers')}
          </label>
          <input
            id="reset-otp"
            type="text"
            inputMode="numeric"
            maxLength={6}
            autoComplete="one-time-code"
            placeholder="000000"
            className={inputCls + ' font-mono tracking-widest'}
            value={otp}
            onChange={(e) => setOtp(e.target.value)}
          />
        </div>

        <TypeResetField value={typed} onChange={setTyped} />

        {error && <ErrorBox>{error}</ErrorBox>}

        <Button
          variant="danger"
          className="w-full"
          onClick={handleReset}
          disabled={!confirmed || !password || !/^\d{6}$/.test(otp) || loading}
        >
          {loading ? <Spinner size="sm" /> : __('Reset Vault', 'eleva-crm-for-photographers')}
        </Button>

        <div className="text-center">
          <button
            type="button"
            onClick={() => switchMethod('code')}
            className="text-xs text-indigo-500 hover:text-indigo-700 underline cursor-pointer"
          >
            {__('Use an email code instead', 'eleva-crm-for-photographers')}
          </button>
        </div>
      </div>
    )
  }

  // ---- Email code path ----
  const hint = requested?.emailHint || status?.email_hint || ''

  return (
    <div className="space-y-4">
      {(!requested || !requested.sent) && (
        <>
          <p className="text-sm text-gray-600">
            {hint
              ? sprintf(__('We will email a reset code to %s.', 'eleva-crm-for-photographers'), hint)
              : __('We will email a reset code to the site administrator address.', 'eleva-crm-for-photographers')}
          </p>
          {requested && !requested.sent && (
            <ErrorBox>{__('The email could not be sent. Check that your site can send email, then try again.', 'eleva-crm-for-photographers')}</ErrorBox>
          )}
          {error && <ErrorBox>{error}</ErrorBox>}
          <Button variant="primary" className="w-full" onClick={handleRequest} disabled={loading}>
            {loading ? <Spinner size="sm" /> : __('Email me a reset code', 'eleva-crm-for-photographers')}
          </Button>
        </>
      )}

      {requested?.sent && (
        <>
          <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3">
            <p className="text-sm text-red-800">
              {sprintf(
                __('Customers: %1$d, works: %2$d. Their encrypted personal data becomes unreadable forever.', 'eleva-crm-for-photographers'),
                requested.counts.customers,
                requested.counts.works
              )}
            </p>
          </div>

          <p className="text-xs text-gray-500">
            {sprintf(__('Code sent to %s. It expires in 15 minutes.', 'eleva-crm-for-photographers'), hint)}
          </p>

          <div className="space-y-1">
            <label htmlFor="reset-code" className="block text-xs font-medium text-gray-600">
              {__('Reset code', 'eleva-crm-for-photographers')}
            </label>
            <input
              id="reset-code"
              type="text"
              inputMode="numeric"
              maxLength={8}
              autoComplete="one-time-code"
              placeholder="00000000"
              className={inputCls + ' font-mono tracking-widest'}
              value={code}
              onChange={(e) => setCode(e.target.value)}
            />
          </div>

          <TypeResetField value={typed} onChange={setTyped} />

          {error && <ErrorBox>{error}</ErrorBox>}

          <Button
            variant="danger"
            className="w-full"
            onClick={handleReset}
            disabled={!confirmed || !/^\d{8}$/.test(code.trim()) || loading}
          >
            {loading ? <Spinner size="sm" /> : __('Reset Vault', 'eleva-crm-for-photographers')}
          </Button>
        </>
      )}

      {allowCredentials && (
        <div className="text-center">
          <button
            type="button"
            onClick={() => switchMethod('credentials')}
            className="text-xs text-indigo-500 hover:text-indigo-700 underline cursor-pointer"
          >
            {__('Use password and authenticator code instead', 'eleva-crm-for-photographers')}
          </button>
        </div>
      )}
    </div>
  )
}
