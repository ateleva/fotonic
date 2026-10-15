// A complete email address: local part, "@", a domain and a final extension of 2+ letters
// (name@example.com). "name@domain" and "name@domain.c" are not valid. Mirrors the server check,
// which refuses what it would otherwise empty on save.
const EMAIL_RE = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z][A-Za-z0-9-]*[A-Za-z0-9]$/

export function isValidEmail(value) {
  const email = String(value ?? '').trim()
  if (email.length < 6 || email.length > 254 || !EMAIL_RE.test(email)) return false
  const local = email.slice(0, email.lastIndexOf('@'))
  return !local.startsWith('.') && !local.endsWith('.') && !local.includes('..')
}

/** First non-empty email in a people list that is not valid, or null. null (unreadable) is skipped. */
export function firstInvalidEmail(people) {
  for (const p of Array.isArray(people) ? people : []) {
    const email = p?.email
    if (typeof email === 'string' && email.trim() !== '' && !isValidEmail(email)) return email.trim()
  }
  return null
}

/** Indexes of the people whose filled email is not valid. */
export function invalidEmailIndexes(people) {
  const out = []
  ;(Array.isArray(people) ? people : []).forEach((p, i) => {
    if (typeof p?.email === 'string' && p.email.trim() !== '' && !isValidEmail(p.email)) out.push(i)
  })
  return out
}
