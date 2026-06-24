import { useState, useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { useQueryClient } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { vaultSetup } from '../../api/vault'
import { useVault } from '../../context/VaultContext'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import Spinner from '../../components/Spinner'
import RecoveryCodeDisplay from './RecoveryCodeDisplay'
import { __ } from '../../utils/i18n'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function QrCanvas({ uri, size = 200 }) {
  const ref = useRef(null)
  useEffect(() => {
    if (ref.current && uri) {
      QRCode.toCanvas(ref.current, uri, { width: size, margin: 2 })
    }
  }, [uri, size])
  return <canvas ref={ref} />
}

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
// Step 1: Set password (min 10 chars)
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
      <h2 className="text-lg font-semibold text-gray-800">{__('Set Vault Password')}</h2>
      <p className="text-sm text-gray-500">
        {__('Choose a strong password to protect your encrypted data. You will need it each time you unlock the vault.')}
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
        label={__('Password')}
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
            required: __('Password is required'),
            minLength: { value: 10, message: __('Minimum 10 characters') },
          })}
        />
      </FormField>

      <FormField
        label={__('Confirm Password')}
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
            required: __('Please confirm your password'),
            validate: (val) =>
              val === watch('password') || __('Passwords do not match'),
          })}
        />
      </FormField>

      <div className="rounded-md bg-blue-50 border border-blue-100 p-3 space-y-1.5">
        <p className="text-xs font-medium text-blue-700">
          {__('Save this credential in your password manager')}
        </p>
        <div className="flex items-center justify-between">
          <div>
            <span className="text-xs text-gray-500">{__('Username')}</span>
            <p className="text-sm font-mono font-semibold text-gray-800">crm-vault</p>
          </div>
          <button
            type="button"
            onClick={() => navigator.clipboard.writeText('crm-vault')}
            className="text-xs text-blue-500 hover:text-blue-700 underline cursor-pointer"
          >
            {__('Copy')}
          </button>
        </div>
        <p className="text-xs text-gray-400">
          {__('Using a separate username prevents your browser from confusing the vault password with your WordPress login.')}
        </p>
      </div>

      <div className="pt-2">
        <Button type="submit" variant="primary" className="w-full">
          {__('Next')}
        </Button>
      </div>
    </form>
  )
}

// ---------------------------------------------------------------------------
// Step 2: POST vault/setup {password} → get QR + recovery data from server
// ---------------------------------------------------------------------------

function StepSetupCall({ password, onNext }) {
  const [error, setError]   = useState(null)
  const [called, setCalled] = useState(false)

  // Fire once on first render
  if (!called) {
    setCalled(true)
    ;(async () => {
      try {
        const data = await vaultSetup({ password })
        // data: { setup: true, qr_uri, recovery_code, recovery_phrase }
        onNext({
          qrUri:          data.qr_uri,
          recoveryCode:   data.recovery_code ?? null,
          recoveryPhrase: data.recovery_phrase ?? null,
        })
      } catch (err) {
        setError(err.message)
      }
    })()
  }

  if (error) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-red-600">{error}</p>
        <Button variant="secondary" onClick={() => window.location.reload()}>
          {__('Try again')}
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col items-center gap-4 py-8">
      <Spinner size="lg" />
      <p className="text-sm text-gray-500">{__('Setting up vault…')}</p>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Step 3: Scan QR code
// ---------------------------------------------------------------------------

function StepScanQR({ qrUri, onNext }) {
  const secretB32 = qrUri?.match(/secret=([A-Z2-7]+)/i)?.[1] ?? ''

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-semibold text-gray-800">{__('Scan QR Code')}</h2>
      <p className="text-sm text-gray-500">
        {__('Open your authenticator app (Google Authenticator, Authy, etc.) and scan the QR code below.')}
      </p>

      {qrUri && (
        <div className="flex flex-col items-center gap-3">
          <div className="rounded-md border border-gray-200 p-2">
            <QrCanvas uri={qrUri} size={200} />
          </div>
          <div className="w-full rounded-md bg-gray-50 border border-gray-200 p-3">
            <p className="text-xs text-gray-500 mb-1">{__('Manual entry key:')}</p>
            <p className="text-sm font-mono font-semibold text-gray-800 break-all">
              {secretB32}
            </p>
          </div>
        </div>
      )}

      <Button variant="primary" className="w-full" onClick={onNext}>
        {__("I've scanned the code — Next")}
      </Button>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Step 4: Confirm OTP (unlocks vault with password)
// ---------------------------------------------------------------------------

function StepOTP({ password, onSuccess }) {
  const queryClient = useQueryClient()
  const { unlock } = useVault()
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    setError,
  } = useForm()

  const onSubmit = async ({ otp }) => {
    const result = await unlock(password, otp)
    if (result?.error) {
      setError('otp', {
        type: 'manual',
        message: result.message || __('Invalid code or password mismatch — check your authenticator app'),
      })
      return
    }
    await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    onSuccess()
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <h2 className="text-lg font-semibold text-gray-800">{__('Confirm Authenticator Code')}</h2>
      <p className="text-sm text-gray-500">
        {__('Enter the 6-digit code shown in your authenticator app to complete setup.')}
      </p>

      <FormField
        label={__('One-Time Code')}
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
            required: __('Code is required'),
            pattern: { value: /^\d{6}$/, message: __('Enter a 6-digit code') },
          })}
        />
      </FormField>

      <Button
        type="submit"
        variant="primary"
        className="w-full"
        disabled={isSubmitting}
      >
        {isSubmitting ? <Spinner size="sm" /> : __('Verify & Unlock')}
      </Button>
    </form>
  )
}

// ---------------------------------------------------------------------------
// Step 5: Save recovery phrase (if server returned one)
// ---------------------------------------------------------------------------

function StepRecoveryPhrase({ recoveryPhrase, onNext }) {
  const [saved, setSaved] = useState(false)
  const [copied, setCopied] = useState(false)

  const handleCopy = () => {
    navigator.clipboard.writeText(recoveryPhrase).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-semibold text-gray-800">{__('Save Your Recovery Phrase')}</h2>
      <p className="text-sm text-gray-500">
        {__('This phrase lets you recover access if you forget your password. Store it somewhere safe offline — it will never be shown again.')}
      </p>

      <div className="rounded-md bg-amber-50 border border-amber-200 p-4">
        <p className="text-sm font-mono font-semibold text-amber-900 break-all tracking-widest text-center">
          {recoveryPhrase}
        </p>
      </div>

      <Button variant="secondary" className="w-full" onClick={handleCopy}>
        {copied ? __('Copied!') : __('Copy to clipboard')}
      </Button>

      <label className="flex items-start gap-3 cursor-pointer">
        <input
          type="checkbox"
          checked={saved}
          onChange={(e) => setSaved(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"
        />
        <span className="text-sm text-gray-700">
          {__('I have saved this phrase offline in a secure location.')}
        </span>
      </label>

      <Button
        variant="primary"
        className="w-full"
        disabled={!saved}
        onClick={onNext}
      >
        {__('Next')}
      </Button>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Step 6: Save recovery code (if server returned one)
// ---------------------------------------------------------------------------

function StepRecoveryCode({ recoveryCode, onDone }) {
  return (
    <div className="space-y-4">
      <h2 className="text-lg font-semibold text-gray-800">{__('Save Your Recovery Code')}</h2>
      <RecoveryCodeDisplay
        code={recoveryCode}
        onConfirm={onDone}
        confirmLabel={__('Finish Setup')}
      />
    </div>
  )
}

// ---------------------------------------------------------------------------
// Main VaultSetup wizard
// Steps: 1=password, 2=server setup call (spinner), 3=QR scan, 4=OTP confirm,
//        5=recovery phrase (if returned), 6=recovery code (if returned)
// ---------------------------------------------------------------------------

export default function VaultSetup() {
  const [step, setStep]         = useState(1)
  const [password, setPassword] = useState('')
  const [setupData, setSetupData] = useState(null)  // { qrUri, recoveryCode, recoveryPhrase }

  // Determine total steps dynamically once we have setup data
  let totalSteps = 4
  if (setupData?.recoveryPhrase) totalSteps++
  if (setupData?.recoveryCode)   totalSteps++

  // Step indices (dynamic based on what server returned)
  const phraseStep = setupData?.recoveryPhrase ? 5 : null
  const codeStep   = phraseStep
    ? (setupData?.recoveryCode ? 6 : null)
    : (setupData?.recoveryCode ? 5 : null)

  const handlePasswordNext = (pw) => {
    setPassword(pw)
    setStep(2)
  }

  const handleSetupDone = (data) => {
    setSetupData(data)
    setStep(3)
  }

  const afterOtp = () => {
    if (setupData?.recoveryPhrase) {
      setStep(5)
    } else if (setupData?.recoveryCode) {
      setStep(phraseStep ?? codeStep)
    }
    // else: vault is already unlocked; VaultGate re-renders via query invalidation
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-md p-8">
        <div className="text-center mb-6">
          <span className="text-3xl">&#x1F510;</span>
          <h1 className="mt-2 text-xl font-bold text-gray-900">{__('Vault Setup')}</h1>
          <p className="text-xs text-gray-400 mt-1">{__('Step')} {step} {__('of')} {totalSteps}</p>
        </div>

        {step > 1 && step <= totalSteps && (
          <StepIndicator current={step} total={totalSteps} />
        )}

        {step === 1 && (
          <StepPassword onNext={handlePasswordNext} />
        )}

        {step === 2 && (
          <StepSetupCall password={password} onNext={handleSetupDone} />
        )}

        {step === 3 && setupData && (
          <StepScanQR
            qrUri={setupData.qrUri}
            onNext={() => setStep(4)}
          />
        )}

        {step === 4 && (
          <StepOTP
            password={password}
            onSuccess={afterOtp}
          />
        )}

        {step === 5 && setupData?.recoveryPhrase && (
          <StepRecoveryPhrase
            recoveryPhrase={setupData.recoveryPhrase}
            onNext={() => {
              if (setupData.recoveryCode) {
                setStep(6)
              }
              // If no recovery code, VaultGate re-renders via invalidated query
            }}
          />
        )}

        {step === 5 && !setupData?.recoveryPhrase && setupData?.recoveryCode && (
          <StepRecoveryCode
            recoveryCode={setupData.recoveryCode}
            onDone={() => { /* vault is already unlocked; VaultGate renders the app */ }}
          />
        )}

        {step === 6 && setupData?.recoveryCode && (
          <StepRecoveryCode
            recoveryCode={setupData.recoveryCode}
            onDone={() => { /* vault is already unlocked; VaultGate renders the app */ }}
          />
        )}
      </div>
    </div>
  )
}
