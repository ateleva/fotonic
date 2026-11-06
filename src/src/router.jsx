/* eslint-disable react-refresh/only-export-components -- this file is a router
   config module, not a components module; createRouter() returns a router
   object, and Fallback/Lazy are internal helpers, never exported. Splitting
   them into a separate file only to satisfy Fast Refresh isn't worth the risk
   on the app's single most load-bearing file for a DX-only concern. */
import { Suspense } from 'react'
import { lazyPage } from './utils/lazyImport'
import { createHashRouter, Navigate, useParams } from 'react-router-dom'
import Layout from './components/Layout'
import Spinner from './components/Spinner'
import { __ } from './utils/i18n'

const Dashboard      = lazyPage(() => import('./features/dashboard/Dashboard'))
const CustomerList   = lazyPage(() => import('./features/customers/CustomerList'))
const CustomerForm   = lazyPage(() => import('./features/customers/CustomerForm'))
const ServiceList    = lazyPage(() => import('./features/services/ServiceList'))
const ServiceForm    = lazyPage(() => import('./features/services/ServiceForm'))
const WorkList       = lazyPage(() => import('./features/works/WorkList'))
const WorkForm       = lazyPage(() => import('./features/works/WorkForm'))
const MemoryCardForm = lazyPage(() => import('./features/memory-cards/MemoryCardForm'))
const StoragePage = lazyPage(() => import('./features/storage/StoragePage'))
const BackupLocationForm = lazyPage(() => import('./features/storage/BackupLocationForm'))
const SettingsPage   = lazyPage(() => import('./features/settings/SettingsPage'))
const RestoreWizard  = lazyPage(() => import('./features/restore/RestoreWizard'))
const TrashPage      = lazyPage(() => import('./features/trash/TrashPage'))
const ChangelogPage  = lazyPage(() => import('./features/changelog/ChangelogPage'))
const CalendarView   = lazyPage(() => import('./features/calendar/CalendarView').then(m => ({ default: m.CalendarView })))

function Fallback() {
  return (
    <div className="flex items-center justify-center h-48">
      <Spinner />
    </div>
  )
}

function MemoryCardRedirect() {
  const { id } = useParams()
  return <Navigate to={`/storage/cards/${id}`} replace />
}

function Lazy({ component: Component }) {
  return (
    <Suspense fallback={<Fallback />}>
      <Component />
    </Suspense>
  )
}

// What the browser tab says on each page, so several tabs of the app can be told apart. Always a generic name
// ("Edit Work"), never the record's own title: customer names and the like must not end up in the browser history.
// Functions, so the translation is read when the tab is named, and every text is a literal 2-argument __() so the
// translation pipeline can find it. The Pro pages are listed here too, because their
// routes are declared below (only when Pro is active) and the tab title is the same mechanism for all.
const STORAGE_TABS = {
  cards: () => __('Memory Cards', 'eleva-crm-for-photographers'),
  backups: () => __('Backup Locations', 'eleva-crm-for-photographers'),
  shots: () => __('Shots History', 'eleva-crm-for-photographers'),
}
const ROUTE_TITLES = {
  'dashboard': () => __('Dashboard', 'eleva-crm-for-photographers'),
  'customers': () => __('Customers', 'eleva-crm-for-photographers'), 'customers/new': () => __('New Customer', 'eleva-crm-for-photographers'), 'customers/:id': () => __('Edit Customer', 'eleva-crm-for-photographers'),
  'services': () => __('Services', 'eleva-crm-for-photographers'), 'services/new': () => __('New Service', 'eleva-crm-for-photographers'), 'services/:id': () => __('Edit Service', 'eleva-crm-for-photographers'),
  'works': () => __('Works', 'eleva-crm-for-photographers'), 'works/new': () => __('New Work', 'eleva-crm-for-photographers'), 'works/:id': () => __('Edit Work', 'eleva-crm-for-photographers'),
  // The tab first: a narrow browser tab cuts the end of the title, and the tab is what tells the pages apart.
  'storage/:tab': ({ tab }) => `${(STORAGE_TABS[tab] ?? STORAGE_TABS.cards)()} · ${__('Storage', 'eleva-crm-for-photographers')}`,
  'storage/cards/new': () => __('New Card', 'eleva-crm-for-photographers'), 'storage/cards/:id': () => __('Edit Card', 'eleva-crm-for-photographers'),
  'storage/backups/new': () => __('New Backup Location', 'eleva-crm-for-photographers'), 'storage/backups/:id': () => __('Edit Backup Location', 'eleva-crm-for-photographers'),
  'calendar': () => __('Calendar', 'eleva-crm-for-photographers'), 'changelog': () => __('Changelog', 'eleva-crm-for-photographers'), 'settings': () => __('Settings', 'eleva-crm-for-photographers'), 'restore': () => __('Restore from a backup', 'eleva-crm-for-photographers'), 'trash': () => __('Bin', 'eleva-crm-for-photographers'),
  'quotes': () => __('Quotes', 'eleva-crm-for-photographers'), 'quotes/new': () => __('New Quote', 'eleva-crm-for-photographers'), 'quotes/:id': () => __('Edit Quote', 'eleva-crm-for-photographers'), 'quote-settings': () => __('Quote Settings', 'eleva-crm-for-photographers'),
  'kanban': () => __('Kanban', 'eleva-crm-for-photographers'), 'reminders': () => __('Reminders', 'eleva-crm-for-photographers'),
  'analytics': () => __('Analytics', 'eleva-crm-for-photographers'), 'analytics-compare': () => __('Compare Periods', 'eleva-crm-for-photographers'),
  'collaborators': () => __('Collaborators', 'eleva-crm-for-photographers'), 'collaborators/new': () => __('New Collaborator', 'eleva-crm-for-photographers'), 'collaborators/:id': () => __('Edit Collaborator', 'eleva-crm-for-photographers'),
  'products': () => __('Products', 'eleva-crm-for-photographers'), 'products/new': () => __('New Product', 'eleva-crm-for-photographers'), 'products/:id': () => __('Edit Product', 'eleva-crm-for-photographers'),
  'suppliers': () => __('Suppliers', 'eleva-crm-for-photographers'), 'suppliers/new': () => __('New Supplier', 'eleva-crm-for-photographers'), 'suppliers/:id': () => __('Edit Supplier', 'eleva-crm-for-photographers'),
  'expenses': () => __('Expenses', 'eleva-crm-for-photographers'), 'expenses/new': () => __('New Expense', 'eleva-crm-for-photographers'), 'expenses/:id': () => __('Edit Expense', 'eleva-crm-for-photographers'),
}

function withTitles(routes) {
  return routes.map((route) => (ROUTE_TITLES[route.path] ? { ...route, handle: { title: ROUTE_TITLES[route.path] } } : route))
}

export function createRouter() { return createHashRouter([
  {
    path: '/',
    element: <Layout />,
    children: withTitles([
      { index: true, element: <Navigate to="/dashboard" replace /> },
      { path: 'dashboard',       element: <Lazy component={Dashboard} /> },
      { path: 'customers',       element: <Lazy component={CustomerList} /> },
      { path: 'customers/new',   element: <Lazy component={CustomerForm} /> },
      { path: 'customers/:id',   element: <Lazy component={CustomerForm} /> },
      { path: 'services',        element: <Lazy component={ServiceList} /> },
      { path: 'services/new',    element: <Lazy component={ServiceForm} /> },
      { path: 'services/:id',    element: <Lazy component={ServiceForm} /> },
      { path: 'works',             element: <Lazy component={WorkList} /> },
      { path: 'works/new',         element: <Lazy component={WorkForm} /> },
      { path: 'works/:id',         element: <Lazy component={WorkForm} /> },
      // Storage: three tabs (cards, backup locations, shots history) and the detail forms of the first two.
      { path: 'storage',               element: <Navigate to="/storage/cards" replace /> },
      { path: 'storage/:tab',          element: <Lazy component={StoragePage} /> },
      { path: 'storage/cards/new',     element: <Lazy component={MemoryCardForm} /> },
      { path: 'storage/cards/:id',     element: <Lazy component={MemoryCardForm} /> },
      { path: 'storage/backups/new',   element: <Lazy component={BackupLocationForm} /> },
      { path: 'storage/backups/:id',   element: <Lazy component={BackupLocationForm} /> },
      // The memory cards moved here in 1.6.6: old bookmarks keep working.
      { path: 'memory-cards',          element: <Navigate to="/storage/cards" replace /> },
      { path: 'memory-cards/new',      element: <Navigate to="/storage/cards/new" replace /> },
      { path: 'memory-cards/:id',      element: <MemoryCardRedirect /> },
      { path: 'calendar',          element: <Lazy component={CalendarView} /> },
      { path: 'changelog',         element: <Lazy component={ChangelogPage} /> },
      { path: 'settings',          element: <Lazy component={SettingsPage} /> },
      { path: 'restore',           element: <Lazy component={RestoreWizard} /> },
      { path: 'trash',             element: <Lazy component={TrashPage} /> },
      // Pro routes — only active when FotonicPro bundle is loaded
      ...(window.FotonicApp?.features?.quotes && window.FotonicProComponents?.QuoteList ? [
        {
          path: 'quotes',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.QuoteList />
            </Suspense>
          ),
        },
        {
          path: 'quotes/new',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.QuoteForm />
            </Suspense>
          ),
        },
        {
          path: 'quotes/:id',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.QuoteForm />
            </Suspense>
          ),
        },
        // Deliberately NOT under `quotes/`: `quotes/:id` would swallow it (same trap the
        // Calendar route fell into). The sidebar keeps "Quotes" highlighted via `alsoActive`.
        ...(window.FotonicProComponents?.QuoteSettingsPage ? [{
          path: 'quote-settings',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.QuoteSettingsPage />
            </Suspense>
          ),
        }] : []),
      ] : []),
      ...(window.FotonicApp?.features?.kanban && window.FotonicProComponents?.KanbanBoard ? [{
        path: 'kanban',
        element: (
          <Suspense fallback={<Fallback />}>
            <window.FotonicProComponents.KanbanBoard />
          </Suspense>
        )
      }] : []),
      ...(window.FotonicApp?.features?.kanban && window.FotonicProComponents?.RemindersList ? [{
        path: 'reminders',
        element: (
          <Suspense fallback={<Fallback />}>
            <window.FotonicProComponents.RemindersList />
          </Suspense>
        )
      }] : []),
      ...(window.FotonicApp?.features?.analytics && window.FotonicProComponents?.Analytics ? [{
        path: 'analytics',
        element: (
          <Suspense fallback={<Fallback />}>
            <window.FotonicProComponents.Analytics />
          </Suspense>
        )
      }] : []),
      ...(window.FotonicApp?.features?.analytics && window.FotonicProComponents?.AnalyticsCompare ? [{
        path: 'analytics-compare',
        element: (
          <Suspense fallback={<Fallback />}>
            <window.FotonicProComponents.AnalyticsCompare />
          </Suspense>
        )
      }] : []),
      ...(window.FotonicApp?.features?.collaborators && window.FotonicProComponents?.CollaboratorList ? [
        {
          path: 'collaborators',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.CollaboratorList />
            </Suspense>
          ),
        },
        {
          path: 'collaborators/new',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.CollaboratorForm />
            </Suspense>
          ),
        },
        {
          path: 'collaborators/:id',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.CollaboratorForm />
            </Suspense>
          ),
        },
      ] : []),
      ...(window.FotonicApp?.features?.products && window.FotonicProComponents?.ProductList ? [
        {
          path: 'products',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.ProductList />
            </Suspense>
          ),
        },
        {
          path: 'products/new',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.ProductForm />
            </Suspense>
          ),
        },
        {
          path: 'products/:id',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.ProductForm />
            </Suspense>
          ),
        },
      ] : []),
      ...(window.FotonicApp?.features?.suppliers && window.FotonicProComponents?.SupplierList ? [
        {
          path: 'suppliers',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.SupplierList />
            </Suspense>
          ),
        },
        {
          path: 'suppliers/new',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.SupplierForm />
            </Suspense>
          ),
        },
        {
          path: 'suppliers/:id',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.SupplierForm />
            </Suspense>
          ),
        },
      ] : []),
      ...(window.FotonicApp?.features?.expenses && window.FotonicProComponents?.ExpenseList ? [
        {
          path: 'expenses',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.ExpenseList />
            </Suspense>
          ),
        },
        {
          path: 'expenses/new',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.ExpenseForm />
            </Suspense>
          ),
        },
        {
          path: 'expenses/:id',
          element: (
            <Suspense fallback={<Fallback />}>
              <window.FotonicProComponents.ExpenseForm />
            </Suspense>
          ),
        },
      ] : []),
    ]),
  },
]) }
