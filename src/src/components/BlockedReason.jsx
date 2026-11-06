import { __ } from '../utils/i18n'

// Where a record of each kind opens (the Pro bundle's routes included: quotes).
const ROUTES = { work: (id) => `/works/${id}`, quote: (id) => `/quotes/${id}` }

/**
 * What the "Cannot delete" window says: one heading per kind of record that still uses this one, and under it the records
 * themselves, each a link that opens it (the window closes first, `onGo`). With no `groups` (an old Pro, or an error text)
 * it falls back to the plain `reason`, one line per reason.
 *
 * @param {string}   reason  Plain text, reasons separated by a line break.
 * @param {object[]} groups  [{ heading, items: [{ type: 'work' | 'quote', id, title }] }]
 * @param {function} onGo    Called with the route to open.
 */
export default function BlockedReason({ reason, groups, onGo }) {
  if (Array.isArray(groups) && groups.length > 0) {
    return (
      <div className="mb-6 space-y-4">
        {groups.map((g, i) => (
          <div key={i}>
            <p className="text-sm font-medium text-gray-800">{g.heading}</p>
            <ul className="mt-1.5 space-y-1">
              {g.items.map((it) => {
                const route = ROUTES[it.type]?.(it.id)
                return (
                  <li key={`${it.type}-${it.id}`} className="flex gap-2 text-sm">
                    <span aria-hidden="true" className="text-red-600">•</span>
                    {route ? (
                      <button
                        type="button"
                        onClick={() => onGo(route)}
                        title={__('Open', 'eleva-crm-for-photographers')}
                        className="min-w-0 break-words border-0 bg-transparent p-0 text-left font-medium text-fotonic-primary underline cursor-pointer hover:opacity-80"
                      >
                        {it.title}
                      </button>
                    ) : (
                      <span className="min-w-0 break-words text-gray-700">{it.title}</span>
                    )}
                  </li>
                )
              })}
            </ul>
          </div>
        ))}
      </div>
    )
  }
  if (reason && reason.includes('\n')) {
    return (
      <ul className="mb-6 space-y-2 text-sm text-gray-700">
        {reason.split('\n').map((line, i) => (
          <li key={i} className="flex gap-2">
            <span aria-hidden="true" className="text-red-600">•</span>
            <span className="min-w-0 break-words">{line}</span>
          </li>
        ))}
      </ul>
    )
  }
  return <p className="mb-6 text-sm text-gray-600">{reason}</p>
}
