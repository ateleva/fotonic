=== Eleva CRM for Photographers ===
Contributors: eleva
Tags: photography, crm, workflow, photographers, event-photography
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.3.6
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

CRM and workflow manager for professional event photographers.

== Description ==

Eleva is a standalone WordPress plugin that provides a modern React-powered CRM dashboard for professional event photographers, without any monthly subscription fees.

= Features =

* Customer Management: store couples and individuals with multiple contacts per client. Full backend search across all custom fields. Each customer page shows a linked Works table with totals (total price, paid, unpaid).
* Service Catalog: define your services and base prices. Override price and notes per project.
* Works / Projects: the central hub linking customers, services, attached files, and payment installments. Total Price and payment installments tracked per project.
* Payment Tracking: track deposits and balances. Automatic paid/partial/unpaid status assigned from installments.
* File Vault: attach contracts and files to any project. Protected storage with .htaccess and REST-gated downloads.
* Vault Security: AES-256 encryption protected by a master password and TOTP two-factor authentication. All personally identifiable data is encrypted at rest. Even direct database access reveals only ciphertext.
* Dashboard: summary cards showing annual revenue, upcoming events, and unpaid balances. Next five upcoming works.
* Quick Notes: a dedicated WYSIWYG notes field on each Work, positioned above the main notes editor, for short reminders visible at a glance.
* Calendar Color: choose an event card color (12-color palette) on each Work.
* Monthly Calendar: a full monthly calendar view showing all scheduled works as colored pills. Click any entry to see a detail popup with date, customer, payment status, and a link to the work.

= No third-party dependencies =

No ACF required. No WooCommerce required. Fully standalone. Uses only native WordPress APIs.

== Installation ==

1. Download the plugin ZIP from WordPress.org.
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin.
3. Upload the ZIP and click Install Now, then Activate.
4. Navigate to Eleva in the WP Admin sidebar.
5. On first launch, set your Vault master password and scan the QR code with an authenticator app (Google Authenticator, Authy, or any TOTP-compatible app).
6. Enter your Vault password and the current OTP code to unlock the CRM.

== Frequently Asked Questions ==

= Does this require ACF (Advanced Custom Fields)? =

No. Eleva uses only native WordPress meta boxes and registers all custom post types natively. No third-party field plugins are required or used.

= Does this work without WooCommerce? =

Yes. Eleva is completely standalone and has no dependency on WooCommerce or any other plugin.

= What is the Vault? =

The Vault is a built-in security layer. All personally identifiable information (customer names, emails, phone numbers, addresses, notes) is encrypted with AES-256 before being stored in the database. You set a Vault master password that is independent of your WordPress login, and you pair it with a TOTP authenticator app. Without your Vault master password, the database contains only unreadable ciphertext. The decryption key is never stored in the database; it lives only in a temporary, HTTP-Only, SameSite=Strict session cookie encrypted with AES-256-GCM (authenticated encryption — tampered cookies are rejected, not silently decrypted).

= Does the plugin work on shared hosting? =

Yes. It requires PHP 7.4 or higher and WordPress 6.0 or higher, both of which are standard on modern shared hosting environments.

= Where is my data stored? =

All data is stored exclusively in your own WordPress database (wp_posts and wp_postmeta tables). The plugin makes no external HTTP requests and no data is ever sent to external servers. You own and control your data entirely.

= Does the plugin send data anywhere? =

This plugin makes zero external HTTP requests. Your data never leaves your server.

== Privacy Policy ==

Eleva CRM for Photographers collects no personal data of any kind, makes no external HTTP requests, and transmits nothing to external servers. All data is stored in your own WordPress database.

== Development ==

The full, non-compiled source code (the React/Vite app that produces the files in `dist/`) is provided two ways:

1. Bundled inside this plugin under the `src/` directory.
2. Publicly maintained at https://github.com/ateleva/fotonic

The admin app is built with React 18, Vite, Zustand, TanStack Query, and Tailwind CSS. WordPress loads only the compiled output in `dist/` — the `src/` tree is the human-readable source it is built from.

Build tools: Node.js 22 and npm. To compile the bundle from source:

1. `cd src` (the Vite project root)
2. `npm install` (or `npm ci` for a reproducible build from `package-lock.json`)
3. `npm run build`

This runs Vite and writes the compiled assets to `dist/` — `fotonic-app.js` (app entry), `fotonic-app.css` (styles), and lazy-loaded `fotonic-chunk-*.js` route chunks. Use `npm run watch` to rebuild on save during development. See `src/README.md` for full details.

== Screenshots ==

1. Dashboard showing annual revenue, payments to receive, payment type breakdown, and upcoming works list
2. Customer list with search — name, main contact, email, and phone at a glance
3. Service catalog with base prices — add, edit, and reuse services across projects
4. Works list with payment status filter (All / Paid / Partial / Unpaid) and total price column
5. Works list filtered to "Partial" status, with custom Payment Types manager expanded
6. Work edit form — event details with date, time, and multiple location addresses
7. Work edit form — customer selector, calendar color picker, and services with per-work price override
8. Work edit form — payment section with installments, paid/unpaid status per installment, and running totals
9. Monthly calendar view showing scheduled works as colored event pills
10. Settings page — Vault section showing change-password and reset-authenticator-app panels
11. Vault unlock screen — master password and TOTP authenticator code required to access encrypted client data

== Changelog ==

= 1.3.6 =
* WP.org compliance: the full React/Vite source is now bundled in the plugin under `src/` (the compiled `dist/` bundle is retained for runtime). The same source also remains publicly maintained at the GitHub repository. This satisfies both human-readable-source options in guideline 4.
* Docs: expanded the Development section with build-tool instructions (Node.js 22, npm, Vite) and added a build guide at `src/README.md`.

= 1.3.5 =
* WP.org compliance: plugin renamed to "Eleva CRM for Photographers"; slug changed to eleva-crm-for-photographers.
* WP.org compliance: removed all Pro-gated code blocks (Work Owner, Collaborators, Taxable Price) from the free plugin. No locked features remain.
* WP.org compliance: converted inline script/style tags in meta boxes to wp_add_inline_script / wp_add_inline_style.
* Security: added REST nonce verification to vault file download permission callback.
* Source: added Development section to readme.txt linking public GitHub source repository.
* Admin menu position changed to auto (null) to avoid conflicting with core items.

= 1.3.4 =
* Security: vault file download (GET /vault-download/{id}) ownership check now decodes the JSON file-list server-side and compares integers exactly. Previous LIKE-pattern matching covered only IDs in the first or last position of the array and missed mid-array entries; the new check is exact regardless of position.
* Security: PBKDF2 iteration count for the vault key derivation raised from 100,000 to 600,000, aligning with OWASP 2023 guidance. Existing vaults remain unlockable; the new iteration count is applied to all derive_key() calls.
* Security: customer search SQL filter (posts_search + posts_join) is now scoped to the _ftnc_people meta key only. Previously the JOIN spanned all postmeta rows for matched posts, an unnecessary surface that could grow as new meta keys were added.
* WP.org compliance: removed the aggressive remove_all_actions() call on admin_notices hooks on the Fotonic SPA page. Admin notices still fire (so security/update warnings are not suppressed) and are only hidden visually inside the SPA viewport via scoped CSS.
* Reliability: activator OpenSSL-missing path now passes the correct plugin slug to deactivate_plugins() so the main plugin is disabled cleanly when the extension is unavailable.
* Performance: menu icon SVG is read from disk once per request and cached in a static property instead of being read on every add_menu() call.
* i18n: added translators comments to sprintf/_n strings carrying %s/%d placeholders so translators have correct context.

= 1.3.3 =
* Customer Works recap: the Customer edit page now shows a table of all linked works with title, date, services, total price, and payment status. Footer row shows work count, total price, paid total, and unpaid total.
* REST GET /works now accepts a customer_id query parameter to filter works by customer.
* i18n: Italian translations added for all new strings.

= 1.3.2 =
* Calendar view is now included: the monthly calendar showing all scheduled works is available to all users.
* Calendar locale fix: month names and event dates now display in the WordPress site language instead of the server/OS locale.
* Vault description: Settings page now shows an explanation of the Vault feature — detailed when not yet configured, a short reminder when active.
* UI: logo mark used as the WP Admin menu icon.
* UI: Settings sidebar nav item right-side clipping fixed.
* UI: Payment status filter dropdown in the Works list now sizes to full option text width.
* Layout: WP admin footer hidden on the plugin page; SPA viewport height correctly fills the available space.

= 1.3.1 =
* Security: vault session cookie upgraded from AES-256-CBC (unauthenticated) to AES-256-GCM. The GCM authentication tag means any tampered cookie is rejected outright rather than silently decrypted to garbage. Cookie format: base64(nonce[12] + auth-tag[16] + ciphertext[32]).
* Security: deterministic encryption for searchable fields (email, phone) fixed. IV is now derived per-value via HMAC-SHA256(key, value), so different field values produce different IVs. Previously all deterministic ciphertexts shared the same IV, creating a ciphertext relationship leak.
* Security: browser-side AES-GCM deterministic encryption (webcrypto.js) IV derivation fixed. IV now hashes key bytes concatenated with value bytes; previously only the key was hashed, producing the same IV for every value in a session.
* Security: deterministic ciphertext format changed from bare base64 to v1d:base64(IV||CT), making the format unambiguous and correctly round-trippable through decrypt(). Email and phone fields that were previously stored with the broken format would silently return empty — now they decrypt correctly.
* Security: vault REST permission callback now explicitly calls wp_verify_nonce() in addition to current_user_can(), closing a theoretical CSRF window on the REST API.
* Security: vault file download endpoint (GET /vault-download/{id}) fixed to use exact JSON-token matching. The previous LIKE %id% pattern allowed attachment ID 1 to match work file arrays containing IDs 10 or 11.
* Security: vault unlock, failed unlock, lock, and password-change events now write audit entries to the WordPress error log (user ID, remote IP, event name). Requires WP_DEBUG_LOG to be enabled.
* Security: re-encryption of email/phone fields during vault password change now uses deterministic decryption instead of standard decryption, ensuring those fields are correctly re-encrypted after a password change.
* Security: price_override and installment amounts are clamped to max(0.0, value), preventing negative price injection via the REST API.
* Security: vault password minimum length (12 characters) enforced server-side on both setup and change-password endpoints.
* CI: workflow-level permissions: {} deny-all added to deploy.yml; the build job overrides with contents: read only, reducing GITHUB_TOKEN blast radius.
* Maintenance: added uninstall.php for complete data cleanup (CPT posts, postmeta, taxonomy terms, options, transients, vault upload directory) on plugin deletion — required for WordPress.org approval.
* Distribution: excluded developer-only files (CLAUDE.md, README.md, CHANGELOG.md, SUBMISSION-GUIDE.md) from plugin ZIP via .distignore.

= 1.3.0 =
* Security: vault setup endpoint now returns 409 if the vault is already configured, preventing a privileged user from accidentally overwriting all encrypted PII.
* Security: vault cookie server-secret fallback replaced with a randomly generated 64-character key stored as a WordPress option, eliminating a guessable fallback based on site URL.
* Security: meta box save guard upgraded from edit_post to manage_options capability, matching the REST API authorization model.
* Compatibility: wp_enqueue_script updated to use array-style args (WordPress 6.3+); removes the deprecation notice from WP_DEBUG logs.
* Compatibility: Tested up to WordPress 7.0.
* CI: build artifact verification step added to the deploy workflow; the release is aborted if the compiled JS or CSS files are missing.
* i18n: translation strings refreshed.

= 1.2.2 =
* WP admin theme color integration: sidebar nav active state and CTA buttons now read actual computed colors from the WP admin DOM at runtime, adapting to all built-in and custom admin color schemes.
* Custom payment types: new CRUD REST API (GET/POST /payment-types, PUT/DELETE /payment-types/:id) lets administrators define installment types beyond the built-in Default/Coupon. UI manager in the Works list.
* Installment type selector: dropdown driven by custom payment types instead of a hardcoded toggle.
* Payment status badge labels (Paid / Partial / Unpaid) are now translatable via WordPress i18n.
* Secondary button border fix: added border-style: solid to prevent WP admin CSS bleed-through from hiding the border after the appearance reset.
* Accessibility: removed focus:outline-none from Button component; keyboard users now see a proper focus ring on all interactive elements.
* Sidebar nav: all nav items (free and Pro) use the themed active state class; Pro items no longer show hardcoded indigo on active.

= 1.2.1 =
* Consistent button design system: all buttons use three semantic variants — primary (WP admin theme color), secondary (white + gray border), danger (red). No more shadows, gradients, or mixed inline styles.
* WP admin theme color integration: primary buttons pick up --wp-admin-theme-color CSS variable automatically.
* Full-width forms: removed max-w-* constraints from WorkForm, CustomerForm, ServiceForm, and SettingsPage.

= 1.2.0 =
* Added REST endpoint GET /collaborator-options.
* Work REST API now saves collaborators[] and full owner type/id fields.
* Fixed: sticky layout shell with dynamic viewport height; sidebar and main content scroll independently.

= 1.1.0 =
* Added Quick Notes WYSIWYG field to Work edit screen, placed above the main notes editor.
* Added Calendar Color picker (12-color palette) to Work edit screen.
* Added `quick_notes` and `color` fields to the Works REST API.
* Full i18n/l10n support: all React UI strings wrapped in gettext functions; Italian (it_IT) translation covers 100% of PHP and JavaScript strings.
* Regenerated POT template; added JSON translation file for React components (`wp_set_script_translations`).

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.3.4 =
Security: vault download ownership check rewritten; PBKDF2 raised to 600k iterations. WP.org compliance: replaced admin-notice hook suppression with CSS-only hiding. See changelog for full details.

= 1.3.3 =
Adds customer works recap table and customer_id filter on the works REST endpoint. No breaking changes to existing data.

= 1.3.1 =
Security: vault cookie upgraded to AES-256-GCM; REST nonce enforcement tightened; IDOR in file downloads fixed. Added uninstall.php for complete data cleanup on plugin deletion.

= 1.3.0 =
Security hardening: vault setup guard, server-secret hardening, and meta box capability fix. Recommended for all installations. No breaking changes to existing data.

= 1.2.2 =
Adds custom payment types and WP admin theme color support. No breaking changes to existing data.

= 1.2.1 =
Consistent button styles. No breaking changes.

= 1.2.0 =
Adds collaborators repeater and owner dropdown to Work edit form. No breaking changes to existing data.

= 1.1.0 =
Adds Quick Notes and Calendar Color fields to Works. No breaking changes to existing data.

= 1.0.0 =
Initial public release.
