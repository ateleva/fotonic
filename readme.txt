=== Fotonic ===
Contributors: ateleva
Tags: photography, crm, workflow, photographers, event-photography
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.3.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

CRM and workflow manager for professional event photographers.

== Description ==

Fotonic is a standalone WordPress plugin that provides a modern React-powered CRM dashboard for professional event photographers, without any monthly subscription fees.

= Features =

* Customer Management: store couples and individuals with multiple contacts per client. Full backend search across all custom fields. Each customer page shows a linked Works table with totals (total price, paid, unpaid).
* Service Catalog: define your services and base prices. Override price and notes per project.
* Works / Projects: the central hub linking customers, services, attached files, and payment installments. Total Price and Taxable Price (when Fotonic Pro is active) are shown side-by-side.
* Payment Tracking: track deposits and balances. Automatic paid/partial/unpaid status assigned from installments.
* File Vault: attach contracts and files to any project. Protected storage with .htaccess and REST-gated downloads.
* Vault Security: AES-256 encryption protected by a master password and TOTP two-factor authentication. All personally identifiable data is encrypted at rest. Even direct database access reveals only ciphertext.
* Dashboard: summary cards showing annual revenue, upcoming events, and unpaid balances. Next five upcoming works.
* Quick Notes: a dedicated WYSIWYG notes field on each Work, positioned above the main notes editor, for short reminders visible at a glance.
* Calendar Color: choose an event card color (12-color Google Calendar palette) on each Work.
* Monthly Calendar: a full monthly calendar view showing all scheduled works as colored pills. Click any entry to see a detail popup with date, customer, payment status, and a link to the work. Google Calendar sync is available when Fotonic Pro is installed and connected.

= Fotonic Pro =

A paid addon (sold separately) that adds: task management with Kanban board, Google Calendar and Google Tasks integration, Analytics with revenue charts and CSV/PDF export, Expense tracker, per-year raw taxes configuration (tax percentage × taxable price per work, automatically subtracted from Net Revenue), period-over-period Analytics Compare with trend indicators, Collaborators and Suppliers registry, Products catalog, custom email notifications with SMTP delivery.

When Fotonic Pro's Google Calendar integration is enabled, certain work and task data (titles, dates, times, locations, quick notes, task descriptions) is sent to Google's servers. See the Privacy Policy section below and the Fotonic Pro readme for full details.

= No third-party dependencies =

No ACF required. No WooCommerce required. Fully standalone. Uses only native WordPress APIs.

== Installation ==

1. Download the plugin ZIP from WordPress.org.
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin.
3. Upload the ZIP and click Install Now, then Activate.
4. Navigate to Fotonic in the WP Admin sidebar.
5. On first launch, set your Vault master password and scan the QR code with an authenticator app (Google Authenticator, Authy, or any TOTP-compatible app).
6. Enter your Vault password and the current OTP code to unlock the CRM.

== Frequently Asked Questions ==

= Does this require ACF (Advanced Custom Fields)? =

No. Fotonic uses only native WordPress meta boxes and registers all custom post types natively. No third-party field plugins are required or used.

= Does this work without WooCommerce? =

Yes. Fotonic is completely standalone and has no dependency on WooCommerce or any other plugin.

= What is the Vault? =

The Vault is a built-in security layer. All personally identifiable information (customer names, emails, phone numbers, addresses, notes) is encrypted with AES-256 before being stored in the database. You set a Vault master password that is independent of your WordPress login, and you pair it with a TOTP authenticator app. Without your Vault master password, the database contains only unreadable ciphertext. The decryption key is never stored in the database; it lives only in a temporary, HTTP-Only, SameSite=Strict session cookie encrypted with AES-256-GCM (authenticated encryption — tampered cookies are rejected, not silently decrypted).

= Does the plugin work on shared hosting? =

Yes. It requires PHP 7.4 or higher and WordPress 6.0 or higher, both of which are standard on modern shared hosting environments.

= Where is my data stored? =

All data is stored exclusively in your own WordPress database (wp_posts and wp_postmeta tables). The free plugin makes no external HTTP requests and no data is ever sent to external servers. You own and control your data entirely.

Exception: if you install Fotonic Pro and connect Google Calendar, certain work and task data is transmitted to Google's APIs. This feature requires your explicit consent and can be disconnected at any time from Fotonic > Settings > Google Calendar.

= Is there a Pro version? =

Yes. Fotonic Pro is a paid addon that adds task management, Kanban board, Google Calendar and Google Tasks sync, Analytics dashboards, Collaborator management, Products catalog, custom email notifications, and license management. It is sold separately and is not required to use the free plugin.

= Does the plugin send data anywhere? =

The free plugin makes zero external HTTP requests. Your data never leaves your server.

Fotonic Pro, when Google Calendar integration is enabled by the site administrator, transmits the following data to Google's servers: work titles, event dates and times, event locations, quick notes, task titles, task descriptions, and the related work title. This data is sent to the Google Calendar Events API and Google Tasks API under your own Google account. No data is sent to Fotonic's servers. The integration is entirely opt-in and can be disconnected at any time.

== Privacy Policy ==

= Free plugin =

The free Fotonic plugin collects no personal data of any kind, makes no external HTTP requests, and transmits nothing to external servers. All data is stored in your own WordPress database.

= Fotonic Pro — Google Calendar integration =

When the site administrator enables Google Calendar integration under Fotonic > Settings > Google Calendar, the following applies:

**What data is sent:**
- Work (project) titles, event dates, event start and end times, event location addresses, and quick notes are sent to the Google Calendar Events API to create or update calendar events.
- Task titles, related work titles, task descriptions, and event dates are sent to the Google Calendar Events API or Google Tasks API (depending on whether the task has an end time) to create or update entries.
- Color preferences (mapped to Google Calendar color IDs) are included in these requests.

**When data is sent:**
- Only when a work or task is saved and the Google Calendar feature is enabled.
- Only under the Google account authenticated by the site administrator.
- No data is sent automatically during plugin activation or without the administrator's explicit action.

**Data is not sent to Fotonic:**
- All Google API calls go directly from your server to Google's servers. Fotonic's servers act only as an OAuth relay to obtain authorization tokens, and do not store or log any work or task content.

**OAuth tokens:**
- The Google OAuth refresh token is stored encrypted (AES-256-CBC) in your WordPress database.
- The short-lived access token is stored in a WordPress transient and expires automatically.

**Removing data:**
- Disconnecting Google Calendar (Fotonic > Settings > Google Calendar > Disconnect) deletes the stored refresh token from your database.
- Deleting a work or task in Fotonic also deletes the corresponding Google Calendar event or task from Google's servers.

**Google's privacy policy:** https://policies.google.com/privacy

**Google Calendar API Terms of Service:** https://developers.google.com/terms

== Screenshots ==

1. Vault unlock screen with master password and TOTP authentication
2. Dashboard with annual revenue, upcoming events, and unpaid balance summary
3. Customer list with search
4. Work detail form with payment installments and file attachments

== Changelog ==

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
* Taxable Price field on Works (Pro-gated): new numeric input shown next to Total Price in the Work form when Fotonic Pro is active. Stored as _ftnc_total_price_taxable. Used by Fotonic Pro to compute per-work raw taxes.
* REST GET /works now accepts a customer_id query parameter to filter works by customer. Used by the customer works recap component.
* i18n: Italian translations added for all new strings (works recap labels, Taxable Price / Imponibile).

= 1.3.2 =
* Calendar view is now a free feature: the monthly calendar showing all scheduled works is available to all users. Only Google Calendar sync requires Fotonic Pro.
* Calendar locale fix: month names and event dates now display in the WordPress site language instead of the server/OS locale.
* PRO gating: Work Owner and Collaborators fields no longer appear in the Work edit form when Fotonic Pro is not installed or not licensed. Calendar Color remains visible to all users.
* Vault description: Settings page now shows an explanation of the Vault feature — detailed when not yet configured, a short reminder when active.
* UI: Fotonic logotype SVG now shown in the React SPA sidebar; Fotonic logo mark used as the WP Admin menu icon.
* UI: Settings sidebar nav item right-side clipping fixed.
* UI: Payment status filter dropdown in the Works list now sizes to full option text width.
* Layout: WP admin footer hidden on the Fotonic page; SPA viewport height correctly fills the available space.

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
* Added collaborators repeater on Work edit form: assign collaborators with individual price and payment status per entry.
* Added owner dropdown on Work edit form: select yourself or any collaborator as the work owner.
* Added REST endpoint GET /collaborator-options for work owner and collaborator dropdowns.
* Work REST API now saves collaborators[] and full owner type/id fields.
* Added Collaborator Services nav item in sidebar (Pro feature).
* Fixed: sticky layout shell with dynamic viewport height; sidebar and main content scroll independently.

= 1.1.0 =
* Added Quick Notes WYSIWYG field to Work edit screen, placed above the main notes editor.
* Added Calendar Color picker (12-color palette) to Work edit screen; color syncs to Google Calendar when Pro integration is enabled.
* Work Google Calendar event description now uses the Quick Notes field only (removed customer name, price, and admin link).
* Added `quick_notes` and `color` fields to the Works REST API.
* Added `calendar` and `gcal` feature flags to the localized script data for Fotonic Pro.
* Fotonic Pro: task management system — new ftnc_task post type, created from Kanban view.
* Fotonic Pro: Kanban board is now task-centric; cards show task title and related work name.
* Fotonic Pro: monthly Calendar view showing all works and tasks with their colors.
* Fotonic Pro: Google Calendar integration — connect your Google account and sync works and tasks.
* Fotonic Pro: Google Tasks API integration — tasks without an end time sync as Google Calendar Activities.
* Fotonic Pro: task color picker (12-color palette matching Google Calendar named colors).
* Fotonic Pro: Show in Calendar toggle on tasks with date and optional time fields.
* Full i18n/l10n support: all React UI strings wrapped in gettext functions; Italian (it_IT) translation covers 100% of PHP and JavaScript strings.
* Regenerated POT template; added JSON translation file for React components (`wp_set_script_translations`).

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.3.4 =
Security hardening release. Vault file download ownership check rewritten for exact JSON matching, PBKDF2 raised to 600k iterations, customer search SQL scoped to a single meta key, admin notice suppression replaced with CSS-only hide. No breaking changes to existing data; existing vaults continue to unlock correctly.

= 1.3.3 =
Adds customer works recap table and (with Fotonic Pro) taxable price field on works. No breaking changes to existing data.

= 1.3.1 =
Security hardening release. Vault session cookie upgraded to AES-256-GCM. Deterministic encryption (email/phone) IV reuse fixed. REST nonce check enforced. File download IDOR fixed. Audit logging added. No breaking changes to existing data — vault password-change will correctly re-encrypt all fields.

= 1.3.0 =
Security hardening: vault setup guard, server-secret hardening, and meta box capability fix. Recommended for all installations. No breaking changes to existing data.

= 1.2.2 =
Adds custom payment types and WP admin theme color support. No breaking changes to existing data.

= 1.2.1 =
Consistent button styles. No breaking changes.

= 1.2.0 =
Adds collaborators repeater and owner dropdown to Work edit form. No breaking changes to existing data.

= 1.1.0 =
Adds Quick Notes and Calendar Color fields to Works. Fotonic Pro gains task management, Calendar view, Google Calendar sync, and Google Tasks integration. No breaking changes to existing data.

= 1.0.0 =
Initial public release.
