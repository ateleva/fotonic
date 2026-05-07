=== Fotonic ===
Contributors: ateleva
Tags: photography, crm, workflow, photographers, event-photography
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

CRM and workflow manager for professional event photographers.

== Description ==

Fotonic is a standalone WordPress plugin that provides a modern React-powered CRM dashboard for professional event photographers — without any monthly subscription fees.

**Features:**

* **Customer Management** — Store couples and individuals with multiple contacts per client. Full backend search including custom meta fields.
* **Service Catalog** — Define services and base prices. Override price and notes per project.
* **Works / Projects** — Central hub linking customers, services, files, and payment installments.
* **Payment Tracking** — Track deposits and balances. Automatic paid/partial/unpaid taxonomy status.
* **File Vault** — Attach contracts and files. Protected storage with `.htaccess` and REST-gated downloads.
* **Vault Security** — AES-256 encryption + TOTP 2FA. All PII encrypted at rest. Database theft reveals only ciphertext.
* **Dashboard** — Summary cards: annual revenue, upcoming events, unpaid balances. Next 5 upcoming works.

**Fotonic Pro (sold separately)** adds: Kanban pipeline board, Analytics with charts and CSV/PDF export, Collaborator management, Products catalog, Custom notifications with SMTP delivery, and license management.

No ACF required. No WooCommerce required. Fully standalone.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/fotonic/`
2. Activate the plugin through the Plugins screen in WordPress
3. Navigate to **Fotonic** in the WP Admin sidebar
4. On first launch, set your Vault password and scan the QR code with an Authenticator app (Google Authenticator, Authy)
5. Enter your Vault password and OTP code to unlock and access the CRM

== Frequently Asked Questions ==

= Does this require ACF (Advanced Custom Fields)? =

No. Fotonic uses native WordPress meta boxes with no third-party field plugins.

= Does this work without WooCommerce? =

Yes. Fotonic is completely standalone.

= What is the Vault? =

The Vault is a built-in security layer. All personally identifiable information (customer names, emails, phones, addresses, notes) is encrypted with AES-256 before being stored in the database. You set a Vault password (independent of your WordPress login) and pair it with a TOTP authenticator app. Without your Vault password, the database contains only unreadable ciphertext.

= Does the plugin work on shared hosting? =

Yes. It requires PHP 7.4+ and WordPress 6.0+, both standard on modern shared hosts.

= Where is my data stored? =

All data stays in your own WordPress database (wp_posts and wp_postmeta). Nothing is sent to external servers. You own your data entirely.

= Is there a Pro version? =

Yes. Fotonic Pro is a paid addon that adds Kanban board, Analytics dashboards, Collaborator management, Products catalog, custom email notifications, and license management. It is sold separately.

== Screenshots ==

1. Vault unlock screen with TOTP authentication
2. Dashboard with revenue and upcoming event summary
3. Customer list with search
4. Work detail form with payment installments

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
