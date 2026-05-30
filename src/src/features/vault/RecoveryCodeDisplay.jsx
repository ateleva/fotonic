/**
 * RecoveryCodeDisplay
 *
 * Shown whenever a recovery code is issued once (setup, regenerate).
 * Props:
 *   code        {string}  the recovery code
 *   onConfirm   {fn}      called when user checks "I have saved it" and confirms
 *   confirmLabel {string} button label override (optional)
 */
import { useState } from 'react'
import { Copy, Check, AlertTriangle } from 'lucide-react'
import Button from '../../components/Button'
import { __ } from '../../utils/i18n'

export default function RecoveryCodeDisplay({ code, onConfirm, confirmLabel }) {
  const [copied, setCopied] = useState(false)
  const [saved, setSaved] = useState(false)

  function handleCopy() {
    navigator.clipboard.writeText(code).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  return (
    <div className="space-y-5">
      {/* Warning banner */}
      <div className="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
        <AlertTriangle size={18} className="text-amber-500 shrink-0 mt-0.5" />
        <p className="text-sm text-amber-800">
          {__('This is the ONLY way to recover access if you lose your password or authenticator. It will not be shown again.', 'eleva-crm-for-photographers')}
        </p>
      </div>

      {/* Code display */}
      <div className="space-y-1">
        <p className="text-xs font-medium text-gray-600">{__('Your recovery code', 'eleva-crm-for-photographers')}</p>
        <div className="flex items-center gap-2">
          <code className="flex-1 rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-base font-mono font-bold text-gray-900 tracking-widest break-all select-all">
            {code}
          </code>
          <button
            type="button"
            onClick={handleCopy}
            title={__('Copy to clipboard', 'eleva-crm-for-photographers')}
            className="shrink-0 rounded-md border border-gray-200 p-2 text-gray-500 hover:bg-gray-100 transition-colors"
          >
            {copied ? <Check size={16} className="text-green-600" /> : <Copy size={16} />}
          </button>
        </div>
        <p className="text-xs text-gray-400">
          {__('Store it in a password manager or write it down and keep it secure.', 'eleva-crm-for-photographers')}
        </p>
      </div>

      {/* Confirmation checkbox */}
      <label className="flex items-start gap-2 cursor-pointer">
        <input
          type="checkbox"
          checked={saved}
          onChange={(e) => setSaved(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
        />
        <span className="text-sm text-gray-700">
          {__('I have saved my recovery code in a safe place.', 'eleva-crm-for-photographers')}
        </span>
      </label>

      <Button
        variant="primary"
        className="w-full"
        disabled={!saved}
        onClick={onConfirm}
      >
        {confirmLabel ?? __('Done', 'eleva-crm-for-photographers')}
      </Button>
    </div>
  )
}
