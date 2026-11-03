import { __ } from './i18n'

// Section keys come from the server (entries carry one); the label is the same word the sidebar uses.
export function sectionLabel(section) {
  switch (section) {
    case 'dashboard': return __('Dashboard', 'eleva-crm-for-photographers')
    case 'works': return __('Works', 'eleva-crm-for-photographers')
    case 'storage': return __('Storage', 'eleva-crm-for-photographers')
    case 'services': return __('Services', 'eleva-crm-for-photographers')
    case 'kanban': return __('Kanban', 'eleva-crm-for-photographers')
    case 'analytics': return __('Analytics', 'eleva-crm-for-photographers')
    case 'expenses': return __('Expenses', 'eleva-crm-for-photographers')
    case 'suppliers': return __('Suppliers', 'eleva-crm-for-photographers')
    case 'quotes': return __('Quotes', 'eleva-crm-for-photographers')
    default: return section
  }
}

// Sections in the order of the sidebar, so the overview reads top to bottom like the menu.
const SECTION_ORDER = ['dashboard', 'services', 'works', 'storage', 'quotes', 'kanban', 'analytics', 'suppliers', 'expenses']

export function groupBySection(entries = []) {
  const map = {}
  for (const e of entries) (map[e.section] ??= []).push(e)
  return map
}

export function orderedSections(map) {
  return [...SECTION_ORDER.filter((s) => map[s]), ...Object.keys(map).filter((s) => !SECTION_ORDER.includes(s))]
}

// Where each section lives in the app, for the "Go to the ... section" button.
export function sectionPath(section) {
  const paths = {
    dashboard: '/dashboard', works: '/works', storage: '/storage', services: '/services', quotes: '/quotes',
    kanban: '/kanban', analytics: '/analytics', suppliers: '/suppliers', expenses: '/expenses',
  }
  return paths[section] ?? '/dashboard'
}

// Radix locks the page while a window is open (pointer-events: none on the body) and unlocks it when the last one
// closes. If that unlock is ever missed the whole app stops answering clicks until a reload, so these windows
// check it themselves. Only a Radix window that is really open counts (not any role="dialog" in the page: the
// WordPress admin and other plugins leave some of their own there).
export function releasePageLock() {
  if (document.body.style.pointerEvents === 'none' && !document.querySelector('[role="dialog"][data-state="open"]')) {
    document.body.style.pointerEvents = ''
  }
}

// Several tries over two seconds, because the lock can be left a moment after the window is gone.
export function releasePageLockSoon() {
  ;[0, 150, 500, 1200, 2500].forEach((ms) => window.setTimeout(releasePageLock, ms))
}
