// Turns one difference between a record of this site and the same record of a backup into rows a photographer can
// read: one row per thing (a payment, a person, a service), with the two sides next to each other and the details
// that changed marked. Pro describes its own fields in window.FotonicProComponents.restoreFields.
import { __ } from '../../utils/i18n.js'
import { formatDate, formatTime } from '../../utils/date.js'
import { leadSourceOptions } from '../../utils/leadSources.js'

// Row keys that only say which record a row points to, or how rows are ordered.
const HIDDEN = /^(ref|uid|order|sort|position|id)$/

const stripHtml = (text) => String(text ?? '')
  .replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim()

const yesNo = (v) => (v === '1' ? __('Yes', 'eleva-crm-for-photographers') : __('No', 'eleva-crm-for-photographers'))

function money(v) {
  const n = Number(v)
  if (v === '' || Number.isNaN(n)) return v
  const locale = (window.FotonicApp?.locale ?? 'en_US').replace('_', '-')
  return new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' }).format(n)
}

const oneOf = (labels) => (v) => labels[v]?.() ?? v

const statusLabels = {
  paid: () => __('Paid', 'eleva-crm-for-photographers'),
  unpaid: () => __('Unpaid', 'eleva-crm-for-photographers'),
  publish: () => __('Active', 'eleva-crm-for-photographers'),
  trash: () => __('In the Bin', 'eleva-crm-for-photographers'),
  active: () => __('Active', 'eleva-crm-for-photographers'),
  full: () => __('Full', 'eleva-crm-for-photographers'),
  retired: () => __('Retired', 'eleva-crm-for-photographers'),
  // The statuses of a memory card, named as in the Memory Cards list.
  free: () => __('Ready', 'eleva-crm-for-photographers'),
  backed_up: () => __('Backed Up', 'eleva-crm-for-photographers'),
  damaged: () => __('Damaged', 'eleva-crm-for-photographers'),
  in_use: () => __('In Use', 'eleva-crm-for-photographers'),
}

// How a value is written, by kind. `ctx` carries the payment type names and the record names of this item.
const KINDS = {
  text: (v) => stripHtml(v),
  money,
  date: (v) => formatDate(v),
  time: (v) => formatTime(v),
  bool: yesNo,
  status: oneOf(statusLabels),
  percent: (v) => (v === '' ? '' : `${v}%`),
  count: (v) => v,
  record: (v, ctx) => ctx.names?.[v] ?? __('One that is only in the backup', 'eleva-crm-for-photographers'),
  paymentType: (v, ctx) => ctx.paymentTypes?.[v] ?? __('A payment type this site does not have', 'eleva-crm-for-photographers'),
  leadSource: (v) => leadSourceOptions().find((o) => o.value === v)?.label ?? v,
  fileKind: (v) => (v === 'link' ? __('Link', 'eleva-crm-for-photographers') : __('Document', 'eleva-crm-for-photographers')),
}

const f = (label, kind = 'text') => ({ label, kind })

// Fields of the free records: what each one is called and how it is written. `columns` describes the rows of a list
// and `name` the columns that tell one row from another.
function freeFields() {
  return {
    post_title: f(__('Name', 'eleva-crm-for-photographers')),
    post_content: f(__('Notes', 'eleva-crm-for-photographers')),
    post_status: f(__('Status', 'eleva-crm-for-photographers'), 'status'),
    _ftnc_notes: f(__('Notes', 'eleva-crm-for-photographers')),
    _ftnc_base_price: f(__('Price', 'eleva-crm-for-photographers'), 'money'),
    _ftnc_lead_source: f(__('Where the customer came from', 'eleva-crm-for-photographers'), 'leadSource'),
    _ftnc_lead_source_detail: f(__('Who referred the customer', 'eleva-crm-for-photographers')),
    _ftnc_event_date: f(__('Event date', 'eleva-crm-for-photographers'), 'date'),
    _ftnc_event_time_from: f(__('Start time', 'eleva-crm-for-photographers'), 'time'),
    _ftnc_event_time_to: f(__('End time', 'eleva-crm-for-photographers'), 'time'),
    _ftnc_customer_id: f(__('Customer', 'eleva-crm-for-photographers'), 'record'),
    _ftnc_total_price: f(__('Total price', 'eleva-crm-for-photographers'), 'money'),
    _ftnc_color: f(__('Colour', 'eleva-crm-for-photographers')),
    _ftnc_quick_notes: f(__('Quick notes', 'eleva-crm-for-photographers')),
    _ftnc_backup_done: f(__('Backup done', 'eleva-crm-for-photographers'), 'bool'),
    _ftnc_formatting_done: f(__('Cards formatted', 'eleva-crm-for-photographers'), 'bool'),
    _ftnc_shots_taken: f(__('Photos taken', 'eleva-crm-for-photographers')),
    _ftnc_shots_delivered: f(__('Photos delivered', 'eleva-crm-for-photographers')),
    _ftnc_gcal_sync: f(__('Sent to Google Calendar', 'eleva-crm-for-photographers'), 'bool'),
    _ftnc_delivery_title: f(__('Delivery title', 'eleva-crm-for-photographers')),
    _ftnc_delivery_url: f(__('Delivery link', 'eleva-crm-for-photographers')),
    _ftnc_delivery_notes: f(__('Delivery notes', 'eleva-crm-for-photographers')),
    _ftnc_delivery_password: f(__('Delivery password', 'eleva-crm-for-photographers')),
    _ftnc_delivery_expires: f(__('Delivery link expiry', 'eleva-crm-for-photographers'), 'date'),
    _ftnc_loc_type: f(__('Type', 'eleva-crm-for-photographers')),
    _ftnc_loc_capacity: f(__('Capacity', 'eleva-crm-for-photographers')),
    _ftnc_loc_status: f(__('Status', 'eleva-crm-for-photographers'), 'status'),
    _ftnc_loc_notes: f(__('Notes', 'eleva-crm-for-photographers')),
    'term:ftnc_card_status': f(__('Status', 'eleva-crm-for-photographers'), 'status'),
    _ftnc_people: {
      ...f(__('People and contact details', 'eleva-crm-for-photographers')),
      name: ['first_name', 'last_name'],
      // The same person even after a change of surname or one contact.
      match: ['email', 'phone', 'first_name'],
      columns: {
        first_name: f(__('First name', 'eleva-crm-for-photographers')),
        last_name: f(__('Last name', 'eleva-crm-for-photographers')),
        email: f(__('Email', 'eleva-crm-for-photographers')),
        phone: f(__('Phone', 'eleva-crm-for-photographers')),
        nationality: f(__('Nationality', 'eleva-crm-for-photographers')),
        instagram_username: f(__('Instagram', 'eleva-crm-for-photographers')),
        address: f(__('Address', 'eleva-crm-for-photographers')),
        tin: f(__('Tax code', 'eleva-crm-for-photographers')),
        gender: {
          ...f(__('Gender', 'eleva-crm-for-photographers')),
          labels: { male: __('Male', 'eleva-crm-for-photographers'), female: __('Female', 'eleva-crm-for-photographers') },
        },
        is_main: f(__('Main contact', 'eleva-crm-for-photographers'), 'bool'),
      },
    },
    _ftnc_event_addresses: {
      ...f(__('Addresses', 'eleva-crm-for-photographers')),
      name: ['label'],
      columns: { label: f(__('Name', 'eleva-crm-for-photographers')), street: f(__('Address', 'eleva-crm-for-photographers')) },
    },
    _ftnc_work_services: {
      ...f(__('Services', 'eleva-crm-for-photographers')),
      name: ['service_id'],
      columns: {
        service_id: f(__('Service', 'eleva-crm-for-photographers'), 'record'),
        price_override: f(__('Price', 'eleva-crm-for-photographers'), 'money'),
        notes_override: f(__('Notes', 'eleva-crm-for-photographers')),
      },
    },
    _ftnc_installments: {
      ...f(__('Payments', 'eleva-crm-for-photographers')),
      name: ['title'],
      columns: {
        title: f(__('Name', 'eleva-crm-for-photographers')),
        base: f(__('Amount', 'eleva-crm-for-photographers'), 'money'),
        status: f(__('Status', 'eleva-crm-for-photographers'), 'status'),
        type: f(__('Payment type', 'eleva-crm-for-photographers'), 'paymentType'),
        taxable: f(__('Taxable', 'eleva-crm-for-photographers'), 'bool'),
        date: f(__('Date', 'eleva-crm-for-photographers'), 'date'),
        surcharge: f(__('Surcharge', 'eleva-crm-for-photographers'), 'money'),
        vat: f(__('VAT', 'eleva-crm-for-photographers'), 'money'),
        amount: f(__('Total to collect', 'eleva-crm-for-photographers'), 'money'),
      },
    },
    _ftnc_work_files: {
      ...f(__('Files', 'eleva-crm-for-photographers')),
      name: ['label', 'url'],
      columns: {
        label: f(__('Name', 'eleva-crm-for-photographers')),
        url: f(__('Link', 'eleva-crm-for-photographers')),
        type: f(__('Type', 'eleva-crm-for-photographers'), 'fileKind'),
      },
    },
    _ftnc_memory_cards: {
      ...f(__('Memory Cards', 'eleva-crm-for-photographers')),
      name: ['card_id'],
      columns: {
        card_id: f(__('Card', 'eleva-crm-for-photographers'), 'record'),
        notes: f(__('Notes', 'eleva-crm-for-photographers')),
      },
    },
    _ftnc_storage_locations: {
      ...f(__('Backup Locations', 'eleva-crm-for-photographers')),
      name: ['location_id'],
      columns: { location_id: f(__('Location', 'eleva-crm-for-photographers'), 'record') },
    },
  }
}

// Fields that mean nothing to a person: they are named, their details are not listed.
const SILENT = ['_ftnc_pay_model', '_ftnc_owner_type']

/** The description of one field, from this plugin or from Pro (Pro wins for a field both know). */
export function fieldSpec(field) {
  const pro = window.FotonicProComponents?.restoreFields?.(field)
  return pro ?? freeFields()[field] ?? null
}

function write(kind, value, ctx, labels) {
  if (value === null || value === undefined) return ''
  // A switch that is off is stored as nothing: it still reads "No".
  if (kind === 'bool') return yesNo(String(value))
  if (value === '') return ''
  if (labels) return labels[value] ?? value
  return (KINDS[kind] ?? KINDS.text)(String(value), ctx)
}

const isRow = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)

function details(row, spec, ctx) {
  if (!isRow(row)) {
    const text = write(spec.kind, row, ctx, spec.labels)
    return text === '' ? [] : [{ key: 'value', label: '', text, raw: String(row) }]
  }
  const columns = spec.columns ?? {}
  const keys = [...Object.keys(columns).filter((k) => k in row), ...Object.keys(row).filter((k) => !(k in columns))]
  return keys.flatMap((key) => {
    if (!(key in columns) && HIDDEN.test(key)) return []
    const value = row[key]
    if (isRow(value) || Array.isArray(value)) return []
    const column = columns[key]
    const text = write(column?.kind, value, ctx, column?.labels)
    if (text === '') return []
    return [{ key, label: column?.label ?? __('Other detail', 'eleva-crm-for-photographers'), text, raw: String(value) }]
  })
}

function rowName(row, spec, ctx) {
  if (!isRow(row)) return ''
  return (spec.name ?? [])
    .map((key) => write(spec.columns?.[key]?.kind, row[key], ctx, spec.columns?.[key]?.labels))
    .filter(Boolean)
    .join(' ')
}

const stable = (v) => (isRow(v)
  ? JSON.stringify(Object.keys(v).sort().map((k) => [k, stable(v[k])]))
  : JSON.stringify(v))

// Pairs the rows of the two lists: first the equal ones, then the ones with the same name, then the ones that share
// a detail that identifies them, then by position when a row has no name.
function pair(site, backup, spec, ctx) {
  const free = new Set(backup.map((_, i) => i))
  const mate = new Array(site.length).fill(null)
  const tries = [
    (a, b) => stable(a) === stable(b),
    (a, b) => rowName(a, spec, ctx) !== '' && rowName(a, spec, ctx) === rowName(b, spec, ctx),
    ...(spec.match ?? []).map((key) => (a, b) => Boolean(a?.[key]) && a[key] === b?.[key]),
    (a, b, i, j) => i === j && (rowName(a, spec, ctx) === '' || rowName(b, spec, ctx) === ''),
  ]
  tries.forEach((same) => {
    site.forEach((a, i) => {
      if (mate[i] !== null) return
      const j = [...free].find((n) => same(a, backup[n], i, n))
      if (j !== undefined) { mate[i] = j; free.delete(j) }
    })
  })
  return [
    ...site.map((a, i) => [a, mate[i] === null ? undefined : backup[mate[i]]]),
    ...[...free].map((j) => [undefined, backup[j]]),
  ]
}

// One line per detail, with what each side says ('' = nothing there).
function lines(site, backup) {
  const keys = [...new Set([...site, ...backup].map((d) => d.key))]
  return keys.map((key) => {
    const a = site.find((d) => d.key === key)
    const b = backup.find((d) => d.key === key)
    return { key, label: (a ?? b).label, site: a?.text ?? '', backup: b?.text ?? '', changed: a?.raw !== b?.raw }
  })
}

// `only` says that the whole row exists on one side: 'site', 'backup' or null.
function row(title, site, backup) {
  const only = site === null ? 'backup' : (backup === null ? 'site' : null)
  const all = lines(site ?? [], backup ?? [])
  return { title, only, same: only === null && all.every((l) => !l.changed), lines: all }
}

const asList = (v) => {
  if (v === '' || v === null || v === undefined) return []
  return Array.isArray(v) ? v : [v]
}

/**
 * What changes in one field. `type` says how to show it:
 *  - 'value': one thing with two values (`rows` has one row with one line);
 *  - 'list':  several things (payments, people), one row each;
 *  - 'table': two tables of cells, in `site` and `backup`;
 *  - 'note':  only a sentence;
 *  - 'hidden': bookkeeping nobody sees on the record pages.
 */
export function compareField(diff, ctx = {}) {
  const spec = fieldSpec(diff.field)
  const label = spec?.label ?? ''
  const base = { field: diff.field, label, rows: [] }
  // A field nobody described (internal ids, bookkeeping) means nothing to a person.
  if (!spec || SILENT.includes(diff.field)) return { ...base, type: 'hidden' }
  if (diff.unreadable) {
    return { ...base, type: 'note', note: __('One of the two cannot be read, so they cannot be shown side by side.', 'eleva-crm-for-photographers') }
  }
  const context = { ...ctx, names: ctx.names?.[diff.field] ?? {} }
  if (spec.table) {
    const cells = (value) => (value ? spec.table(value) : [])
    return { ...base, type: 'table', site: cells(diff.site), backup: cells(diff.backup) }
  }
  if (spec.columns) {
    const rows = pair(asList(diff.site), asList(diff.backup), spec, context).map(([a, b]) => row(
      rowName(a, spec, context) || rowName(b, spec, context),
      a === undefined ? null : details(a, spec, context),
      b === undefined ? null : details(b, spec, context),
    ))
    const note = rows.length > 0 && rows.every((r) => r.same)
      ? __('The same entries, in a different order or written in a different way.', 'eleva-crm-for-photographers')
      : ''
    return { ...base, type: 'list', rows, note }
  }
  // A single value, or a list of plain words (the terms of a record).
  const value = (v) => {
    const list = asList(v).filter((x) => !isRow(x))
    const text = spec.kind === 'bool' && list.length === 0
      ? write('bool', '', context)
      : list.map((x) => write(spec.kind, x, context, spec.labels)).filter(Boolean).join(', ')
    return text ? [{ key: 'value', label, text, raw: text }] : []
  }
  const r = row('', value(diff.site), value(diff.backup))
  return { ...base, type: 'value', rows: [{ ...r, lines: r.lines.length ? r.lines : [{ key: 'value', label, site: '', backup: '', changed: false }] }] }
}
