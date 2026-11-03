import { useId, useRef, useState } from 'react'
import { ChevronDown, X } from 'lucide-react'
import { __ } from '../utils/i18n'

// Letters without accents and in lower case, so "Elisa" finds "élisa".
const fold = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

/**
 * A select you can search by typing. Replaces a long <select> where the person could not look a record up.
 *
 *  options     [{ value, label, disabled? }]; `value` is compared as a string.
 *  onSearch    given: the parent filters (for example asks the server) and `options` are already the results.
 *              not given: the options are filtered here, by what is typed.
 *  selectedLabel  text to show for the chosen value when it is not (or not yet) among the options.
 *  footer      small text under the list (for example "Showing 20 of 134, type to narrow down").
 *  allowClear  shows an X that empties the field.
 * Keyboard: arrows move, Enter chooses, Escape closes. The list is drawn with position: fixed so a table with its
 * own scrolling never cuts it.
 */
export default function SearchSelect({
  id, value, onChange, options = [], onSearch, selectedLabel, placeholder,
  allowClear = false, disabled = false, invalid = false, loading = false, footer = null, emptyText,
  className = '',
}) {
  const uid = useId()
  const listId = `${uid}-list`
  const inputRef = useRef(null)
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const [box, setBox] = useState(null)

  const current = String(value ?? '')
  const chosen = options.find((o) => String(o.value) === current)
  const shownLabel = chosen?.label ?? selectedLabel ?? ''

  const visible = onSearch || !query.trim()
    ? options
    : options.filter((o) => fold(o.label).includes(fold(query.trim())))

  function measure() {
    const r = inputRef.current?.getBoundingClientRect()
    if (!r) return
    const below = window.innerHeight - r.bottom - 8
    const above = r.top - 8
    const flip = below < 160 && above > below
    setBox({ left: r.left, width: r.width, top: flip ? undefined : r.bottom + 4, bottom: flip ? window.innerHeight - r.top + 4 : undefined, maxHeight: Math.max(120, Math.min(260, flip ? above : below)) })
  }

  function openList() {
    if (disabled) return
    measure()
    setQuery('')
    onSearch?.('')
    const idx = options.findIndex((o) => String(o.value) === current)
    setActive(idx >= 0 ? idx : 0)
    setOpen(true)
  }

  function closeList() {
    setOpen(false)
    setQuery('')
    onSearch?.('')
  }

  function choose(opt) {
    if (!opt || opt.disabled) return
    onChange(String(opt.value))
    closeList()
  }

  function onKeyDown(e) {
    if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) {
      e.preventDefault()
      openList()
      return
    }
    if (!open) return
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, Math.max(visible.length - 1, 0))) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)) }
    else if (e.key === 'Enter') { e.preventDefault(); choose(visible[active]) }
    else if (e.key === 'Escape') { e.preventDefault(); closeList() }
    else if (e.key === 'Tab') closeList()
  }

  function onType(e) {
    setQuery(e.target.value)
    setActive(0)
    onSearch?.(e.target.value)
    if (!open) openList()
  }

  return (
    <div className={`relative ${className}`}>
      <input
        ref={inputRef}
        id={id}
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={open && visible[active] ? `${uid}-opt-${active}` : undefined}
        aria-invalid={invalid || undefined}
        autoComplete="off"
        disabled={disabled}
        placeholder={open ? __('Type to search…', 'eleva-crm-for-photographers') : placeholder}
        value={open ? query : shownLabel}
        onChange={onType}
        onFocus={openList}
        onClick={() => { if (!open) openList() }}
        onBlur={closeList}
        onKeyDown={onKeyDown}
        className={[
          'w-full rounded-md border px-3 py-1.5 text-sm focus:outline-none focus:ring-2 disabled:opacity-50',
          allowClear && current ? 'pr-14' : 'pr-8',
          invalid ? 'border-red-500 ring-1 ring-red-500 focus:ring-red-500' : 'border-gray-300 focus:ring-indigo-500',
        ].join(' ')}
      />
      <span className="pointer-events-none absolute inset-y-0 right-2 flex items-center text-gray-400"><ChevronDown size={14} /></span>
      {allowClear && current && !disabled && (
        <button
          type="button"
          aria-label={__('Clear', 'eleva-crm-for-photographers')}
          onMouseDown={(e) => { e.preventDefault(); onChange(''); closeList() }}
          className="absolute inset-y-0 right-7 flex items-center border-0 bg-transparent p-0 text-gray-400 hover:text-gray-700 cursor-pointer"
        >
          <X size={14} />
        </button>
      )}
      {open && box && (
        <ul
          id={listId}
          role="listbox"
          className="fixed z-[100000] m-0 list-none overflow-auto rounded-md border border-gray-200 bg-white p-1 shadow-lg"
          style={{ left: box.left, width: box.width, top: box.top, bottom: box.bottom, maxHeight: box.maxHeight }}
        >
          {visible.length === 0 ? (
            <li className="px-3 py-2 text-sm text-gray-500">
              {loading ? __('Searching…', 'eleva-crm-for-photographers') : (emptyText ?? __('No results', 'eleva-crm-for-photographers'))}
            </li>
          ) : visible.map((opt, i) => (
            <li
              key={opt.value}
              id={`${uid}-opt-${i}`}
              role="option"
              aria-selected={String(opt.value) === current}
              aria-disabled={opt.disabled || undefined}
              onMouseDown={(e) => { e.preventDefault(); choose(opt) }}
              onMouseEnter={() => setActive(i)}
              className={[
                'cursor-pointer rounded px-3 py-1.5 text-sm',
                opt.disabled ? 'cursor-not-allowed text-gray-400' : 'text-gray-800',
                i === active ? 'bg-gray-100' : '',
                String(opt.value) === current ? 'font-semibold text-fotonic-primary' : '',
              ].join(' ')}
            >
              {opt.label}
            </li>
          ))}
          {footer && <li className="px-3 pb-1 pt-2 text-xs text-gray-500">{footer}</li>}
        </ul>
      )}
    </div>
  )
}
