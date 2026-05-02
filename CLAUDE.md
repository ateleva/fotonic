# Fotonic — Claude Code Context
Full spec: `/Users/alessandro/Local Sites/fotonic/Analisi Strutturale - Gestionale Fotografi.md`

## One-liner
Freemium WP plugin CRM for event photographers. React SPA in WP Admin.
Free CPTs: `ftnc_customer` `ftnc_service` `ftnc_work` · Pro CPTs in fotonic-pro/ repo.

## Paths
- Plugin root: `.../plugins/fotonic/` (this repo)
- Pro addon: `.../plugins/fotonic-pro/` (separate private repo, adjacent)
- React source: `src/src/` (Vite project nested inside src/)
- Compiled output: `dist/fotonic-app.{js,css}` (committed to git)
- Local WP admin: `http://fotonic.local/wp-admin`

## PHP Rules
- PHP 7.4–8.3 compat — no named args, no match expr, no union property types
- No ACF, no WooCommerce, no third-party meta box libs
- REST namespace: `fotonic/v1` · Nonce header: `X-WP-Nonce`
- All classes: `Fotonic_` prefix · one file per class · `class-{slug}.php`
- Pro active check: `defined('FOTO_PRO_VERSION')`

## i18n Rules
- Text domain: `fotonic` · Languages dir: `languages/`
- ALL PHP user-facing strings: `__()` / `_e()` / `esc_html__()` / `esc_attr__()`
- React strings: `import { __ } from '@wordpress/i18n'` — external, mapped to `wp.i18n` global
- `wp_set_script_translations('fotonic-app-js', 'fotonic', FOTONIC_DIR . 'languages')` in class-admin-page.php
- Generate JS JSON translations: `wp i18n make-json languages/ --no-purge`
- Compile .mo files: `msgfmt languages/fotonic-it_IT.po -o languages/fotonic-it_IT.mo`
- Dev language: English · Always include Italian (it_IT)

## React Conventions
- API hooks in `src/src/api/` via TanStack Query
- Auth: `X-WP-Nonce: window.FotonicApp.nonce`
- Base REST URL: `window.FotonicApp.restUrl`
- Feature flags: `window.FotonicApp.features.{kanban|collaborators|analytics|notifications}`
- Pro routes: `lazy()` + `Suspense`, guard with `window.FotonicApp.isPro`
- State: Zustand (UI state) · TanStack Query (server state)
- Tailwind: `preflight: false` · `important: '#fotonic-app-root'`

## Security (Vault)
- PII encrypted AES-256 via `Fotonic_Crypto`
- Deterministic encryption for searchable fields (email, phone) only
- Session key in HTTP-Only cookie — never in DB or JS
- Files served via `/wp-json/fotonic/v1/vault-download/{id}` only
- Upload vault dir: `wp-content/uploads/fotonic/vault/` with `Require all denied`

## Daily Workflow
```
cd plugins/fotonic/src && npm run watch   # auto-rebuilds dist/ on save
# edit PHP in includes/ or React in src/src/
# before commit: npm run build (clean minified build)
```

## Gotchas
- `__dirname` not in ESM vite.config — use `fileURLToPath(import.meta.url)` pattern
- React source in `src/src/` (nested) — NOT `src/`
- `dist/` IS committed — don't gitignore it
- `enqueue_assets` fires on all admin pages — always check `$hook !== 'toplevel_page_fotonic'`
- Pro Vite build: lib/iife mode + React as external global
- `work_payment_status` taxonomy is auto-assigned by plugin logic only
