import * as React from 'react'
import * as ReactDOMFull from 'react-dom'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import * as ReactQuery from '@tanstack/react-query'
import * as ReactRouter from 'react-router-dom'
import { QueryClient, QueryClientProvider, MutationCache } from '@tanstack/react-query'
import { createRouter } from './router'
import VaultGate from './features/vault/VaultGate'
import { VaultProvider } from './context/VaultContext'
import './index.css'

// Pin globals to THIS bundle's copies so fotonic-pro.js (which externalizes react/react-dom)
// picks up the same instances and hooks work across the boundary.
window.React = React
window.ReactDOM = ReactDOMFull
window.FotonicVendors = { ReactQuery, ReactRouter }

// Any write may have put something in the Bin (a delete) or taken it out: the small Bin buttons of the lists read
// ['trash-summary'], so it is refreshed after every successful write. Only mounted observers refetch, so it costs
// one tiny request on a page that shows a Bin button and nothing elsewhere.
const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 30_000, retry: 1 } },
  mutationCache: new MutationCache({
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['trash-summary'] }) },
  }),
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
