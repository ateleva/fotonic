import { useState, useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { useQueryClient } from '@tanstack/react-query'
import QRCode from 'qrcode'
import { apiFetch } from '../../api/client'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import Spinner from '../../components/Spinner'
import RecoveryCodeDisplay from './RecoveryCodeDisplay'
import { __ } from '../../utils/i18n'

function QrCanvas({ uri, size = 200 }) {
  const ref = useRef(null)
  useEffect(() => {
    if (ref.current && uri) {
      QRCode.toCanvas(ref.current, uri, { width: size, margin: 2 })
    }
  }, [uri, size])
  return <canvas ref={ref} />
}

// --- base32 encoding (RFC 4648, no external lib) ---
const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

function base32Encode(bytes) {
  let bits = 0
  let value = 0
  let output = ''
  for (let i = 0; i < bytes.length; i++) {
    value = (value << 8) | bytes[i]
    bits += 8
    while (bits >= 5) {
      output += BASE32_CHARS[(value >>> (bits - 5)) & 31]
      bits -= 5
    }
  }
  if (bits > 0) {
    output += BASE32_CHARS[(value << (5 - bits)) & 31]
  }
  return output
}

function generateBase32Secret() {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  return base32Encode(bytes)
}

// --- Step indicator ---
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

// --- Step 1: Set password ---
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
            minLength: { value: 8, message: __('Minimum 8 characters') },
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

      <div className="pt-2">
        <Button type="submit" variant="primary" className="w-full">
          {__('Next')}
        </Button>
      </div>
    </form>
  )
}

// --- Step 2: Scan QR code (calls vault/setup, stores recovery_code) ---
function StepQR({ password, totpSecret, onNext }) {
  const [qrUri, setQrUri] = useState(null)
  const [recoveryCode, setRecoveryCode] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [called, setCalled] = useState(false)

  // Call setup on first render
  if (!called) {
    setCalled(true)
    setLoading(true)
    apiFetch('vault/setup', {
      method: 'POST',
      body: JSON.stringify({ password, totp_secret: totpSecret }),
    })
      .then((data) => {
        setQrUri(data.qr_uri)
        setRecoveryCode(data.recovery_code ?? null)
        setLoading(false)
      })
      .catch((err) => {
        setError(err.message)
        setLoading(false)
      })
  }

  if (loading) {
    return (
      <div className="flex flex-col items-center gap-4 py-8">
        <Spinner size="lg" />
        <p className="text-sm text-gray-500">{__('Setting up vault…')}</p>
      </div>
    )
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
              {totpSecret}
            </p>
          </div>
        </div>
      )}

      <Button variant="primary" className="w-full" onClick={() => onNext(recoveryCode)}>
        {__("I've scanned the code — Next")}
      </Button>
    </div>
  )
}

// --- Step 3: Confirm OTP ---
function StepOTP({ password, onSuccess }) {
  const queryClient = useQueryClient()
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    setError,
  } = useForm()

  const onSubmit = async ({ otp }) => {
    try {
      await apiFetch('vault/unlock', {
        method: 'POST',
        body: JSON.stringify({ password, otp }),
      })
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
      onSuccess()
    } catch (err) {
      setError('otp', {
        type: 'manual',
        message: __('Invalid code — check your authenticator app'),
      })
    }
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

// --- Step 4: Save recovery code (shown only when server returns one) ---
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

// --- Main VaultSetup wizard ---
// Steps: 1=password, 2=QR, 3=OTP, 4=recovery code (if provided)
export default function VaultSetup() {
  const [step, setStep] = useState(1)
  const [password, setPassword] = useState('')
  const [totpSecret] = useState(() => generateBase32Secret())
  const [recoveryCode, setRecoveryCode] = useState(null)

  const totalSteps = recoveryCode !== null ? 4 : 3

  const handlePasswordNext = (pw) => {
    setPassword(pw)
    setStep(2)
  }

  const handleQRNext = (code) => {
    setRecoveryCode(code)   // may be null if server doesn't return one
    setStep(3)
  }

  // After OTP confirm: if a recovery code was returned, show step 4; otherwise done.
  const handleOTPSuccess = () => {
    if (recoveryCode) {
      setStep(4)
    }
    // Otherwise VaultGate will re-render automatically via the invalidated query
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-md p-8">
        <div className="text-center mb-6">
          <span className="text-3xl">🔐</span>
          <h1 className="mt-2 text-xl font-bold text-gray-900">{__('Vault Setup')}</h1>
          <p className="text-xs text-gray-400 mt-1">{__('Step')} {step} {__('of')} {totalSteps}</p>
        </div>

        <StepIndicator current={step} total={totalSteps} />

        {step === 1 && <StepPassword onNext={handlePasswordNext} />}
        {step === 2 && (
          <StepQR
            password={password}
            totpSecret={totpSecret}
            onNext={handleQRNext}
          />
        )}
        {step === 3 && (
          <StepOTP password={password} onSuccess={handleOTPSuccess} />
        )}
        {step === 4 && recoveryCode && (
          <StepRecoveryCode
            recoveryCode={recoveryCode}
            onDone={() => {
              // vault is already unlocked; VaultGate renders the app
            }}
          />
        )}
      </div>
    </div>
  )
}
