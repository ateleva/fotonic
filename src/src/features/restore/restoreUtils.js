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
