=== Fotonic ===
Contributors: ateleva
Tags: photography, crm, workflow, photographers, event-photography
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

CRM and workflow manager for professional event photographers.

== Description ==

Fotonic is a standalone WordPress plugin that provides a modern React-powered CRM dashboard for professional event photographers, without any monthly subscription fees.

= Features =

* Customer Management: store couples and individuals with multiple contacts per client. Full backend search across all custom fields.
* Service Catalog: define your services and base prices. Override price and notes per project.
* Works / Projects: the central hub linking customers, services, attached files, and payment installments.
* Payment Tracking: track deposits and balances. Automatic paid/partial/unpaid status assigned from installments.
* File Vault: attach contracts and files to any project. Protected storage with .htaccess and REST-gated downloads.
* Vault Security: AES-256 encryption protected by a master password and TOTP two-factor authentication. All personally identifiable data is encrypted at rest. Even direct database access reveals only ciphertext.
* Dashboard: summary cards showing annual revenue, upcoming events, and unpaid balances. Next five upcoming works.
* Quick Notes: a dedicated WYSIWYG notes field on each Work, positioned above the main notes editor, for short reminders visible at a glance.
* Calendar Color: choose an event card color (12-color Google Calendar palette) on each Work, used in the monthly calendar view and synced to Google Calendar when the Pro integration is enabled.

= Fotonic Pro =

A paid addon (sold separately) that adds: task management with Kanban board, a monthly Calendar view, Google Calendar and Google Tasks integration, Analytics with charts and CSV/PDF export, Collaborator management, Products catalog, custom email notifications with SMTP delivery, and SLM license management.

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

The Vault is a built-in security layer. All personally identifiable information (customer names, emails, phone numbers, addresses, notes) is encrypted with AES-256-CBC before being stored in the database. You set a Vault master password that is independent of your WordPress login, and you pair it with a TOTP authenticator app. Without your Vault master password, the database contains only unreadable ciphertext. The decryption key is never stored in the database; it lives only in a temporary, HTTP-Only, SameSite=Strict session cookie.

= Does the plugin work on shared hosting? =

Yes. It requires PHP 7.4 or higher and WordPress 6.0 or higher, both of which are standard on modern shared hosting environments.

= Where is my data stored? =

All data is stored exclusively in your own WordPress database (wp_posts and wp_postmeta tables). The free plugin makes no external HTTP requests and no data is ever sent to external servers. You own and control your data entirely.

Exception: if you install Fotonic Pro and connect Google Calendar, certain work and task data is transmitted to Google's APIs. This feature requires your explicit consent and can be disconnected at any time from Fotonic > Settings > Google Calendar.

= Is there a Pro version? =

Yes. Fotonic Pro is a paid addon that adds task management, Kanban board, Calendar view, Google Calendar and Google Tasks integration, Analytics dashboards, Collaborator management, Products catalog, custom email notifications, and license management. It is sold separately and is not required to use the free plugin.

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

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.1.0 =
Adds Quick Notes and Calendar Color fields to Works. Fotonic Pro gains task management, Calendar view, Google Calendar sync, and Google Tasks integration. No breaking changes to existing data.

= 1.0.0 =
Initial public release.
