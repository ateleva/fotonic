/**
 * RecoveryKitWizard
 *
 * Blocking full-screen wizard shown by VaultGate while kit_ok === false.
 * intro -> phrase -> code -> email. The secrets live only in component state.
 */
import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Mail } from 'lucide-react'
import { vaultIssueKit, vaultAckKit } from '../../api/vault'
import Button from '../../components/Button'
import CopyButton from '../../components/CopyButton'
import Spinner from '../../components/Spinner'
import { __, sprintf } from '../../utils/i18n'
import { lastGroup, matchesLastGroup, kitText } from '../../utils/recoveryKit'

const STEPS = { INTRO: 'intro', PHRASE: 'phrase', CODE: 'code', EMAIL: 'email' }

function SecretStep({ title, intro, secret, count, typed, onTyped, onNext }) {
  const ok = matchesLastGroup(secret, typed)
  const tail = lastGroup(secret)

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-semibold text-gray-800">{title}</h2>
      <p className="text-sm text-gray-500">{intro}</p>

      <div className="rounded-md bg-amber-50 border border-amber-200 p-4">
        <p className="text-sm font-mono font-semibold text-amber-900 break-all tracking-widest text-center select-all">
          {secret}
        </p>
      </div>

      <CopyButton text={secret} label={__('Copy to clipboard', 'eleva-crm-for-photographers')} />

      <div className="space-y-1">
        <label htmlFor="kit-verify" className="block text-sm font-medium text-gray-700">
          {sprintf(__('Type the last %d characters to confirm you have it', 'eleva-crm-for-photographers'), count)}
        </label>
        <input
          id="kit-verify"
          type="text"
          autoComplete="off"
          spellCheck={false}
          maxLength={tail.length + 4}
          className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-indigo-500"
          value={typed}
          onChange={(e) => onTyped(e.target.value)}
        />
      </div>

      <Button variant="primary" className="w-full" disabled={!ok} onClick={onNext}>
        {__('Next', 'eleva-crm-for-photographers')}
      </Button>
    </div>
  )
}

export default function RecoveryKitWizard() {
  const queryClient = useQueryClient()
  const [step, setStep]               = useState(STEPS.INTRO)
  const [kit, setKit]                 = useState(null) // { phrase, code, emailSent, emailHint }
  const [loading, setLoading]         = useState(false)
  const [error, setError]             = useState(null)
  const [typedPhrase, setTypedPhrase] = useState('')
  const [typedCode, setTypedCode]     = useState('')
  const [saved, setSaved]             = useState(false)

  async function handleGenerate() {
    setError(null)
    setLoading(true)
    try {
      const data = await vaultIssueKit()
      setKit({
        phrase:    data.recovery_phrase,
        code:      data.recovery_code,
        emailSent: data.email_sent === true,
        emailHint: data.email_hint ?? '',
      })
      setTypedPhrase('')
      setTypedCode('')
      setSaved(false)
      setStep(STEPS.PHRASE)
    } catch (err) {
      setError(err.message || __('Could not generate the recovery kit. Please try again.', 'eleva-crm-for-photographers'))
    } finally {
      setLoading(false)
    }
  }

  async function handleFinish() {
    setError(null)
    setLoading(true)
    try {
      await vaultAckKit(true)
      await queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    } catch (err) {
      setError(err.message || __('Could not save your confirmation. Please try again.', 'eleva-crm-for-photographers'))
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-xl shadow-md w-full max-w-md p-8">
        <div className="text-center mb-6">
          <span className="text-3xl">&#x1F510;</span>
          <h1 className="mt-2 text-xl font-bold text-gray-900">{__('Recovery kit', 'eleva-crm-for-photographers')}</h1>
        </div>

        {step === STEPS.INTRO && (
          <div className="space-y-4">
            <div className="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
              <AlertTriangle size={18} className="text-amber-500 shrink-0 mt-0.5" />
              <p className="text-sm text-amber-800">
                {__('Without a recovery kit, forgetting your vault password or losing your authenticator app means your protected data cannot be recovered.', 'eleva-crm-for-photographers')}
              </p>
            </div>
            <p className="text-sm text-gray-500">
              {__('We will generate a recovery phrase and a recovery code, show them here, and email them to the site administrator address. Either one lets you set a new password and a new authenticator without losing data. Any recovery secret you saw before stops working.', 'eleva-crm-for-photographers')}
            </p>
            {error && (
              <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
            )}
            <Button variant="primary" className="w-full" onClick={handleGenerate} disabled={loading}>
              {loading
                ? <Spinner size="sm" />
                : (error
                  ? __('Try again', 'eleva-crm-for-photographers')
                  : __('Generate and email my recovery kit', 'eleva-crm-for-photographers'))}
            </Button>
          </div>
        )}

        {step === STEPS.PHRASE && kit && (
          <SecretStep
            title={__('Your recovery phrase', 'eleva-crm-for-photographers')}
            intro={__('Store it somewhere safe outside this site, such as a password manager or a printed copy.', 'eleva-crm-for-photographers')}
            secret={kit.phrase}
            count={lastGroup(kit.phrase).length}
            typed={typedPhrase}
            onTyped={setTypedPhrase}
            onNext={() => setStep(STEPS.CODE)}
          />
        )}

        {step === STEPS.CODE && kit && (
          <SecretStep
            title={__('Your recovery code', 'eleva-crm-for-photographers')}
            intro={__('This is a second, shorter secret that does the same job. Keep it next to the phrase.', 'eleva-crm-for-photographers')}
            secret={kit.code}
            count={lastGroup(kit.code).length}
            typed={typedCode}
            onTyped={setTypedCode}
            onNext={() => setStep(STEPS.EMAIL)}
          />
        )}

        {step === STEPS.EMAIL && kit && (
          <div className="space-y-4">
            <h2 className="text-lg font-semibold text-gray-800">{__('Keep a copy outside this site', 'eleva-crm-for-photographers')}</h2>

            {kit.emailSent ? (
              <div className="flex items-start gap-3 rounded-md border border-green-200 bg-green-50 px-4 py-3">
                <Mail size={18} className="text-green-600 shrink-0 mt-0.5" />
                <p className="text-sm text-green-800">
                  {sprintf(__('Sent to %s', 'eleva-crm-for-photographers'), kit.emailHint)}
                </p>
              </div>
            ) : (
              <div className="flex items-start gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-3">
                <AlertTriangle size={18} className="text-red-500 shrink-0 mt-0.5" />
                <p className="text-sm text-red-700">
                  {__('Email could not be sent. Copy the kit now and keep it outside this site.', 'eleva-crm-for-photographers')}
                </p>
              </div>
            )}

            <p className="text-xs text-gray-500">
              {__('If you use an SMTP plugin that logs emails (WP Mail SMTP, FluentSMTP, WP Mail Logging), the kit is stored in your database. Turn logging off or delete that log entry.', 'eleva-crm-for-photographers')}
            </p>

            <CopyButton
              text={kitText({ siteName: window.location.hostname, phrase: kit.phrase, code: kit.code })}
              label={__('Copy all', 'eleva-crm-for-photographers')}
            />

            <label className="flex items-start gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={saved}
                onChange={(e) => setSaved(e.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"
              />
              <span className="text-sm text-gray-700">
                {__('I saved the phrase and the code somewhere safe outside this site', 'eleva-crm-for-photographers')}
              </span>
            </label>

            {error && (
              <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>
            )}

            <Button variant="primary" className="w-full" disabled={!saved || loading} onClick={handleFinish}>
              {loading ? <Spinner size="sm" /> : __('Finish', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
