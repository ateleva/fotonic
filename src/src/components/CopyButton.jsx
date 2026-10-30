import { useEffect, useRef, useState } from 'react'
import { Copy, Check } from 'lucide-react'
import Button from './Button'
import { copyText } from '../utils/copyText'
import { __ } from '../utils/i18n'

// "Copy" button for values the user has to keep (recovery phrase and code, authenticator
// key, username). It copies through the system clipboard, so on a Mac signed in to the same
// Apple ID the value can be pasted on an iPhone (Universal Clipboard). When nothing worked
// it says so, instead of failing silently.
//   variant 'button' (default): full-width secondary button with an icon.
//   variant 'link': small underlined text link, for inline use next to a value.
export default function CopyButton({ text, label, variant = 'button', className = 'w-full' }) {
  const [state, setState] = useState('idle') // idle | copied | failed
  const timer = useRef(null)

  useEffect(() => () => clearTimeout(timer.current), [])

  async function handleCopy() {
    const ok = await copyText(text)
    setState(ok ? 'copied' : 'failed')
    clearTimeout(timer.current)
    timer.current = setTimeout(() => setState('idle'), ok ? 2000 : 6000)
  }

  const copied = state === 'copied'
  const shownLabel = copied ? __('Copied!', 'eleva-crm-for-photographers') : (label ?? __('Copy', 'eleva-crm-for-photographers'))
  const failure = (
    <p aria-live="polite" className="mt-1 text-xs text-red-600">
      {state === 'failed' ? __('Could not copy. Select the text and press Ctrl+C (Cmd+C on Mac).', 'eleva-crm-for-photographers') : ''}
    </p>
  )

  if (variant === 'link') {
    return (
      <span>
        <button
          type="button"
          onClick={handleCopy}
          className="cursor-pointer border-0 bg-transparent p-0 text-xs text-blue-500 underline hover:text-blue-700"
        >
          {shownLabel}
        </button>
        {state === 'failed' && failure}
      </span>
    )
  }

  return (
    <div className={className}>
      <Button variant="secondary" className="w-full" onClick={handleCopy}>
        {copied ? <Check size={16} className="text-green-600" /> : <Copy size={16} />}
        {shownLabel}
      </Button>
      {failure}
    </div>
  )
}
