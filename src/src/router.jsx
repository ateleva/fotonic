import { lazy, Suspense } from 'react'
import { createHashRouter, Navigate } from 'react-router-dom'
import Layout from './components/Layout'
import Spinner from './components/Spinner'

const Dashboard    = lazy(() => import('./features/dashboard/Dashboard'))
const CustomerList = lazy(() => import('./features/customers/CustomerList'))
const CustomerForm = lazy(() => import('./features/customers/CustomerForm'))
const ServiceList  = lazy(() => import('./features/services/ServiceList'))
const ServiceForm  = lazy(() => import('./features/services/ServiceForm'))
const WorkList     = lazy(() => import('./features/works/WorkList'))
const WorkForm     = lazy(() => import('./features/works/WorkForm'))
const SettingsPage = lazy(() => import('./features/settings/SettingsPage'))

function Fallback() {
  return (
    <div className="flex items-center justify-center h-48">
      <Spinner />
    </div>
  )
}

function Lazy({ component: Component }) {
  return (
    <Suspense fallback={<Fallback />}>
      <Component />
    </Suspense>
  )
}

export function createRouter() { return createHashRouter([
  {
    path: '/',
    element: <Layout />,
    children: [
      { index: true, element: <Navigate to="/dashboard" replace /> },
      { path: 'dashboard',       element: <Lazy component={Dashboard} /> },
      { path: 'customers',       element: <Lazy component={CustomerList} /> },
      { path: 'customers/new',   element: <Lazy component={CustomerForm} /> },
      { path: 'customers/:id',   element: <Lazy component={CustomerForm} /> },
      { path: 'services',        element: <Lazy component={ServiceList} /> },
      { path: 'services/new',    element: <Lazy component={ServiceForm} /> },
      { path: 'services/:id',    element: <Lazy component={ServiceForm} /> },
      { path: 'works',           element: <Lazy component={WorkList} /> },
      { path: 'works/new',       element: <Lazy component={WorkForm} /> },
      { path: 'works/:id',       element: <Lazy component={WorkForm} /> },
      { path: 'settings',        element: <Lazy component={SettingsPage} /> },
      // Pro routes — only active when FotonicPro bundle is loaded
      ...(window.FotonicApp?.features?.kanban && window.FotonicProComponents?.KanbanBoard ? [{
        path: 'kanban',
        element: (
          <Suspense fallback={<Fallback />}>
            <window.FotonicProComponents.KanbanBoard />
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
    ],
  },
]) }
