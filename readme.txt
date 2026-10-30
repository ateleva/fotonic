=== Eleva CRM for Photographers ===
Contributors: eleva
Tags: crm, photography, photographer, client-management, wedding
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free CRM for wedding and event photographers: clients, bookings, payments and memory cards, with an encrypted vault. No subscription.

== Description ==

Eleva is a free photography business manager and CRM, built for wedding and event photographers. It keeps every client, every shoot, and every payment in one place, right inside the WordPress admin you already use, with no monthly subscription and nothing sent to a server you don't control.

You already have enough to think about on shoot day. Eleva handles the rest: who is getting married and when, what they owe and what they have paid, which memory card belongs to which shoot, and where every contract and file lives. The client details that matter most, names, contacts, home addresses, are protected by a dedicated encryption vault, so a leaked database never hands anyone a usable list of your clients.

= Features =

* Customers: keep every couple or client together, with more than one contact per booking, so a bride's email and a groom's email can both live on the same record, each with an optional gender. Full search across names, emails, phones, and addresses. Every customer page shows their full booking history with running totals.
* Services: build a catalog of the packages and add-ons you offer, then override the price or notes for any specific booking without touching the catalog.
* Works (your bookings): the hub for every job. Customer, services, event date and time, every location, every file, and every payment, on one page.
* Payments: track deposits and balances with a running list of installments. Paid, Partial, or Unpaid status is worked out for you automatically from what has actually been paid.
* Custom payment types: go beyond a simple deposit and balance and define your own installment types to match how you actually bill.
* Files: attach contracts, invoices, and reference files to any booking, or drop in a link to a Google Drive or Dropbox folder instead. Images and PDFs preview right in the browser, with zoom and full-document search.
* Dashboard: your year at a glance. Revenue booked, payments still to receive, and the next five shoots coming up.
* Monthly calendar: every booking as a colored pill on a real calendar. Click through to see the customer, date, and payment status.
* Calendar colors: tag each booking with one of twelve colors, so a glance at the calendar tells you which is which.
* Memory Cards: an inventory of your SD and CF cards, so you always know which ones are safe to format. Status moves itself: Ready, In Use, Backed Up, or Damaged, as you attach a card to a shoot and check off the backup.
* Quick Notes: a short rich-text field on every booking for the one-line reminder you need at a glance, kept separate from your full notes.
* Vault: a dedicated encryption layer, protected by its own password and a TOTP authenticator app, that keeps client names, contact details, and addresses unreadable in the database, even to someone with direct database access.
* Encrypted backup: download one encrypted archive of your entire CRM (customers, bookings, services, and files) whenever you want, protected by your Vault credentials and opened by no one but you.

= No dependencies, nothing leaves your server =

Eleva doesn't require ACF, WooCommerce, or any other plugin, and makes zero outbound requests of its own: nothing is ever sent to us or to anyone else. Every byte of your data stays in your own WordPress database, on your own server.

Eleva CRM Pro is a separate, optional add-on. If you choose to connect it to Google Calendar or Google Drive, those are its own connections, made only when you turn them on, never by the free plugin.

== Installation ==

1. Download the plugin ZIP from WordPress.org.
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin.
3. Upload the ZIP and click Install Now, then Activate.
4. Navigate to Eleva in the WP Admin sidebar.
5. On first launch, set your Vault master password and scan the QR code with an authenticator app (Google Authenticator, Authy, or any TOTP-compatible app).
6. Enter your Vault password and the current OTP code to unlock the CRM.

== Frequently Asked Questions ==

= Who is Eleva for? =

Wedding and event photographers who want a CRM built around how a shoot actually works: one client, one date, a handful of payments, and a stack of files, not a generic sales pipeline.

= Is there a free CRM for photographers in WordPress? =

Yes. Eleva is a free CRM that runs inside your own WordPress admin. It keeps your clients, bookings, payments, files and calendar in one place, with no monthly fee and no feature locked behind an upgrade.

= What can I manage with Eleva? =

Eleva works as a photography business manager. You can track customers (with more than one contact per booking), your catalog of services and prices, every booking with its date, locations and files, deposits and balances with automatic Paid, Partial or Unpaid status, a monthly calendar, and an inventory of your memory cards. Client details stay encrypted in your own database.

= Do I need a subscription? =

No. Eleva is free, with no monthly fee and no feature paywall inside the app. An optional paid add-on, Eleva CRM Pro, exists for studios that want a team (collaborators), a Kanban board, analytics, quotes with VAT and PDF, and automatic scheduled backups to Google Drive, but nothing in the free plugin nags you to upgrade.

= Does this require ACF (Advanced Custom Fields)? =

No. Eleva uses only native WordPress meta boxes and registers all custom post types itself. No third-party field plugin is required or used.

= Does this work without WooCommerce? =

Yes. Eleva is completely standalone and has no dependency on WooCommerce or any other plugin.

= What is the Vault? =

The Vault is a dedicated encryption layer for the personal details tied to your clients and bookings: names, email addresses, phone numbers, home addresses, nationality, tax ID, Instagram handles, and each booking's event address. Every one of those is encrypted with AES-256 before it is written to the database.

You set a Vault master password, independent of your WordPress login, and pair it with a TOTP authenticator app (Google Authenticator, Authy, or similar); unlocking always needs both. The password itself is never stored: it is stretched through PBKDF2 with 600,000 iterations, and the resulting key lives only in a temporary, authenticated session cookie (AES-256-GCM), never in the database. The Vault locks itself again after 15 minutes of inactivity, and on every WordPress login or logout, so a shared computer is never left open.

Booking details like the event date, price, and your own notes sit outside the Vault. Only the personal contact details listed above are encrypted.

= What if I forget my Vault password? =

Eleva gives you two separate fallbacks, both set up ahead of time from the Settings page. If you still know your password but lost your authenticator app, the one-time recovery code gets you back in. If you forgot your password itself, only the recovery phrase can reset it without losing your encrypted data. Set up both, since they cover different problems. Losing your password, your authenticator, and both recovery credentials together means the data cannot be recovered by anyone, including us. That is what makes the encryption real.

= Does the plugin work on shared hosting? =

Yes. It needs PHP 7.4 or higher and WordPress 6.0 or higher, both standard on modern shared hosting.

= Where is my data stored? =

Entirely in your own WordPress database. Eleva makes no external HTTP requests and sends no data to any external server, ours or anyone else's. You own and control your data completely.

= Will Eleva slow down my site? =

No. Eleva only loads on its own admin screen inside WP Admin; it adds nothing to your public-facing site, so visitor-facing performance is unaffected.

= Can I get my data out if I stop using Eleva? =

Yes, any time, from the Settings page: download a complete encrypted backup of every customer, booking, service, and file. It is your data in a portable archive, not locked to the plugin. A standalone command-line tool that ships with the plugin opens the archive on any computer and gives you your data as readable files. Importing a backup back into the plugin is not available yet.

= Does Eleva back up my data? =

Yes. From Settings, download a single encrypted archive of your entire CRM: customers, bookings, services, memory cards, and every attached file. Nothing leaves your server: the archive is built locally and saved to your own computer.

That archive is encrypted with a key protected by your Vault password before it is ever written. Nobody, including us, can open it without that password or your recovery code, and there is no back door: lose your password, recovery code, and recovery phrase together, and the archive is unrecoverable. Its protection rests on your Vault password: the minimum is 8 characters, but a longer, less guessable password is safer, so choose a strong one and keep the file private.

This backs up your CRM data, not your whole WordPress site. Themes, other plugins, and unrelated content are not included.

Automatic, scheduled backups that push this same archive to your own Google Drive are an **Eleva CRM Pro** feature. The free plugin's backup is always manual: on demand, whenever you choose to download one.

== Eleva CRM Pro ==

Eleva CRM Pro is a separate, optional paid add-on for photographers running a small team, sending quotes to clients or wanting deeper reporting. It adds:

* Quotes: prepare numbered quotes for your clients, turn a confirmed quote into a booking in one click, and send it as a PDF by email or as a message for WhatsApp, Messenger or Instagram.
* VAT: choose whether prices include VAT, exclude it or are shown without it, with your own rate.
* Company details: your business name, address and tax details, printed on your quotes.
* Collaborators: assign second shooters and team members to a booking, and track what each one is owed.
* Kanban board: move bookings through your own workflow stages.
* Analytics: revenue, cost, and owned-vs-commissioned reporting, with period comparison.
* Products & Suppliers: manage physical products and the suppliers behind them.
* Google Calendar sync: your bookings and reminders pushed automatically to your Google Calendar.
* Automatic backups: the same encrypted archive as the free plugin, pushed to your own Google Drive on a schedule, with retention.

Pro is entirely optional. Nothing in the free plugin is locked or hidden behind an upgrade prompt.

== Privacy Policy ==

Eleva CRM for Photographers collects no personal data of its own, makes no external HTTP requests, and sends nothing to any external server. Every piece of data lives exclusively in your own WordPress database.

== Credits ==

The in-browser PDF preview is built on pdf.js by Mozilla (https://mozilla.github.io/pdf.js/), licensed under the Apache License 2.0.

== Screenshots ==

1. Dashboard with the sidebar collapsed to icon-only, so every panel gets the full width
2. Dashboard: annual revenue, payments still to receive, payment type breakdown, and the next five upcoming works
3. Customer list with search: name, main contact, email, and phone at a glance
4. Service catalog with base prices: add, edit, and reuse services across bookings
5. Works list with a payment status filter (All, Paid, Partial, Unpaid), a total price column, and the custom Payment Types manager open below it
6. Work edit form: event date and time, customer selector, calendar color, and services with a per-booking price override
7. Work edit form: attached files and the Memory Cards checklist, so you always know which card holds which shoot
8. Work edit form: payment section with installments, paid or unpaid status per installment, and running totals
9. Memory Cards: every card's status (Ready, In Use, Backed Up, Damaged) and which Work it is currently assigned to
10. Monthly calendar view with every scheduled booking shown as a colored pill
11. Vault unlock screen: master password and authenticator code required before any client data is shown
12. Settings page: Vault controls (change password, reset authenticator, recovery phrase) and one-click encrypted backup download

== Changelog ==

= 1.6.2 =
* Security: the Vault now locks everywhere. Before, pressing Lock, changing the Vault password or recovering with your phrase or code only cleared the cookie of the browser you were using, so a copy of that cookie, or a second computer left open, kept working until the WordPress login ended. Now each of those actions refuses every earlier session on every browser and device, the 24-hour limit is checked by the server too, and a page left open elsewhere shows the lock screen within 30 seconds. After updating, everyone unlocks the Vault once.
* Security: guessing the Vault password or a recovery code is much harder. Failed attempts are now counted for the whole Vault (5 failures in 15 minutes) and only one check runs at a time. Before, the count was kept per user and many requests sent at once could try more guesses than allowed.
* Security: the plugin's records (customers, services, works, memory cards and payment status) no longer have screens in the classic WordPress admin and can be reached only by administrators. Before, an Editor or Author could open those screens, because they followed WordPress default permissions. Everything is managed in the Eleva CRM app; other plugins and user roles are not touched.
* Security: private files are better protected. Backup file names now carry 32 random characters instead of 8, the protection files of the backups folder are put back if they go missing, and a new Site Health test ("Eleva CRM private files are protected") checks that your web server does not hand out the backups and Vault folders. On nginx servers, which ignore the .htaccess file that protects them on Apache, the test shows the rules to add. Nothing changes on Apache.
* Security: stored files can now carry a Vault encryption layer, which Eleva CRM Pro 1.6.3 uses for quote PDFs because they contain client names and addresses. Such files are decrypted only for you, with the Vault unlocked, and the offline backup tool removes the layer, so a backup gives you readable files. The Reset screen warns that these files become unreadable after a reset.
* Fix: the customer search could also match unrelated content, for example a draft page with a similar title. It now searches customers only.
* Fix: the backup texts said that the authenticator code protects the archive. It does not: the archive is protected by your Vault password alone, so a strong password matters. The Settings page, the readme and the offline tool guide now say so, and that the minimum length is 8 characters.
* Fix: a file that cannot be opened because the Vault was locked in another tab, window or device now explains that and what to do, instead of the generic "You do not have permission".
* New: every password field has a show/hide button, so a mistyped password can be checked, and the copy buttons for the recovery kit, the username and the authenticator key work in every browser.
* Change: the page text and the readme no longer suggest that a backup can be imported back into the plugin: importing is not available yet. The offline tool opens the archive and gives you your data as readable files. The plugin page description and FAQ also explain more clearly what Eleva does.
* Compatibility: tested with the latest WordPress (7.1.x). Update Eleva CRM Pro to 1.6.3 for encrypted quote PDFs.

= 1.6.1 =
* New: Vault recovery kit. After setting up the Vault (and on the first unlock after this update) a short wizard shows your recovery phrase and recovery code, asks you to retype the last characters of each to prove you saved them, and emails the kit to the site administrator address. Settings gains "New recovery kit" to replace them at any time.
* New: "Lost your password or authenticator?" now rebuilds both from your recovery phrase or code: choose a new password and scan a new authenticator QR, without losing any data.
* New: if you lost everything, a destructive reset is available from the lock screen, gated by a one-time code emailed to the site administrator and by typing RESET. In Settings the reset accepts your password and authenticator code instead.
* Fix: Vault setup could leave a live Vault with an unverified authenticator, and the recovery phrase and code were never shown. Setup now has two steps: nothing is activated until your first authenticator code is confirmed, and an interrupted setup simply starts over.
* Fix: the Vault password minimum is now 8 characters everywhere. Before, the form accepted 10 while the server required 12, so 10 or 11 characters failed with a confusing error.
* Compatibility: tested with the latest WordPress (7.1.x).

= 1.6.0 =
* New: groundwork for Quotes in Eleva CRM Pro 1.6.0: a Quotes entry in the menu and the hooks Pro uses to prepare quotes, turn confirmed ones into works, add VAT to works and generate quote PDFs. Works created from a quote show "Created from quote" and the lead source; customers list the quotes made for them.
* New: Gender (Male or Female, optional) on every person of a customer, with a Gender column in the Customers list. The Customer form now uses a compact grid.
* New: email addresses must look like name@example.com. The Customer form highlights wrong fields in red and says what to fix, and the server rejects invalid addresses with a clear error instead of silently emptying them.
* New: Settings gains a Company details section, filled in by Pro and printed in the header of the quote PDFs.
* Fix: your personal data is protected from accidental loss. A form that cannot read a saved value (for example after the Vault key changed) no longer shows it as empty and then overwrites it on save; unreadable values are kept as they are and flagged with a notice. A locked Vault refuses to save personal details in plain text and asks you to unlock it, keeping your changes in the form. The classic editor screens no longer encrypt a value twice.
* Fix: text with accents and backslashes was altered on save in customers, services, works and memory cards (for example "è" stored as "u00e8"). Everything is now saved exactly as typed.
* Fix: the admin app's inline CSS (notice hiding, no outer scrollbar, hidden footer) was never printed because it was attached to a stylesheet handle that did not exist yet. It is now applied.
* Fix: a work must have an event date; without one it was saved but never appeared in the Works list.
* Fix: browsers could keep serving an older build of the app after an update; the asset version now changes with every build.
* Compatibility: tested with the latest WordPress (7.1.x).
* i18n: new interface strings for this release are translated into Italian (it_IT).

= 1.5.0 =
* New: Total Price is now calculated automatically from the work's service rows (plus any supplier referral fees, with Eleva CRM Pro) instead of being typed by hand, so it can never drift out of sync with what the services actually cost.
* New: Work edit screen gains a Suppliers section (requires Eleva CRM Pro) for tracking referral fees owed to wedding planners, venues, and other suppliers who refer a job.
* Fix: a work saved before this change, with a total price but no service rows, keeps its existing total instead of it being reset to zero. The work form shows a notice on any such work explaining that adding a service row lets the total be calculated automatically again.
* Fix: the Calendar view could show a customer name with a wrongly HTML-encoded ampersand, and Kanban task reminders never appeared on the Calendar at all. Both were caused by this plugin and Eleva CRM Pro registering the same calendar endpoint; this plugin now steps aside when Pro is active.
* i18n: new interface strings for this release are fully translated into Italian (it_IT).

= 1.4.2 =
* Fix: cleaned up wording on several vault, recovery, and file-selection screens for clarity and consistency.
* i18n: Italian (it_IT) translation completed for a couple of messages that had never been translated, including the session idle-timeout warning and an alternate recovery option on the "forgot my authenticator" screen.

= 1.4.1 =
* New: Work edit screen gains a Reminders section (requires Eleva CRM Pro): set a date offset from the event date and it turns into a Kanban task, with optional Google Calendar sync.
* New: "Open Google Calendar" button on the Calendar page, shown once a Google Account is connected (requires Eleva CRM Pro 1.4.1+).
* Fix: Dashboard revenue now scopes each payment installment by its own date instead of its work's event date. A payment dated in a different year than its work no longer gets double-counted in one year and missed in the other.
* Fix: Google Calendar. Removing an event from the Calendar view no longer appears to succeed when the request actually failed.
* Fix: Settings page. The Vault and Backup sections' description text no longer renders in a narrow column with empty space beside it; text now fills the panel like every other section.

= 1.4.0 =
* New: encrypted CRM backup. Download a single archive of all your CRM data (customers, works, services, memory cards, and every attached file) from the Settings page, protected by your Vault password and authenticator code. Nobody, including us, can open it without them.
* New: a standalone, dependency-free command-line decrypt tool ships with the plugin, so a backup can be restored on any machine with just PHP: no WordPress or plugin install required.
* Improved: refreshed spacing, borders, and layout across every form and list screen; the sidebar now collapses to icon-only and remembers your choice; tables get a persistent scroll indicator instead of relying on the operating system's hidden scrollbar.
* Fix: the sidebar's collapse/expand button and the calendar's day-of-week column headers now translate correctly in Italian. They previously always showed in English regardless of site language.
* Note: with Eleva CRM Pro connected to Google Drive, backups also run automatically on a schedule. See the Pro changelog.

= 1.3.12 =
* Fix: Italian (it_IT) translation now covers the Customers, Services, and Works list screens, the Dashboard, and the Calendar view. These previously displayed in English regardless of the site or user language setting, even though the rest of the plugin was translated.

= 1.3.11 =
* New: the Files section on a Work now accepts external links (Google Drive, Dropbox, etc.) alongside uploaded files, with an optional custom display label.
* New: images and PDFs in the Files section now open in a full-size in-browser preview instead of only downloading. The PDF viewer includes zoom, page navigation, and full-document search.

= 1.3.10 =
* New: Customers, Services, Works and Memory Cards lists now show a clickable title that opens the record's edit page directly, styled in your WordPress admin theme color with an underline, matching the existing Edit button shortcut.
* Fix: Dashboard's "Recent Works" title links now use your WordPress admin theme color with a permanent underline, instead of a hardcoded blue that only underlined on hover.
* Fix: file downloads on a Work's Files section now use an authenticated request instead of a plain link, so downloads work correctly when the vault is involved.
* Fix: the Notes field on a Work no longer appears to reset after external re-renders. The data was always saved correctly; only the on-screen display was affected.

= 1.3.9 =
* Fix: vault now re-locks on every WordPress login/logout (no longer stays unlocked across sessions).
* Fix: the Lock Vault button now locks immediately without a page refresh.

= 1.3.8 =
* New: Memory Cards. Keep an inventory of your SD/CF cards so you always know which ones are safe to reuse. Each card has a status: Ready, In Use, Backed Up, or Damaged, that advances automatically as you attach the card to a shoot and tick off backup and formatting. Manage your full card list from the new sidebar entry, or assign cards to a shoot right inside the Work editor. A card that is currently in use cannot be deleted by mistake.
* Vault: added a recovery phrase so you can regain access if you forget your vault password, separate from the existing one-time recovery code used for a lost authenticator. After updating, unlock your vault and you will be offered to set one up. This is optional and re-encrypts nothing.
* Vault: the unlock and setup screens now save credentials in your browser's password manager under a dedicated "crm-vault" username, so the vault password is never confused with, or overwritten on top of, your WordPress login.
* Vault: authenticator codes are now accepted within a ±60 second window, fixing spurious "invalid code" errors after the server or device clock drifts (for example, after the machine sleeps).
* Vault: the vault now auto-locks after 15 minutes of inactivity and silently re-opens on page refresh while it is unlocked.
* Fix: the sidebar title now follows your site language instead of always appearing in English, and the sidebar lock button and version footer are tidier and easier to use.
* i18n: Italian (it_IT) translation updated to 100%, including all the new Memory Cards and vault recovery-phrase wording.
* Updating from 1.3.x is seamless: your vault, password, authenticator and encrypted data are unchanged. Just update and unlock as usual: there is no data migration and no required action.

= 1.3.7 =
* i18n: Italian (it_IT) translation completed to 100%. Added the vault recovery-code and payment-type strings that were previously untranslated, and corrected leftover pre-rebrand strings.
* i18n: bundled translations now also load correctly on WordPress 6.0 to 6.6.

= 1.3.5 =
* Renamed to "Eleva CRM for Photographers".
* No locked or upsell-only features remain in the free plugin.
* Security: added nonce verification to the vault file download permission check.

= 1.3.4 =
* Security: the vault file download ownership check now compares file IDs exactly, closing a gap where the previous check could match the wrong file in rare cases.
* Security: the password-derived encryption key now uses 600,000 rounds of iteration (up from 100,000), matching current OWASP guidance. Existing vaults keep working exactly as before.
* Security: the customer search filter is now scoped precisely to customer contact fields.
* Fix: admin notices, including security and update warnings, are no longer suppressed on the Eleva screen.
* Reliability: if a required encryption extension is missing on activation, the plugin now deactivates itself cleanly instead of leaving things in a broken state.
* i18n: translator notes added to strings containing placeholders, improving translation accuracy.

= 1.3.3 =
* Customer Works recap: the Customer edit page now shows a table of all linked works with title, date, services, total price, and payment status, plus totals for the whole list.
* Works list can now be filtered to a single customer.
* i18n: Italian translations added for all new strings.

= 1.3.2 =
* Calendar view is now included: the monthly calendar showing all scheduled works is available to all users.
* Calendar locale fix: month names and event dates now display in the WordPress site language instead of the server's own locale.
* Vault description: Settings page now shows an explanation of the Vault feature, detailed when not yet configured, a short reminder once active.
* UI: logo mark used as the WP Admin menu icon.
* UI: Settings sidebar navigation clipping fixed.
* UI: Payment status filter dropdown in the Works list now sizes to the full option text.
* Layout: the plugin screen now fills the available height correctly.

= 1.3.1 =
* Security: vault session cookie upgraded to authenticated AES-256-GCM encryption. Any tampered cookie is now rejected outright rather than silently decrypted to garbage.
* Security: fixed an issue where searchable encrypted fields (email, phone) could share encryption patterns across different values; each value now encrypts uniquely.
* Security: fixed the same issue in the browser-side encryption path.
* Security: fixed a data format issue that could cause some previously-stored email and phone values to fail decryption; they now decrypt correctly.
* Security: added an additional server-side check to vault-protected requests, closing a theoretical cross-site request forgery window.
* Security: fixed the vault file download check so a file ID could not accidentally match a different file's download link.
* Security: vault unlock, failed unlock, lock, and password-change events are now logged (when WP_DEBUG_LOG is enabled) for your own auditing.
* Security: fixed re-encryption of email and phone fields during a vault password change so they always decrypt correctly afterward.
* Security: prices and installment amounts can no longer be saved as negative numbers.
* Security: the vault password now requires a minimum of 12 characters.
* Maintenance: uninstalling the plugin now fully removes all of its data (posts, custom fields, taxonomy terms, options, and the vault upload folder).

= 1.3.0 =
* Security: the vault setup process no longer allows accidentally overwriting an already-configured vault.
* Security: removed a guessable fallback in the vault's cookie encryption secret.
* Security: tightened who can save Customer, Service, and Work records to match the same permission level as the REST API.
* Compatibility: resolved a deprecation notice that appeared in some debug logs on newer WordPress versions.
* Compatibility: tested up to WordPress 7.0.

= 1.2.2 =
* WP admin theme color integration: sidebar and buttons now adapt automatically to your chosen WordPress admin color scheme.
* Custom payment types: define your own installment types beyond the built-in Default and Coupon, with a manager right in the Works list.
* Payment status badges (Paid / Partial / Unpaid) are now translatable.
* Accessibility: interactive elements now show a visible focus outline for keyboard users.

= 1.2.1 =
* Consistent button design across the whole plugin: primary, secondary, and danger styles, all matching your WordPress admin color.
* Forms now use the full available width on Work, Customer, Service, and Settings screens.

= 1.2.0 =
* Layout: sidebar and main content now scroll independently, adapting to your screen height.

= 1.1.0 =
* Added Quick Notes: a short rich-text field on the Work edit screen for at-a-glance reminders.
* Added Calendar Color: tag each Work with one of twelve colors.
* Full Italian (it_IT) translation, covering the entire plugin interface.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.6.2 =
Security update: the Vault now locks on every browser and device, failed Vault attempts are limited per Vault, plugin records are reachable only from the Eleva CRM app, and backups are better protected. You unlock the Vault once after updating. Safe in-place update.

= 1.6.1 =
Adds a Vault recovery kit (phrase and code, shown, verified and emailed to the site administrator) and a way to rebuild password and authenticator from it without losing data, plus a safer two-step Vault setup. After updating, the first unlock asks you to save a new recovery kit. Safe in-place update, compatible with Eleva CRM Pro.

= 1.6.0 =
Adds the groundwork for Quotes, VAT and PDF quotes (needs Eleva CRM Pro 1.6.0), a Gender field for customers, stricter email validation, and protection against accidental loss of saved personal data. Tested with the latest WordPress. Safe in-place update: without Pro 1.6.0 nothing new shows up and everything else works as before.

= 1.5.0 =
Total Price is now calculated automatically from the services you enter, and can no longer be typed by hand. A work saved before this update with a total but no service rows keeps its existing total; the work form tells you to add a service row so it can be calculated automatically. Update Eleva CRM Pro to 1.5.0+ for supplier referral fees.

= 1.4.2 =
Cleans up wording on several screens and completes the Italian translation for a couple of previously English-only messages. Safe in-place update: no data or breaking changes.

= 1.4.1 =
New: Work Reminders section (with Eleva CRM Pro) and an "Open Google Calendar" button. Fixes dashboard revenue scoping, Google Calendar event removal, and Settings page spacing. Safe in-place update: no data or breaking changes.

= 1.4.0 =
New: download a single encrypted backup of all your CRM data from Settings, protected by your Vault password and authenticator code. Safe in-place update: no data or breaking changes. Update Eleva CRM Pro to 1.4.0+ for automatic scheduled backups to Google Drive.

= 1.3.12 =
Fix: Italian translation now covers list screens, Dashboard, and Calendar. Safe in-place update: no data or breaking changes.

= 1.3.11 =
New: Files section now supports external links and a full-size in-browser preview for images and PDFs. Safe in-place update: no data or breaking changes.

= 1.3.10 =
New: clickable title links on Customers/Services/Works/Memory Cards lists and the Dashboard. Fix: Work file downloads and the Notes field. Safe in-place update: no data or breaking changes.

= 1.3.9 =
Security: the vault now re-locks on every WordPress login/logout, so it no longer stays unlocked across sessions, and the Lock Vault button locks immediately. Safe in-place update: your vault password, authenticator and encrypted client data are unchanged. After updating, unlock the vault as usual.

= 1.3.8 =
New: Memory Cards inventory to track your SD/CF cards. Safe in-place update: your vault password, authenticator and encrypted client data stay exactly as they are. After updating, unlock the vault as usual; you will be offered an optional recovery phrase. No data migration, no data loss, no required action. Note: the vault now auto-locks after 15 minutes of inactivity.

= 1.3.7 =
Completes the Italian translation (vault recovery + payment types) and improves translation loading on older WordPress versions. No data or breaking changes.

= 1.3.4 =
Security: vault download ownership check rewritten; PBKDF2 raised to 600k iterations. See changelog for full details.

= 1.3.3 =
Adds a customer works recap table and a way to filter works by customer. No breaking changes to existing data.

= 1.3.1 =
Security: vault cookie upgraded to AES-256-GCM; nonce enforcement tightened; a file-download ID matching issue fixed. Added complete data cleanup on plugin deletion.

= 1.3.0 =
Security hardening: vault setup guard, server-secret hardening, and permission fix. Recommended for all installations. No breaking changes to existing data.

= 1.2.2 =
Adds custom payment types and WP admin theme color support. No breaking changes to existing data.

= 1.2.1 =
Consistent button styles. No breaking changes.

= 1.2.0 =
Layout fix: sidebar and main content scroll independently. No breaking changes to existing data.

= 1.1.0 =
Adds Quick Notes and Calendar Color fields to Works. No breaking changes to existing data.

= 1.0.0 =
Initial public release.
