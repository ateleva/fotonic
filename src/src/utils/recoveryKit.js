import { __ } from './i18n'

// Single client-side source for the vault password minimum (server: MIN_PASSWORD_LENGTH).
export const MIN_VAULT_PASSWORD = 8

/** Text after the last dash of a recovery secret. */
export function lastGroup(secret) {
  const s = String(secret ?? '')
  const i = s.lastIndexOf('-')
  return i === -1 ? s : s.slice(i + 1)
}

/** Case-insensitive compare of the typed text with the secret's last group; dashes and spaces ignored. */
export function matchesLastGroup(secret, input) {
  const clean = (v) => String(v ?? '').replace(/[\s-]/g, '').toUpperCase()
  const expected = clean(lastGroup(secret))
  return expected !== '' && clean(input) === expected
}

/** Plain text for the "Copy all" button. */
export function kitText({ siteName, phrase, code }) {
  return [
    __('Eleva CRM vault recovery kit', 'eleva-crm-for-photographers'),
    siteName,
    '',
    __('Recovery phrase:', 'eleva-crm-for-photographers') + ' ' + phrase,
    __('Recovery code:', 'eleva-crm-for-photographers') + ' ' + code,
    '',
    __('Either one opens the vault and lets you set a new password and a new authenticator, without losing any data. Anyone who has them can open the vault.', 'eleva-crm-for-photographers'),
  ].join('\n')
}
