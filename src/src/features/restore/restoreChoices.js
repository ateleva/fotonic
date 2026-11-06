import { __, sprintf } from '../../utils/i18n'

// The choices of the "conflicts" step, kept apart from the screen so the rules are in one place.
//
// A choice is a string: 'keep' / 'backup' (for a record that already exists here), 'keep:12' / 'backup:12' (for a
// possible duplicate: 12 is the id of the record on this site it may be), 'import', 'copy', 'skip'.

export const PAGE_SIZE = 10

export const itemKey = (item) => `${item.dataset}:${item.backup_id}`

// One group per kind of record, in the order the server sent them (customers before works).
export function groupItems(items) {
  const groups = []
  const byKey = {}
  items.forEach((item) => {
    if (!byKey[item.dataset]) {
      byKey[item.dataset] = { dataset: item.dataset, new: [], different: [], possible: [] }
      groups.push(byKey[item.dataset])
    }
    if (byKey[item.dataset][item.status]) byKey[item.dataset][item.status].push(item)
  })
  return groups
}

export function defaultChoice(item) {
  return item.status === 'different' ? 'keep' : undefined
}

export function optionsFor(item) {
  if (item.status === 'different') {
    return [
      { value: 'keep', label: __('Keep the one on this site', 'eleva-crm-for-photographers') },
      { value: 'backup', label: __('Use the version from the backup', 'eleva-crm-for-photographers') },
      { value: 'copy', label: __('Add the backup version as a separate one', 'eleva-crm-for-photographers') },
      { value: 'skip', label: __('Leave it out', 'eleva-crm-for-photographers') },
    ]
  }
  // A possible duplicate.
  if (item.candidates.length === 0) {
    return [
      { value: 'import', label: __('Add it', 'eleva-crm-for-photographers') },
      { value: 'skip', label: __('Leave it out', 'eleva-crm-for-photographers') },
    ]
  }
  const out = []
  item.candidates.forEach((c) => {
    out.push({ value: `keep:${c.id}`, label: sprintf(__('It is "%s": keep the one on this site', 'eleva-crm-for-photographers'), c.title ?? '') })
    out.push({ value: `backup:${c.id}`, label: sprintf(__('It is "%s": use the version from the backup', 'eleva-crm-for-photographers'), c.title ?? '') })
  })
  out.push({ value: 'copy', label: __('It is a different one: add it', 'eleva-crm-for-photographers') })
  out.push({ value: 'skip', label: __('Leave it out', 'eleva-crm-for-photographers') })
  return out
}

// One choice for a whole group ("do this for all"), turned into the right choice for each record.
export function resolveBulk(item, bulk) {
  if (bulk === 'copy' || bulk === 'skip' || bulk === 'import') return bulk
  if (item.status === 'different') return bulk
  const first = item.candidates[0]
  return first ? `${bulk}:${first.id}` : 'import'
}

function toDecision(choice) {
  const [kind, id] = String(choice).split(':')
  const out = { action: { keep: 'keep_site', backup: 'use_backup', import: 'import', copy: 'copy', skip: 'skip' }[kind] }
  if (id) out.site_id = Number(id)
  return out
}

// Only what differs from the default goes to the server, plus every possible duplicate (always an explicit choice).
export function buildDecisions(items, choices, newBulk) {
  const out = {}
  const add = (item, choice) => {
    out[item.dataset] = out[item.dataset] ?? {}
    out[item.dataset][item.backup_id] = toDecision(choice)
  }
  items.forEach((item) => {
    if (item.status === 'new') {
      const bulk = newBulk[item.dataset]
      if (bulk && bulk !== 'import') add(item, bulk)
    } else if (item.status === 'different') {
      const choice = choices[itemKey(item)] ?? 'keep'
      if (choice !== 'keep') add(item, choice)
    } else if (item.status === 'possible') {
      const choice = choices[itemKey(item)]
      if (choice) add(item, choice)
    }
  })
  return out
}

export function countMissing(items, choices) {
  return items.filter((i) => i.status === 'possible' && !choices[itemKey(i)]).length
}

// How many records will be added and how many replaced, for the last look before the restore starts.
export function summarize(items, choices, newBulk) {
  let added = 0
  let replaced = 0
  items.forEach((item) => {
    if (item.status === 'new') {
      if ((newBulk[item.dataset] ?? 'import') !== 'skip') added++
      return
    }
    const choice = String(choices[itemKey(item)] ?? defaultChoice(item) ?? '')
    if (choice === 'copy' || choice === 'import') added++
    else if (choice.startsWith('backup')) replaced++
  })
  return { added, replaced }
}
