/**
 * A link typed by the admin as a safe web address, or null when it is not one. Same rule as
 * `Fotonic_REST_API::normalize_link()`, keep the two in sync.
 *
 * "apple.com" is fine (a browser opens it too) and gets "https://" in front. What matters is the host:
 * a name, a dot and an extension of at least two letters ("apple" alone is not a link). Only http and
 * https are kept.
 */
const HOST = /^(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+(?:\p{L}{2,}|xn--[a-z0-9-]{2,})$/iu

export function normalizeLink(raw) {
  let value = (raw ?? '').trim()
  if (value === '' || /\s/u.test(value)) return null
  if (!/^https?:\/\//i.test(value)) {
    if (value.includes('://') || /^[a-z][a-z0-9+.-]*:(?!\d)/i.test(value)) return null
    value = `https://${value}`
  }
  let host
  try {
    host = new URL(value).hostname
  } catch {
    return null
  }
  return HOST.test(host) ? value : null
}
