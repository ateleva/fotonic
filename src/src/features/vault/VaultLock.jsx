import { useForm } from 'react-hook-form'
import { useQueryClient } from '@tanstack/react-query'
import { Lock } from 'lucide-react'
import { useVault } from '../../context/VaultContext'
import Button from '../../components/Button'
import FormField from '../../components/FormField'
import Spinner from '../../components/Spinner'
import { __ } from '../../utils/i18n'

export default function VaultLock() {
  const queryClient = useQueryClient()
  const { unlock } = useVault()
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    setError,
  } = useForm()

  const onSubmit = async ({ password, otp }) => {
    try {
      // unlock() calls vault/unlock + vault/status + derives browser CryptoKey
      await unlock(password, otp)
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
      // VaultGate re-renders with unlocked=true → <RouterProvider>
    } catch (err) {
      setError('root', {
        type: 'manual',
        message: err.message || __('Invalid password or code'),
      })
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-md p-8">
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-14 h-14 rounded-full bg-indigo-50 mb-3">
            <Lock size={28} className="text-indigo-600" />
          </div>
          <h1 className="text-xl font-bold text-gray-900">{__('Vault Locked')}</h1>
          <p className="text-sm text-gray-500 mt-1">
            {__('Enter your vault password and authenticator code to access your data.')}
          </p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <FormField
            label={__('Vault Password')}
            required
            htmlFor="password"
            error={errors.password?.message}
          >
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              {...register('password', { required: __('Password is required') })}
            />
          </FormField>

          <FormField
            label={__('Authenticator Code')}
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

          {errors.root && (
            <p className="text-sm text-red-600 text-center">{errors.root.message}</p>
          )}

          <Button
            type="submit"
            variant="primary"
            className="w-full mt-2"
            disabled={isSubmitting}
          >
            {isSubmitting ? <Spinner size="sm" /> : __('Unlock Vault')}
          </Button>
        </form>
      </div>
    </div>
  )
}
