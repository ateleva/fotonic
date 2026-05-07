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

Fotonic is a standalone WordPress plugin that provides a modern React-powered CRM dashboard for professional event photographers, without any monthly subscription fees.

= Features =

* Customer Management: store couples and individuals with multiple contacts per client. Full backend search across all custom fields.
* Service Catalog: define your services and base prices. Override price and notes per project.
* Works / Projects: the central hub linking customers, services, attached files, and payment installments.
* Payment Tracking: track deposits and balances. Automatic paid/partial/unpaid status assigned from installments.
* File Vault: attach contracts and files to any project. Protected storage with .htaccess and REST-gated downloads.
* Vault Security: AES-256 encryption protected by a master password and TOTP two-factor authentication. All personally identifiable data is encrypted at rest. Even direct database access reveals only ciphertext.
* Dashboard: summary cards showing annual revenue, upcoming events, and unpaid balances. Next five upcoming works.

= Fotonic Pro =

A paid addon (sold separately) that adds: Kanban pipeline board, Analytics with charts and CSV/PDF export, Collaborator management, Products catalog, custom email notifications with SMTP delivery, and SLM license management.

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

All data is stored exclusively in your own WordPress database (wp_posts and wp_postmeta tables). No data is ever sent to external servers. You own and control your data entirely.

= Is there a Pro version? =

Yes. Fotonic Pro is a paid addon that adds Kanban board, Analytics dashboards, Collaborator management, Products catalog, custom email notifications, and license management. It is sold separately and is not required to use the free plugin.

= Does the plugin send data anywhere? =

No. The plugin makes no external HTTP requests unless you explicitly activate the Fotonic Pro license, which contacts your own SLM license server. The free plugin makes zero external calls.

== Screenshots ==

1. Vault unlock screen with master password and TOTP authentication
2. Dashboard with annual revenue, upcoming events, and unpaid balance summary
3. Customer list with search
4. Work detail form with payment installments and file attachments

== Changelog ==

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
