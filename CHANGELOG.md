# Changelog

## [1.3.6] — 2026-06-05

### Changed

- **Distribution / WP.org guideline 4 (human-readable source)**: the full non-compiled React/Vite source now ships inside the plugin under `src/`, alongside the compiled `dist/` bundle it produces. `.distignore` was updated to include `src/` and exclude only `node_modules`, `.vite`, and `dist`. The same source also remains publicly maintained at the GitHub repository. Both guideline-4 options (source in the deployed plugin **and** a public readme link) are now satisfied.
- **Compatibility**: Tested up to WordPress 7.0.

### Docs

- Expanded the readme `== Development ==` section with build-tool instructions (Node.js 22, npm, Vite) and the source→output mapping.
- Replaced the generic Vite-template `src/README.md` with a real build guide.

### Removed

- Unreferenced Vite template assets (`react.svg`, `vite.svg`, `hero.png`) from the source tree.

---

## [1.3.5] — 2026-05-30

### Changed

- **WP.org compliance**: plugin renamed to "Eleva CRM for Photographers"; slug changed to `eleva-crm-for-photographers`.
- **WP.org compliance (trialware)**: removed all Pro-gated code blocks (Work Owner, Collaborators, Taxable Price) from the free plugin — no locked features remain. Pro features are injected at runtime by the separate private addon via `window.FotonicProComponents`.
- **WP.org compliance**: converted inline `<script>` / `<style>` tags in meta boxes to `wp_add_inline_script()` / `wp_add_inline_style()`.
- **Security**: added REST nonce verification (`wp_verify_nonce()`) to the vault file download permission callback.
- **Admin menu**: position changed from `25` to `null` (auto) to avoid conflicting with core menu items.

---

## [1.3.4] — 2026-05-28

### Security

- **Vault file download IDOR hardening**: `GET /vault-download/{id}` ownership check rewritten. Previously the endpoint used three LIKE patterns against the `_ftnc_work_files` JSON column, which only matched IDs in the first or last position of the array and missed mid-array entries. The new check pulls the candidate rows with a coarse LIKE then `json_decode`s and compares integers exactly — robust regardless of position, whitespace, or future format changes.
- **PBKDF2 iterations raised 100k → 600k** in `Fotonic_Crypto::derive_key()`. Matches OWASP 2023 guidance for PBKDF2-HMAC-SHA256. Constant `PBKDF2_ITERATIONS` introduced for future bumps. Existing vaults continue to unlock; the new iteration count is applied transparently because `derive_key()` always re-runs PBKDF2 from password + salt.
- **Customer search SQL scope**: `posts_search` and `posts_join` filters for `ftnc_customer` now join an aliased `postmeta` row constrained to `meta_key = '_ftnc_people'` and the OR-clause matches against that alias. Previously the JOIN spanned every postmeta row for the matched posts.

### Changed

- **Admin notice handling**: dropped the `remove_all_actions()` calls on `admin_notices`, `all_admin_notices`, `network_admin_notices`, and `user_admin_notices` on the Fotonic SPA page. Security and update warnings still fire (so administrators are not blinded to important messages); they are only hidden visually inside the SPA viewport via the existing scoped CSS rule. This aligns with WordPress.org guideline 11 (no admin hijacking).
- **Activator OpenSSL guard**: corrected `deactivate_plugins()` argument from `plugin_basename(__FILE__)` (which resolved to the activator file path, not the main plugin file) to the canonical `'fotonic/fotonic.php'` so the main plugin is properly disabled when PHP OpenSSL is unavailable.
- **Menu icon SVG caching**: `Fotonic_Admin_Page::add_menu()` now reads the icon SVG once per request and caches the resulting data URI in a static property, avoiding redundant filesystem reads.

### i18n

- Added `/* translators: */` comments to placeholder-bearing strings (`Me (%s)`, `Linked to %1$d work(s): %2$s`) so translators get correct positional context. The `_n()` call now uses positional `%1$d` / `%2$s` arguments.

---

## [1.3.3] — 2026-05-27

### Added
- **Customer Works recap**: the Customer edit page in the React SPA now shows a table of all linked works below the customer form. Columns: title (link to work), date, services (comma-separated), total price, payment status badge. Footer row shows work count, sum of total price, sum paid (from installments), and sum unpaid.
- **Taxable Price field on Work** (Pro-gated): new `Taxable Price (€)` number input displayed next to Total Price in the Work form when Fotonic Pro is active. Stored as `_ftnc_total_price_taxable` post meta. Hidden when Pro is not installed.
- **`customer_id` filter on GET `/works`**: REST endpoint now accepts a `customer_id` query parameter to return only works linked to a specific customer. Used internally by the customer works recap.

### i18n

- Italian translations added for customer works recap labels (Works, Date, Services, Total Price, Payment Status, Paid, Partial, Unpaid, No works yet., Total works:, Paid:, Unpaid:) and Taxable Price (€) / Imponibile (€).

---

## [1.3.2] — 2026-05-26

### Added
- **Calendar view**: monthly calendar is now a free feature — available to all users without a Pro license. Only Google Calendar sync remains Pro-only.
- **Vault description**: Settings page now shows explanatory text in the Vault section — a detailed explanation when the vault has not yet been configured, and a short reminder when it is active.
- **Fotonic logotype**: React SPA sidebar now shows the Fotonic SVG logotype instead of a plain text label.
- **WP admin icon**: Fotonic menu entry in the WordPress admin sidebar now uses the custom Fotonic logo mark SVG.
- **GitHub README**: plugin logo (icon-256x256.png) shown above the title on the repository page.

### Changed
- Calendar month names and event date labels now respect the WordPress site language (derived from `window.FotonicApp.locale`) instead of the browser/OS locale.
- WP admin footer (`#wpfooter`) is hidden on the Fotonic admin page; `#wpbody-content` bottom padding removed so the React SPA fills the viewport without overflow.

### Fixed
- Work Owner and Collaborators fields no longer appear in the `ftnc_work` classic editor or React form when Fotonic Pro is not installed or the license is not valid. Calendar Color remains visible to all users.
- Settings sidebar: active nav item now has correct right-side padding and full border-radius (was clipped on the right edge).
- Works list: payment status filter dropdown now sizes to the widest option instead of clipping the "All Statuses" label.
- REST `/collaborator-options`: returns an empty collaborators array when Fotonic Pro is inactive, preventing a PHP error in the free plugin.

---

## [1.3.1] — 2026-05-26

### Security
- **VULN-S01 (HIGH)**: Vault session cookie upgraded from AES-256-CBC to AES-256-GCM. CBC had no MAC — a tampered cookie would decrypt to garbage silently. GCM rejects any tampered ciphertext via the 16-byte authentication tag. New cookie format: `base64(nonce[12] || auth-tag[16] || CT[32])`. (`class-vault.php`)
- **VULN-S02 (HIGH)**: Deterministic encryption for searchable fields (email, phone) used the same IV for every value encrypted with the same key — a per-value IV was never applied. IV is now `hash_hmac('sha256', $value, $key, true)[0:16]`, unique per (key, value) pair. (`class-crypto.php`)
- **VULN-S03 (HIGH)**: `deterministic_encrypt()` output format changed from bare `base64($ciphertext)` (no IV embedded) to `v1d:base64(HMAC_IV[16] || CT)`. The old format was undecodable — `decrypt()` would interpret the first block of ciphertext as the IV and return garbage, causing email and phone fields to always display empty. Added `deterministic_decrypt()` to handle the `v1d:` prefix. (`class-crypto.php`)
- **VULN-S04 (HIGH)**: Browser-side `deterministicEncrypt()` (webcrypto.js) derived IV from `SHA-256(rawKey)` only — the same 12-byte GCM nonce for every value encrypted in a session. IV now derived from `SHA-256(rawKey || valueBytes)`, unique per (key, value). (`src/src/lib/webcrypto.js`)
- **VULN-S05 (MEDIUM)**: REST API permission callback `admin_permission()` checked `current_user_can('manage_options')` but did not explicitly verify the WP REST nonce. Added `wp_verify_nonce()` call. (`class-rest-api.php`)
- **VULN-S06 (MEDIUM)**: Vault file download IDOR — `GET /vault-download/{id}` used `LIKE %{id}%` which matched ID `1` inside arrays `[10, 11]`. Replaced with three-query exact JSON-token matching: trailing-quote match, start-of-array match, single-element match. (`class-rest-api.php`)
- **VULN-S07 (MEDIUM)**: No audit trail for vault events. Added private `audit_log()` helper; wired to `vault_unlock_ok`, `vault_unlock_fail`, `vault_lock`, `vault_password_changed` — each entry records user ID, IP, and event to the WP error log. (`class-rest-api.php`)
- **VULN-S08 (MEDIUM)**: `maybe_decrypt()` routed all ciphertext through `Fotonic_Crypto::decrypt()`, mishandling `v1d:`-prefixed deterministic fields. `looks_encrypted()` now fast-paths `v1d:` and `v2:` prefixes. `maybe_decrypt()` routes `v1d:` to `deterministic_decrypt()`. `reencrypt_customers()` email/phone block uses `deterministic_decrypt()` for old-key reads. (`class-rest-api.php`)
- Price overrides and installment amounts clamped to `max(0.0, value)` — prevents negative price injection via REST. (`class-rest-api.php`)
- Vault password minimum length (12 characters) enforced server-side on setup and change-password. (`class-rest-api.php`)

### Added
- `uninstall.php`: complete data cleanup on plugin deletion — removes all CPT posts, postmeta, taxonomy terms, options, transients, and vault upload directory. Required for WordPress.org approval. (`uninstall.php`)
- `dateFormat` and `timeFormat` (from WP Settings › General) exposed to JS via `window.FotonicApp` for locale-aware date/time formatting.
- `analytics-compare` route registered in free plugin router, activated when Pro `AnalyticsCompare` component is present.

### Changed
- `utils/date.js` fully rewritten: PHP format string → date-fns converter (`phpToDateFns`) with locale-based fallbacks (`it` → `dd/MM/yyyy`; `en_GB` → `dd/MM/yyyy`; default → `MM/dd/yyyy`). Exports `formatDate`, `formatTime`, `formatDateTime`.

### CI / Distribution
- `deploy.yml`: workflow-level `permissions: {}` deny-all; build job overrides with `contents: read` only.
- `.distignore`: excludes `CLAUDE.md`, `README.md`, `CHANGELOG.md`, `SUBMISSION-GUIDE.md`, `*.po~` from SVN deploy ZIP.

---

## [1.3.0] — 2026-05-21

### Security
- **VULN-801**: `vault/setup` REST endpoint now returns `409 Conflict` if the vault is already initialised. Previously any `manage_options` user could re-POST to the endpoint at any time, generating a new key and making all existing encrypted PII permanently unreadable.
- **VULN-810**: Vault cookie server-secret fallback replaced. Was `get_site_url() . $wpdb->prefix` (publicly guessable). Now a 64-character random key generated once and persisted as a WordPress option.
- **VULN-007**: `can_save()` in `class-meta-boxes.php` upgraded from `edit_post` to `manage_options` capability check, matching the REST API auth model and preventing lower-privilege users from saving Fotonic meta boxes.

### Changed
- `wp_enqueue_script` updated from boolean `true` to array-style `[ 'in_footer' => true ]` (WordPress 6.3+ deprecation).
- Plugin header: `Tested up to: 7.0`.

### CI
- `.github/workflows/deploy.yml`: build artifact verification step added — release aborted if `dist/fotonic-app.js` or `dist/fotonic-app.css` are missing after the build step.

### i18n
- Translation strings refreshed (PHP and JavaScript).

## [1.2.2] — 2026-05-19

### Added
- **Custom payment types**: CRUD REST API (`GET/POST /payment-types`, `PUT/DELETE /payment-types/:id`) backed by a `fotonic_payment_types` wp_option. Defaults seed `Payment` and `Discount` types on first use.
- **Payment Type Manager**: UI in the Works list to create, rename, and delete custom installment types.
- Installment type selector in WorkForm is now a `<select>` dropdown populated from custom payment types, replacing the hardcoded Default/Coupon toggle.

### Changed
- **WP admin theme color**: sidebar nav active background and CTA button accent now read actual computed colors from the WP admin DOM at runtime via an inline script (reads `#adminmenu .current > a` for `--fotonic-nav` and a hidden `.wp-core-ui .button-primary` for `--fotonic-primary`). Works for all built-in and custom admin color schemes without hardcoding.
- Sidebar nav: all nav items (free + Pro) now use `nav-link-active` CSS class for active state; Pro items no longer render hardcoded `bg-indigo-50 text-indigo-700`.
- Payment status badge labels (Paid / Partial / Unpaid) moved inside component render scope so they are correctly translated via `__()`.

### Fixed
- Secondary button border disappeared after WP admin bleed-through reset. Fixed by adding `border-solid` to the Tailwind variant class (`border border-solid border-gray-200`).
- `focus:outline-none` removed from `Button` base classes — keyboard users now see a proper focus ring on all interactive elements.
- `cursor-pointer` added to `Button` base classes to restore pointer cursor after the `appearance: none` reset.

## [1.2.1] — 2026-05-18

### Changed
- **Consistent button design system**: all buttons now use three semantic variants — `primary` (WP admin theme color, adapts to user's chosen color scheme), `secondary` (white + gray border), `danger` (red). No more shadows, gradients, or mixed inline styles.
- **WP admin theme color integration**: primary buttons pick up `--wp-admin-theme-color` CSS variable automatically, so the plugin matches whatever admin color scheme the user selects in their WP profile.
- Button reset (`appearance: none`, `box-shadow: none`, `outline: none` on focus/active) prevents WP admin CSS bleed-through on all button elements inside `#fotonic-app-root`.
- **Full-width forms**: removed `max-w-*` constraints from WorkForm, CustomerForm, ServiceForm, and SettingsPage — all forms now use full available width.
- Trash/remove buttons in PeopleRepeater, ServicesRepeater, and CollaboratorsRepeater are now solid danger buttons (red background, white icon/text).
- Dashboard work-title links reset to plain link style (no WP admin button border bleed).
- Vault lock button neutral gray instead of amber.
- Analytics toolbar: Apply, Export CSV, Export PDF buttons aligned to same height (34px) as date inputs.

## [1.2.0] — 2026-05-14

### Added
- Collaborators repeater on Work edit form: assign collaborators to a work with individual price and payment status per entry.
- Owner dropdown on Work edit form: select yourself (admin) or any collaborator as the work owner.
- New REST endpoint `GET /collaborator-options`: returns admin user and all collaborators with their service terms, used by the owner/collaborator dropdowns in WorkForm.
- Work REST API now saves `collaborators[]` field (id, services, price, status, type) and full owner type/id assignment.
- Collaborator Services nav item in sidebar (Pro only, when collaborators feature is enabled).
- Layout: sticky shell with dynamic height calculation to fill the viewport correctly.

### Fixed
- Sidebar layout no longer overflows viewport; main content scrolls independently.

## [1.1.0] — 2026-05-12

### Added
- Full i18n/l10n support: all React UI strings wrapped in gettext `__()` functions
- Italian (it_IT) translations for all strings — PHP and JavaScript
- Regenerated POT template covering 100% of translatable strings (PHP + JSX)
- JSON translation file (`fotonic-it_IT-fotonic-app-js.json`) for React components

### Fixed
- Calendar event display and date-handling edge cases
- Layout nav labels now translated correctly (moved arrays inside component render scope)

## [1.0.0] — 2026-05-02

- Initial release
