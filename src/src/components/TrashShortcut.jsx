import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import { __, sprintf } from '../utils/i18n'
import { fetchTrashSummary } from '../api/trash'

/**
 * The small Bin button of a list: shown only when at least one record of this type is in the Bin, with how many, and
 * opens the Bin page filtered to that type. Quiet on purpose (a red icon and a thin red outline, no fill): it is there to be
 * noticed, not to shout. `['trash-summary']` is shared with the Settings card and the Pro lists (one cached request).
 */
export default function TrashShortcut({ type }) {
  const navigate = useNavigate()
  const { data } = useQuery({ queryKey: ['trash-summary'], queryFn: fetchTrashSummary, staleTime: 0 })
  const n = data?.by_type?.[type] ?? 0
  if (!n) return null
  const label = sprintf(__('Items in the Bin: %s', 'eleva-crm-for-photographers'), n)
  return (
    <button
      type="button"
      onClick={() => navigate('/trash?type=' + encodeURIComponent(type))}
      title={label}
      aria-label={label}
      className="inline-flex items-center justify-center gap-2 rounded-md bg-white px-4 py-2 text-sm font-medium text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-50 transition-colors cursor-pointer"
    >
      <Trash2 size={14} aria-hidden="true" />
      {sprintf(__('Bin (%s)', 'eleva-crm-for-photographers'), n)}
    </button>
  )
}
