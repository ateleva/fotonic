import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import { defineConfig, globalIgnores } from 'eslint/config'

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{js,jsx}'],
    extends: [
      js.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],
    languageOptions: {
      globals: globals.browser,
      parserOptions: { ecmaFeatures: { jsx: true } },
    },
    rules: {
      // Leading underscore marks a deliberately-unused param — needed for
      // the __ wrapper's second parameter below, which exists only so the
      // signature documents the required 2-argument calling convention
      // (see the no-restricted-syntax rule right below).
      // Note for anyone editing this file: avoid writing a literal
      // quoted-string call to __ in comments/messages near this file —
      // the i18n extraction pipeline scans every .js file in the plugin
      // tree and does not distinguish real calls from example text.
      'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      // The local __ wrapper (utils/i18n.js) hardcodes the textdomain
      // internally, so calling it with just the string argument runs fine
      // at runtime — but the wp-code-translate extraction pipeline
      // requires the literal two-argument form (string, textdomain) to
      // find the string at all. A single-argument call is silently
      // invisible to every POT/PO/JSON generated from source. Fotonic Pro
      // regressed 517 calls across 19 files this way in Aug 2026 — this
      // guard exists so free never drifts the same way.
      'no-restricted-syntax': ['error', {
        selector: "CallExpression[callee.name='__'][arguments.length<2]",
        message: 'The __ wrapper needs the textdomain as a second argument, or the string is never extracted for translation.',
      }],
    },
  },
])
