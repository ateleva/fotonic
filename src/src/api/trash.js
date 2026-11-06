import { apiFetch } from './client'

/** { count, by_type: { post_type: n }, names: { post_type: plural name }, auto_days, word }: cheap, shared by every shortcut. */
export function fetchTrashSummary() {
  return apiFetch('trash/summary')
}

/** One page of the Bin (`type` = one record type, '' = all): { data: [{ id, type, type_label, title|null, trashed_at, expires_at|null, blocked|null }], total, pages }. */
export function fetchTrash(page = 1, perPage = 24, type = '') {
  const t = type ? `&type=${encodeURIComponent(type)}` : ''
  return apiFetch(`trash?page=${page}&per_page=${perPage}${t}`)
}

/** { results: [{ id, ok, warnings: [{ code: 'parent_in_bin' | 'parent_missing', type }] }] } */
export function restoreTrash(ids) {
  return apiFetch('trash/restore', { method: 'POST', body: JSON.stringify({ ids }) })
}

/**
 * One batch of a permanent delete: { deleted, blocked: [{ id, type_label, reason }], processed, remaining }.
 * Several ids, or `all`, need `confirm` = the word of the summary. `all` with a `type` deletes only that type.
 */
export function deleteTrash({ ids, all = false, type = '', skip = [], confirm = '' }) {
  return apiFetch('trash/delete', {
    method: 'POST',
    body: JSON.stringify(all ? { all: true, type: type || undefined, skip, confirm } : { ids, confirm }),
  })
}

/** The list a record type belongs to: where "Back" goes from the Bin page when it was opened for one type. */
export const TRASH_LIST_ROUTES = {
  ftnc_customer: '/customers',
  ftnc_service: '/services',
  ftnc_work: '/works',
  ftnc_memory_card: '/storage/cards',
  ftnc_backup_loc: '/storage/backups',
  ftnc_quote: '/quotes',
  ftnc_collaborator: '/collaborators',
  ftnc_product: '/products',
  ftnc_supplier: '/suppliers',
  ftnc_expense: '/expenses',
  ftnc_task: '/kanban',
}
