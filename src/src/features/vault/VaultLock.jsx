import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useQueryClient } from '@tanstack/react-query'
import { Lock, Fingerprint } from 'lucide-react'
import { useVault } from '../../context/VaultContext'
import { fetchVaultStatus } from '../../api/vault'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import PasswordInput from '../../components/PasswordInput'
import Spinner from '../../components/Spinner'
import VaultRecoveryPanel from './VaultRecoveryPanel'
import { useFingerprintUnlock } from './useFingerprintUnlock'
import { __ } from '../../utils/i18n'

/**
 * VaultLock — shown only when silentReopen() returned false.
 * Full unlock form: password + OTP.
 */
export default function VaultLock() {
  const queryClient = useQueryClient()
  const { unlock, markUnlocked } = useVault()
  const [showRecovery, setShowRecovery] = useState(false)

  // The browser tab says so while the Vault is locked, so a tab that was left open can be told from the others.
  // The lock sign comes first: a narrow tab shows only the first letters. Once the Vault is open again the app
  // names the tab after the page the person is on.
  useEffect(() => {
    const original = document.title
    document.title = `🔒 ${__('Vault locked', 'eleva-crm-for-photographers')} · Eleva CRM`
    return () => { document.title = original }
  }, [])

  // After the server opened the Vault with the fingerprint, the gate re-reads the status like after a password unlock.
  const fingerprint = useFingerprintUnlock(async () => {
    markUnlocked()
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
  })

  // Opened elsewhere in the meantime (another tab of this browser unlocked): the status is cached for 30 seconds,
  // so ask the server when the tab is looked at again and every 15 seconds while it is on screen.
  useEffect(() => {
    let busy = false
    const check = async () => {
      if (busy || document.visibilityState !== 'visible') return
      busy = true
      try {
        const data = await fetchVaultStatus()
        if (data?.unlocked === true) {
          queryClient.setQueryData(['vault-status'], data)
          markUnlocked()
        }
      } catch { /* keep the lock screen */ } finally { busy = false }
    }
    const timer = setInterval(check, 15000)
    window.addEventListener('focus', check)
    document.addEventListener('visibilitychange', check)
    return () => {
      clearInterval(timer)
      window.removeEventListener('focus', check)
      document.removeEventListener('visibilitychange', check)
    }
  }, [markUnlocked, queryClient])

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    setError,
  } = useForm()

  const onSubmit = async ({ password, otp }) => {
    const result = await unlock(password, otp)
    if (result?.error) {
      setError('root', {
        type: 'manual',
        message: result.message || __('Incorrect password or code. Please try again.', 'eleva-crm-for-photographers'),
      })
      return
    }
    // One-time invite to turn on the fingerprint is shown after a password unlock (see FingerprintInvite).
    try { sessionStorage.setItem('ftnc_fp_invite', '1') } catch { /* private mode: no invite */ }
    // Vault is now unlocked in memory — invalidate status so VaultGate re-renders
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
  }

  const handleRecoveryUnlocked = async (opts) => {
    // After a destructive reset the vault no longer exists: open the setup wizard.
    if (opts?.reset) window.location.hash = '#/settings?setup=1'
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-md p-8">

        {showRecovery ? (
          <VaultRecoveryPanel
            onBack={() => setShowRecovery(false)}
            onUnlocked={handleRecoveryUnlocked}
          />
        ) : (
          <>
            <div className="text-center mb-8">
              <div className="inline-flex items-center justify-center w-14 h-14 rounded-full bg-indigo-50 mb-3">
                <Lock size={28} className="text-indigo-600" />
              </div>
              <h1 className="text-xl font-bold text-gray-900">{__('Vault Locked', 'eleva-crm-for-photographers')}</h1>
              <p className="text-sm text-gray-500 mt-1">
                {__('Enter your vault password and authenticator code to access your data.', 'eleva-crm-for-photographers')}
              </p>
            </div>

            {fingerprint.available && (
              <div className="mb-5 space-y-2">
                <Button
                  type="button"
                  variant="primary"
                  className="w-full"
                  onClick={fingerprint.start}
                  disabled={fingerprint.busy}
                >
                  {fingerprint.busy
                    ? <Spinner size="sm" />
                    : <><Fingerprint size={16} className="mr-2 inline" />{__('Unlock with fingerprint', 'eleva-crm-for-photographers')}</>}
                </Button>
                {fingerprint.error && <p className="text-sm text-red-600 text-center">{fingerprint.error}</p>}
                <p className="text-xs text-gray-400 text-center">{__('Or use your password and code', 'eleva-crm-for-photographers')}</p>
              </div>
            )}

            <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
              {/* Must be inside <form> — browser only links username hint to password fields in the same form */}
              <input
                type="text"
                name="username"
                autoComplete="username"
                value="crm-vault"
                readOnly
                tabIndex={-1}
                aria-hidden="true"
                style={{ position: 'absolute', opacity: 0, width: '1px', height: '1px', pointerEvents: 'none' }}
              />
              <FormField
                label={__('Vault Password', 'eleva-crm-for-photographers')}
                required
                htmlFor="password"
                error={errors.password?.message}
              >
                <PasswordInput
                  id="password"
                  autoComplete="current-password"
                  className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  {...register('password', { required: __('Password is required', 'eleva-crm-for-photographers') })}
                />
              </FormField>

              <FormField
                label={__('Authenticator Code', 'eleva-crm-for-photographers')}
                required
                htmlFor="otp"
                error={errors.otp?.message}
              >
                <input
                  id="otp"
                  type="text"
                  inputMode="numeric"
                  maxLength={6}
                  autoComplete="one-time-code"
                  placeholder="000000"
                  className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-center tracking-widest font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  {...register('otp', {
                    required: __('Code is required', 'eleva-crm-for-photographers'),
                    pattern: { value: /^\d{6}$/, message: __('Enter a 6-digit code', 'eleva-crm-for-photographers') },
                  })}
                />
              </FormField>

              {errors.root && (
                <p className="text-sm text-red-600 text-center">{errors.root.message}</p>
              )}

              <Button
                type="submit"
                variant="primary"
                className="w-full mt-2"
                disabled={isSubmitting}
              >
                {isSubmitting ? <Spinner size="sm" /> : __('Unlock Vault', 'eleva-crm-for-photographers')}
              </Button>
            </form>

            <div className="mt-5 text-center">
              <button
                type="button"
                onClick={() => setShowRecovery(true)}
                className="text-xs text-indigo-500 hover:text-indigo-700 underline cursor-pointer"
              >
                {__('Lost your password or authenticator?', 'eleva-crm-for-photographers')}
              </button>
            </div>
          </>
        )}

      </div>
    </div>
  )
}
