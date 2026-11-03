// The filter row shared by the Storage tabs. Each button carries its own count, and clicking it shows exactly
// that many rows. Theme colour only: a tint of it for the chosen one.
export default function FilterPills({ options, value, onChange }) {
  return (
    <div className="flex flex-wrap gap-2">
      {options.map(({ key, label, count }) => {
        const active = value === key
        return (
          <button
            key={key}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(key)}
            className={[
              'px-3 py-1.5 rounded-md text-sm font-medium transition-colors border',
              active
                ? 'border-fotonic-primary text-fotonic-primary'
                : 'bg-white border-gray-300 text-gray-600 hover:bg-gray-50',
            ].join(' ')}
            style={active ? { background: 'color-mix(in srgb, var(--fotonic-primary) 10%, white)' } : undefined}
          >
            {label} ({count})
          </button>
        )
      })}
    </div>
  )
}
