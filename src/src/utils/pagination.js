import { useState } from 'react'

// Rows per page in every list. The Works list shows more because a photographer has many of them.
export const PAGE_SIZE = 24
export const WORKS_PAGE_SIZE = 48

/**
 * Paging for a list that is already in the browser (the small catalogs: services, cards, locations...).
 * The page is clamped, so a list that gets shorter (a filter, a delete) never leaves an empty page. Call
 * `setPage(1)` when the filter changes.
 */
export function usePagedItems(items, size = PAGE_SIZE) {
  const [page, setPage] = useState(1)
  const total = items.length
  const pages = Math.max(1, Math.ceil(total / size))
  const current = Math.min(page, pages)
  return {
    pageItems: items.slice((current - 1) * size, current * size),
    page: current,
    pages,
    total,
    size,
    setPage,
  }
}
