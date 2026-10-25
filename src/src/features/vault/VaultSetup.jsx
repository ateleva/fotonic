import { useState, useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { useQueryClient } from '@tanstack/react-query'
import { vaultSetup, vaultSetupConfirm } from '../../api/vault'
import { useVault } from '../../context/VaultContext'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import Spinner from '../../components/Spinner'
import TotpEnroll from './TotpEnroll'
import { __, sprintf } from '../../utils/i18n'
import { MIN_VAULT_PASSWORD } from '../../utils/recoveryKit'

// ---------------------------------------------------------------------------
// Step indicator
// ---------------------------------------------------------------------------

function StepIndicator({ current, total }) {
  return (
    <div className="flex items-center justify-center gap-2 mb-6">
      {Array.from({ length: total }, (_, i) => i + 1).map((step) => (
        <div key={step} className="flex items-center gap-2">
          <div
            className={[
              'w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold',
              step === current
                ? 'bg-indigo-600 text-white'
                : step < current
                ? 'bg-indigo-200 text-indigo-700'
                : 'bg-gray-200 text-gray-500',
            ].join(' ')}
          >
            {step}
          </div>
          {step < total && (
            <div
              className={[
                'w-8 h-0.5',
                step < current ? 'bg-indigo-400' : 'bg-gray-200',
              ].join(' ')}
            />
          )}
        </div>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Step 1: Set password
// ---------------------------------------------------------------------------

function StepPassword({ onNext }) {
  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = useForm()

  const onSubmit = ({ password }) => {
    onNext(password)
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <h2 className="text-lg font-semibold text-gray-800">{__('Set Vault Password', 'eleva-crm-for-photographers')}</h2>
      <p className="text-sm text-gray-500">
        {__('Choose a strong password to protect your encrypted data. You will need it each time you unlock the vault.', 'eleva-crm-for-photographers')}
      </p>

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
        label={__('Password', 'eleva-crm-for-photographers')}
        required
        htmlFor="password"
        error={errors.password?.message}
      >
        <input
          id="password"
          type="password"
          autoComplete="new-password"
          className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          {...register('password', {
            required: __('Password is required', 'eleva-crm-for-photographers'),
            minLength: { value: MIN_VAULT_PASSWORD, message: __('Minimum 8 characters', 'eleva-crm-for-photographers') },
          })}
        />
      </FormField>

      <FormField
        label={__('Confirm Password', 'eleva-crm-for-photographers')}
        required
        htmlFor="confirm"
        error={errors.confirm?.message}
      >
        <input
          id="confirm"
          type="password"
          autoComplete="new-password"
          className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          {...register('confirm', {
            required: __('Please confirm your password', 'eleva-crm-for-photographers'),
            validate: (val) =>
              val === watch('password') || __('Passwords do not match', 'eleva-crm-for-photographers'),
          })}
        />
      </FormField>

      <div className="rounded-md bg-blue-50 border border-blue-100 p-3 space-y-1.5">
        <p className="text-xs font-medium text-blue-700">
          {__('Save this credential in your password manager', 'eleva-crm-for-photographers')}
        </p>
        <div className="flex items-center justify-between">
          <div>
            <span className="text-xs text-gray-500">{__('Username', 'eleva-crm-for-photographers')}</span>
            <p className="text-sm font-mono font-semibold text-gray-800">crm-vault</p>
          </div>
          <button
            type="button"
            onClick={() => navigator.clipboard.writeText('crm-vault')}
            className="text-xs text-blue-500 hover:text-blue-700 underline cursor-pointer"
          >
            {__('Copy', 'eleva-crm-for-photographers')}
          </button>
        </div>
        <p className="text-xs text-gray-400">
          {__('Using a separate username prevents your browser from confusing the vault password with your WordPress login.', 'eleva-crm-for-photographers')}
        </p>
      </div>

      <div className="pt-2">
        <Button type="submit" variant="primary" className="w-full">
          {__('Next', 'eleva-crm-for-photographers')}
        </Button>
      </div>
    </form>
  )
}

// ---------------------------------------------------------------------------
// Step 2: POST vault/setup {password} -> stage the vault, get the QR
// ---------------------------------------------------------------------------

function StepStage({ password, onNext }) {
  const [error, setError]     = useState(null)
  const [attempt, setAttempt] = useState(0)
  const startedRef = useRef(-1)

  useEffect(() => {
    // One request per attempt, even if React re-runs the effect.
    if (startedRef.current === attempt) return
    startedRef.current = attempt
    ;(async () => {
      try {
        const data = await vaultSetup({ password })
        onNext(data.qr_uri)
      } catch (err) {
        setError(err.message || __('Could not start the vault setup. Please try again.', 'eleva-crm-for-photographers'))
      }
    })()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attempt])

  if (error) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-red-600">{error}</p>
        <Button
          variant="secondary"
          onClick={() => { setError(null); setAttempt((a) => a + 1) }}
        >
          {__('Try again', 'eleva-crm-for-photographers')}
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col items-center gap-4 py-8">
      <Spinner size="lg" />
      <p className="text-sm text-gray-500">{__('Setting up vault…', 'eleva-crm-for-photographers')}</p>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Main VaultSetup wizard
// Steps: 1=password, 2=stage (spinner), 3=QR + OTP confirm.
// After the confirm the wizard unmounts on purpose: VaultGate then shows the
// RecoveryKitWizard (kit_ok is false for a fresh vault).
// ---------------------------------------------------------------------------

const TOTAL_STEPS = 3

export default function VaultSetup() {
  const queryClient = useQueryClient()
  const { markUnlocked } = useVault()
  const [step, setStep]         = useState(1)
  const [password, setPassword] = useState('')
  const [qrUri, setQrUri]       = useState(null)

  const handlePasswordNext = (pw) => {
    setPassword(pw)
    setStep(2)
  }

  const handleStaged = (uri) => {
    setQrUri(uri)
    setStep(3)
  }

  const handleConfirm = async (otp) => {
    try {
      await vaultSetupConfirm({ password, otp })
    } catch (err) {
      return { error: err.message || __('Invalid code. Check your authenticator app.', 'eleva-crm-for-photographers') }
    }
    markUnlocked()
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    return {}
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-md p-8">
        <div className="text-center mb-6">
          <span className="text-3xl">&#x1F510;</span>
          <h1 className="mt-2 text-xl font-bold text-gray-900">{__('Vault Setup', 'eleva-crm-for-photographers')}</h1>
          <p className="text-xs text-gray-400 mt-1">
            {sprintf(__('Step %1$d of %2$d', 'eleva-crm-for-photographers'), step, TOTAL_STEPS)}
          </p>
        </div>

        {step > 1 && (
          <StepIndicator current={step} total={TOTAL_STEPS} />
        )}

        {step === 1 && <StepPassword onNext={handlePasswordNext} />}

        {step === 2 && <StepStage password={password} onNext={handleStaged} />}

        {step === 3 && (
          <TotpEnroll
            qrUri={qrUri}
            onConfirm={handleConfirm}
            submitLabel={__('Verify & Unlock', 'eleva-crm-for-photographers')}
          />
        )}
      </div>
    </div>
  )
}
