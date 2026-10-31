import React from 'react'
import { renderToString } from 'react-dom/server'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { VaultProvider } from './src/context/VaultContext'
import EncryptExistingScreen from './src/features/vault/EncryptExistingScreen'
import EncryptPausedBar from './src/features/vault/EncryptPausedBar'
import VaultChoiceScreen from './src/features/vault/VaultChoiceScreen'
import FloatingProgress from './src/components/FloatingProgress'

const qc = new QueryClient()
const wrap = (el) => renderToString(<QueryClientProvider client={qc}><VaultProvider>{el}</VaultProvider></QueryClientProvider>)
let fail = 0
const check = (label, html, mustHave) => { const ok = mustHave.every((s) => html.includes(s)); if (!ok) { fail++; console.log('FAIL', label, mustHave.filter((s) => !html.includes(s))) } else console.log('  ok  ', label) }

check('choice screen', wrap(<VaultChoiceScreen />), ['Do you want to protect personal data', 'Not now', 'Protect personal data with the Vault (recommended)', 'What stays readable'])
const enc = (o) => ({ status: 'pending', phase: 'scan', percent: 0, eta_seconds: null, to_encrypt: 0, records: 0, done: 0, error: '', ...o })
check('encrypt intro (pending)', wrap(<EncryptExistingScreen encrypt={enc({})} onDismiss={() => {}} />), ['Start encryption', 'Close any other tab'])
const running = wrap(<EncryptExistingScreen encrypt={enc({ status: 'running', phase: 'encrypt', percent: 55, to_encrypt: 12, records: 40, eta_seconds: 130 })} onDismiss={() => {}} />)
check('encrypt running', running, ['Encrypting your data', 'Reading your data', '12 of 40 records', '55%', 'About 3 min left.', 'Pause'])
check('encrypt error self test', wrap(<EncryptExistingScreen encrypt={enc({ status: 'error', phase: 'prepare', error: 'self_test_failed' })} onDismiss={() => {}} />), ['could not encrypt a test text', 'Try again', 'Cancel and continue later'])
check('encrypt error incomplete', wrap(<EncryptExistingScreen encrypt={enc({ status: 'error', phase: 'analyze', error: 'incomplete' })} onDismiss={() => {}} />), ['Some data could not be encrypted', 'Cancel and continue later'])
check('paused bar', wrap(<EncryptPausedBar encrypt={enc({ status: 'paused', phase: 'encrypt', percent: 40 })} />), ['Encryption paused', '40%', 'Resume'])
check('floating bar fixed position classes', renderToString(<FloatingProgress title="t" percent={120} />), ['fixed bottom-6 right-6', '100%'])
console.log(fail ? fail + ' failure(s)' : 'all passed')
process.exit(fail ? 1 : 0)
