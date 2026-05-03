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

export const router = createHashRouter([
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
    ],
  },
])
