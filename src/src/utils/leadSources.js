// Where a customer came from. The list and the sources that take a short text come from PHP
// (Fotonic_Lead_Sources) through window.FotonicApp, so the two never disagree.

export const leadSourceOptions = () =>
  Array.isArray(window.FotonicApp?.leadSources) ? window.FotonicApp.leadSources : []

export const takesLeadDetail = (source) =>
  (window.FotonicApp?.leadDetailSources ?? []).includes(source)

// "Word of mouth: Mario", "Supplier: Villa Rossi"; the label alone without a text (or when the Vault
// is locked and the text cannot be read); '' without a source.
export function leadSourceText(customer) {
  const label = customer?.lead_source_label || ''
  const detail = (customer?.lead_source_detail || '').trim()
  if (!label) return ''
  return detail && takesLeadDetail(customer.lead_source) ? `${label}: ${detail}` : label
}
