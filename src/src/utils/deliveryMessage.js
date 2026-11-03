import { normalizeLink } from './link.js'
import { formatDate } from './date.js'
import { __, sprintf } from './i18n.js'

/**
 * The message the admin copies to send the gallery to the client (WhatsApp, a social app, an email).
 *   Hi Anna, you can find the gallery at this link: https://...
 *   To unlock the gallery use this password: ...        (only when a password is written)
 *   The link expires on 30/06/2026.                      (only when an expiry date is written)
 * Returns '' when there is no usable link: a USB stick has nothing to send.
 *
 * @param {{ name?: string, url?: string, password?: string, expires?: string }} d
 */
export function buildDeliveryMessage({ name, url, password, expires }) {
  const link = normalizeLink(url)
  if (!link) return ''

  const who = (name ?? '').trim()
  const parts = [
    who
      ? sprintf(__('Hi %1$s, you can find the gallery at this link: %2$s', 'eleva-crm-for-photographers'), who, link)
      : sprintf(__('Hi, you can find the gallery at this link: %s', 'eleva-crm-for-photographers'), link),
  ]
  const pass = (password ?? '').trim()
  if (pass) {
    parts.push(sprintf(__('To unlock the gallery use this password: %s', 'eleva-crm-for-photographers'), pass))
  }
  if (expires) {
    parts.push(sprintf(__('The link expires on %s.', 'eleva-crm-for-photographers'), formatDate(expires)))
  }
  return parts.join('\n\n')
}

/** First name of the main contact of a customer record ('' when there is none, or it cannot be read). */
export function mainContactName(customer) {
  const people = customer?.people ?? []
  const main = people.find((p) => p.is_main) ?? people[0]
  return (main?.first_name ?? '').trim()
}
