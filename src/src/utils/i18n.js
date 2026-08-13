export function __(text, _domain) {
  if (typeof window !== 'undefined' && window.wp && window.wp.i18n) {
    return window.wp.i18n.__(text, 'eleva-crm-for-photographers')
  }
  return text
}

export function sprintf(format, ...args) {
  if (typeof window !== 'undefined' && window.wp && window.wp.i18n) {
    return window.wp.i18n.sprintf(format, ...args)
  }
  let i = 0
  return format.replace(/%s/g, () => args[i++] ?? '')
}
