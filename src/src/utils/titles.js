import { __, sprintf } from './i18n.js'

/**
 * The title of a customer or a work for the screen. A title the server cannot read (the Vault was reset, so the old
 * ciphertext has no key any more) comes as null: show "Customer 12" / "Work 34" instead of an empty cell, so lists,
 * pickers and links still work. `kind` is 'customer' or 'work'; `id` is optional.
 */
export function titleOr(title, kind, id) {
  if (typeof title === 'string' && title !== '') return title
  const format = kind === 'work' ? __('Work %d', 'eleva-crm-for-photographers') : __('Customer %d', 'eleva-crm-for-photographers')
  return sprintf(format, id || '').trim()
}
