import * as React from 'react'
import * as ReactDOMFull from 'react-dom'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import * as ReactQuery from '@tanstack/react-query'
import * as ReactRouter from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createRouter } from './router'
import VaultGate from './features/vault/VaultGate'
import { VaultProvider } from './context/VaultContext'
import './index.css'

// Pin globals to THIS bundle's copies so fotonic-pro.js (which externalizes react/react-dom)
// picks up the same instances and hooks work across the boundary.
window.React = React
window.ReactDOM = ReactDOMFull
window.FotonicVendors = { ReactQuery, ReactRouter }

const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 30_000, retry: 1 } }
})

// Defer mount to next macrotask so all sibling module scripts (fotonic-pro.js) run first
// and register window.FotonicProComponents before the router is created.
// (Microtasks fire between modules; setTimeout fires after all modules execute.)
setTimeout(() => {
  const router = createRouter()
  createRoot(document.getElementById('fotonic-app-root')).render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <VaultProvider>
          <VaultGate router={router} />
        </VaultProvider>
      </QueryClientProvider>
    </StrictMode>
  )
})
