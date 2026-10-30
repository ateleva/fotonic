/**
 * TotpEnroll
 *
 * QR code + manual key + 6-digit confirmation, shared by the setup wizard and
 * the post-recovery "new authenticator" screen.
 *
 * Props:
 *   qrUri      {string}  otpauth:// URI
 *   onConfirm  {fn}      (otp) => Promise<{ error?: string }>
 *   submitLabel {string} optional button label
 */
import { useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import QRCode from 'qrcode'
import Button from '../../components/Button'
import CopyButton from '../../components/CopyButton'
import FormField from '../../components/FormField'
import Spinner from '../../components/Spinner'
import { __ } from '../../utils/i18n'

export function QrCanvas({ uri, size = 200 }) {
  const ref = useRef(null)
  useEffect(() => {
    if (ref.current && uri) {
      QRCode.toCanvas(ref.current, uri, { width: size, margin: 2 })
    }
  }, [uri, size])
  return <canvas ref={ref} />
}

export default function TotpEnroll({ qrUri, onConfirm, submitLabel }) {
  const secretB32 = qrUri?.match(/secret=([A-Z2-7]+)/i)?.[1] ?? ''
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    setError,
  } = useForm()

  const onSubmit = async ({ otp }) => {
    const result = await onConfirm(otp)
    if (result?.error) {
      setError('otp', { type: 'manual', message: result.error })
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <h2 className="text-lg font-semibold text-gray-800">{__('Scan QR Code', 'eleva-crm-for-photographers')}</h2>
      <p className="text-sm text-gray-500">
        {__('Open your authenticator app (Google Authenticator, Authy, etc.) and scan the QR code below, then enter the 6-digit code it shows.', 'eleva-crm-for-photographers')}
      </p>

      {qrUri && (
        <div className="flex flex-col items-center gap-3">
          <div className="rounded-md border border-gray-200 p-2">
            <QrCanvas uri={qrUri} size={200} />
          </div>
          <div className="w-full rounded-md bg-gray-50 border border-gray-200 p-3">
            <div className="mb-1 flex items-center justify-between">
              <p className="text-xs text-gray-500">{__('Manual entry key:', 'eleva-crm-for-photographers')}</p>
              <CopyButton variant="link" text={secretB32} />
            </div>
            <p className="text-sm font-mono font-semibold text-gray-800 break-all select-all">{secretB32}</p>
          </div>
        </div>
      )}

      <FormField
        label={__('One-Time Code', 'eleva-crm-for-photographers')}
        required
        htmlFor="totp-enroll-otp"
        error={errors.otp?.message}
      >
        <input
          id="totp-enroll-otp"
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

      <Button type="submit" variant="primary" className="w-full" disabled={isSubmitting}>
        {isSubmitting ? <Spinner size="sm" /> : (submitLabel ?? __('Verify code', 'eleva-crm-for-photographers'))}
      </Button>
    </form>
  )
}
