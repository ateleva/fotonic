import { __ } from '../../utils/i18n'

// Names a photographer understands for the kinds of records a backup holds. A key this version does not know
// (a future Pro record) is called by a generic name: the raw key is never shown.
export function datasetLabel(key) {
  switch (key) {
    case 'ftnc_customer': return __('Customers', 'eleva-crm-for-photographers')
    case 'ftnc_service': return __('Services', 'eleva-crm-for-photographers')
    case 'ftnc_work': return __('Works', 'eleva-crm-for-photographers')
    case 'ftnc_memory_card': return __('Memory Cards', 'eleva-crm-for-photographers')
    case 'ftnc_backup_loc': return __('Backup Locations', 'eleva-crm-for-photographers')
    // Eleva CRM Pro records. A site without Pro still needs their names (the screen lists what is left out).
    case 'ftnc_quote': return __('Quotes', 'eleva-crm-for-photographers')
    case 'ftnc_collaborator': return __('Collaborators', 'eleva-crm-for-photographers')
    case 'ftnc_supplier': return __('Suppliers', 'eleva-crm-for-photographers')
    case 'ftnc_product': return __('Products', 'eleva-crm-for-photographers')
    case 'ftnc_expense': return __('Expenses', 'eleva-crm-for-photographers')
    case 'ftnc_task': return __('Tasks', 'eleva-crm-for-photographers')
    default: return __('Data of Eleva CRM Pro', 'eleva-crm-for-photographers')
  }
}

// What a field is called on screen. Unknown fields (Pro) are grouped under one generic name.
export function fieldLabel(field) {
  switch (field) {
    case 'post_title': return __('Name', 'eleva-crm-for-photographers')
    case 'post_content': return __('Notes', 'eleva-crm-for-photographers')
    case 'post_status': return __('Status', 'eleva-crm-for-photographers')
    case '_ftnc_people': return __('People and contact details', 'eleva-crm-for-photographers')
    case '_ftnc_base_price': return __('Price', 'eleva-crm-for-photographers')
    case '_ftnc_notes': return __('Notes', 'eleva-crm-for-photographers')
    case '_ftnc_event_date': return __('Event date', 'eleva-crm-for-photographers')
    case '_ftnc_event_time_from': return __('Start time', 'eleva-crm-for-photographers')
    case '_ftnc_event_time_to': return __('End time', 'eleva-crm-for-photographers')
    case '_ftnc_event_addresses': return __('Addresses', 'eleva-crm-for-photographers')
    case '_ftnc_customer_id': return __('Customer', 'eleva-crm-for-photographers')
    case '_ftnc_work_services': return __('Services', 'eleva-crm-for-photographers')
    case '_ftnc_work_files': return __('Files', 'eleva-crm-for-photographers')
    case '_ftnc_total_price': return __('Total price', 'eleva-crm-for-photographers')
    case '_ftnc_installments': return __('Payments', 'eleva-crm-for-photographers')
    case '_ftnc_color': return __('Colour', 'eleva-crm-for-photographers')
    case '_ftnc_quick_notes': return __('Quick notes', 'eleva-crm-for-photographers')
    case '_ftnc_memory_cards': return __('Memory Cards', 'eleva-crm-for-photographers')
    case '_ftnc_backup_done': return __('Backup done', 'eleva-crm-for-photographers')
    case '_ftnc_formatting_done': return __('Cards formatted', 'eleva-crm-for-photographers')
    case '_ftnc_storage_locations': return __('Backup Locations', 'eleva-crm-for-photographers')
    case '_ftnc_shots_taken': return __('Photos taken', 'eleva-crm-for-photographers')
    case '_ftnc_shots_delivered': return __('Photos delivered', 'eleva-crm-for-photographers')
    case '_ftnc_delivery_title':
    case '_ftnc_delivery_url':
    case '_ftnc_delivery_notes':
    case '_ftnc_delivery_password':
    case '_ftnc_delivery_expires': return __('Delivery', 'eleva-crm-for-photographers')
    case '_ftnc_loc_type': return __('Type', 'eleva-crm-for-photographers')
    case '_ftnc_loc_capacity': return __('Capacity', 'eleva-crm-for-photographers')
    case '_ftnc_loc_status': return __('Status', 'eleva-crm-for-photographers')
    case '_ftnc_loc_notes': return __('Notes', 'eleva-crm-for-photographers')
    default: return __('Other details', 'eleva-crm-for-photographers')
  }
}

export function formatBytes(bytes) {
  const mb = Math.round((bytes / (1024 * 1024)) * 10) / 10
  if (mb >= 1) return `${mb} MB`
  return `${Math.max(1, Math.round(bytes / 1024))} KB`
}

export function formatWhen(value) {
  if (!value) return ''
  const date = typeof value === 'number' ? new Date(value * 1000) : new Date(value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
}

// A job in one of these states still needs the person (or this tab) to finish it.
export const ACTIVE_STATUSES = ['running', 'paused', 'undoing', 'error', 'expired', 'done']

export function isFinishedJob(job) {
  return !job || job.status === 'undone' || job.status === 'dismissed'
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

// After these codes the opened backup is gone and has to be opened again.
export function isSessionGone(error) {
  return ['ftnc_restore_expired', 'ftnc_restore_session_expired', 'ftnc_restore_session_key_missing'].includes(error?.code)
}

// ---------------------------------------------------------------------------
// A value of a difference, in words a photographer reads. The server sends plain text, and for lists (people,
// services, payments, addresses) a JSON text: it is never shown as it is.
// ---------------------------------------------------------------------------

const SHORT = 150

// Fields that only hold the number of another record: nothing readable in them.
const LINK_FIELDS = ['_ftnc_customer_id', '_ftnc_memory_cards', '_ftnc_storage_locations']

// Keys of a row that say which record it points to, or how rows are ordered: they mean nothing on screen.
const HIDDEN_KEY = /(^|_)(id|ids|ref|uid|order|sort|position)$|^is_main$/

function stripHtml(text) {
  return String(text).replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim()
}

function cut(text) {
  return text.length > SHORT ? `${text.slice(0, SHORT - 1).trimEnd()}…` : text
}

function rowText(row) {
  if (row === null || typeof row !== 'object') return stripHtml(row ?? '')
  const parts = []
  const name = [row.first_name, row.last_name].map((v) => stripHtml(v ?? '')).filter(Boolean).join(' ')
  if (name) parts.push(name)
  Object.entries(row).forEach(([key, value]) => {
    if (key === 'first_name' || key === 'last_name' || key === 'gender' || HIDDEN_KEY.test(key)) return
    if (value === null || typeof value === 'object' || typeof value === 'boolean') return
    const text = stripHtml(value)
    if (text !== '') parts.push(text)
  })
  return parts.join(', ')
}

/** True when the field holds only a link to another record (its two sides cannot be shown as words). */
export function isLinkField(field) {
  return LINK_FIELDS.includes(field)
}

/** @return string Words for one side of a difference ('' when there is nothing to show). */
export function friendlyValue(value) {
  if (value === null || value === undefined) return ''
  if (Array.isArray(value)) return cut(value.map((v) => stripHtml(v)).filter(Boolean).join(', '))
  const text = String(value).trim()
  if (text === '') return ''
  if (text[0] === '[' || text[0] === '{') {
    try {
      const parsed = JSON.parse(text)
      const list = Array.isArray(parsed) ? parsed : [parsed]
      return cut(list.map(rowText).filter(Boolean).join('; '))
    } catch {
      // A list cut short by the server: read the "name":"value" pairs that are there.
      const pairs = []
      const re = /"([a-z_]+)":"([^"]*)"/g
      let m = re.exec(text)
      while (m) {
        pairs.push([m[1], m[2]])
        m = re.exec(text)
      }
      if (pairs.length > 0) {
        const row = {}
        pairs.forEach(([k, v]) => { if (!(k in row)) row[k] = v })
        return cut(rowText(row))
      }
    }
  }
  return cut(stripHtml(text))
}
