export function __(text) {
  if (typeof window !== 'undefined' && window.wp && window.wp.i18n) {
    return window.wp.i18n.__(text, 'fotonic')
  }
  return text
}
