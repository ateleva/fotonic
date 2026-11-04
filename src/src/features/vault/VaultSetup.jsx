import { useState, useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { useQueryClient } from '@tanstack/react-query'
import { Lock, UserRound } from 'lucide-react'
import { vaultSetup, vaultSetupConfirm } from '../../api/vault'
import { useVault } from '../../context/VaultContext'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import PasswordInput from '../../components/PasswordInput'
import CopyButton from '../../components/CopyButton'
import Spinner from '../../components/Spinner'
import TotpEnroll from './TotpEnroll'
import { __ } from '../../utils/i18n'
import { MIN_VAULT_PASSWORD } from '../../utils/recoveryKit'
import GateScreen, { GateCard, GateNote } from '../../components/GateScreen'

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
    <form id="vault-password-form" onSubmit={handleSubmit(onSubmit)} className="space-y-4">
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
        <PasswordInput
          id="password"
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
        <PasswordInput
          id="confirm"
          autoComplete="new-password"
          className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          {...register('confirm', {
            required: __('Please confirm your password', 'eleva-crm-for-photographers'),
            validate: (val) =>
              val === watch('password') || __('Passwords do not match', 'eleva-crm-for-photographers'),
          })}
        />
      </FormField>

      <GateNote accent icon={UserRound} title={__('Save this credential in your password manager', 'eleva-crm-for-photographers')}>
        <div className="flex items-center justify-between">
          <div>
            <span className="text-xs text-gray-500">{__('Username', 'eleva-crm-for-photographers')}</span>
            <p className="text-sm font-mono font-semibold text-gray-800 select-all">crm-vault</p>
          </div>
          <CopyButton variant="link" text="crm-vault" />
        </div>
        <p className="mt-2 text-xs text-gray-500">
          {__('Using a separate username prevents your browser from confusing the vault password with your WordPress login.', 'eleva-crm-for-photographers')}
        </p>
      </GateNote>
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

export default function VaultSetup({ onBack }) {
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

  const footer = step === 1 ? (
    <>
      {onBack && (
        <Button variant="secondary" onClick={onBack}>
          {__('Back', 'eleva-crm-for-photographers')}
        </Button>
      )}
      <Button type="submit" form="vault-password-form">
        {__('Next', 'eleva-crm-for-photographers')}
      </Button>
    </>
  ) : null

  return (
    <GateScreen>
      <GateCard
        size="md"
        icon={Lock}
        title={__('Vault Setup', 'eleva-crm-for-photographers')}
        steps={{ current: step, total: TOTAL_STEPS }}
        footer={footer}
      >
        {step === 1 && <StepPassword onNext={handlePasswordNext} />}

        {step === 2 && <StepStage password={password} onNext={handleStaged} />}

        {step === 3 && (
          <TotpEnroll
            qrUri={qrUri}
            onConfirm={handleConfirm}
            submitLabel={__('Verify & Unlock', 'eleva-crm-for-photographers')}
          />
        )}
      </GateCard>
    </GateScreen>
  )
}
