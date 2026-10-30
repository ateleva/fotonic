import { forwardRef, useState } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { __ } from '../utils/i18n'

// Password field with a show/hide toggle, so a mistyped password can be checked by eye.
// Takes the same props as <input> (including a react-hook-form register() spread);
// `className` styles the input itself. The field always starts hidden and goes back to
// hidden when it unmounts (the SPA unmounts on Vault lock).
const PasswordInput = forwardRef(function PasswordInput({ className = '', ...props }, ref) {
  const [visible, setVisible] = useState(false)
  const label = visible
    ? __('Hide password', 'eleva-crm-for-photographers')
    : __('Show password', 'eleva-crm-for-photographers')

  return (
    <div className="relative">
      <input
        {...props}
        ref={ref}
        type={visible ? 'text' : 'password'}
        className={`${className} w-full pr-10 [&::-ms-reveal]:hidden`}
      />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        aria-label={label}
        aria-pressed={visible}
        title={label}
        className="absolute inset-y-0 right-0 flex items-center rounded-r-md border-0 bg-transparent px-3 text-gray-500 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
      >
        {visible ? <EyeOff size={16} aria-hidden="true" /> : <Eye size={16} aria-hidden="true" />}
      </button>
    </div>
  )
})

export default PasswordInput
