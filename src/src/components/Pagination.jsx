import { ChevronLeft, ChevronRight } from 'lucide-react'
import Button from './Button'
import { __, sprintf } from '../utils/i18n'

/**
 * Previous / Next under a list. Nothing is drawn when everything fits on one page.
 * `page` is 1-based; `onPage(n)` is called with the new page.
 */
export default function Pagination({ page, pages, total, size, onPage }) {
  if (!total || total <= size || pages <= 1) return null

  const first = (page - 1) * size + 1
  const last = Math.min(page * size, total)

  return (
    <nav aria-label={__('Pagination', 'eleva-crm-for-photographers')} className="mt-4 flex flex-wrap items-center justify-between gap-3">
      <p className="text-sm text-gray-600">
        {sprintf(__('%1$s-%2$s of %3$s', 'eleva-crm-for-photographers'), first, last, total)}
      </p>
      <div className="flex items-center gap-2">
        <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          <ChevronLeft size={14} />
          {__('Previous page', 'eleva-crm-for-photographers')}
        </Button>
        <span className="text-sm text-gray-600 px-1">
          {sprintf(__('Page %1$s of %2$s', 'eleva-crm-for-photographers'), page, pages)}
        </span>
        <Button variant="secondary" size="sm" disabled={page >= pages} onClick={() => onPage(page + 1)}>
          {__('Next page', 'eleva-crm-for-photographers')}
          <ChevronRight size={14} />
        </Button>
      </div>
    </nav>
  )
}
