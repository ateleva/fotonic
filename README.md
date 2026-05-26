<p align="center">
  <img src="https://raw.githubusercontent.com/ateleva/fotonic/main/.wordpress-org/icon-256x256.png" alt="Fotonic" width="128">
</p>

# Fotonic

**CRM and workflow manager for freelance photographers.**

A standalone WordPress plugin with a modern React-powered dashboard — no monthly fees, no external dependencies.

---

## Features

- **Customer Management** — store couples and individuals with multiple contacts per client; full search across all fields
- **Service Catalog** — define services and base prices; override price and notes per project
- **Works / Projects** — the central hub linking customers, services, files, and payment installments
- **Payment Tracking** — track deposits and balances; paid/partial/unpaid status assigned automatically
- **File Vault** — attach contracts and files to any project; protected storage with REST-gated downloads
- **Vault Security** — AES-256 encryption behind a master password and TOTP two-factor authentication; all PII is encrypted at rest
- **Dashboard** — annual revenue, upcoming events, unpaid balances, and the next five scheduled works
- **Quick Notes** — a WYSIWYG notes field on each Work for short reminders visible at a glance
- **Calendar Color** — assign an event color (12-color Google Calendar palette) to each Work
- **Monthly Calendar** — a full monthly calendar view showing all scheduled works as colored pills; click any entry for details

## Fotonic Pro

The premium addon adds advanced workflow, analytics, team management, and Google Calendar integration.

- Kanban board with drag-and-drop task management
- Google Calendar and Google Tasks sync (works and tasks)
- Analytics: clean UI dashboard with key stats and charts ready to be exported in CSV and PDF formats
- Analytics Compare: side-by-side period comparison with trend indicators — revenue shown as %, costs and expenses as absolute € diff
- Full Expenses tracker: add software, equipment, fixed costs, professionals services, taxes... and track everything overtime 
- Collaborator: manage your collaborators registry and assign them roles in your works, tracking expenses and their services
- Supplier management: create a list of suppliers and professionals to recommend in your works
- Products catalog: track products you offer with flexible pricing tables and export everything in PDF format
- Custom email notifications with SMTP delivery

[Learn more about Fotonic Pro](https://eleva.alessandrobonacina.com)

---

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher

## Installation

1. Download the plugin ZIP from WordPress.org.
2. Go to **Plugins › Add New › Upload Plugin** in your WordPress admin.
3. Upload the ZIP and click **Install Now**, then **Activate**.
4. Navigate to **Fotonic** in the WP Admin sidebar.
5. On first launch, set your Vault master password and scan the QR code with an authenticator app.
6. Enter your password and the current OTP code to unlock the CRM.

---

## FAQ

**Does this require ACF or WooCommerce?**
No. Fotonic is fully standalone and has no third-party plugin dependencies.

**Where is my data stored?**
All data is stored in your own WordPress database. The free plugin makes no external HTTP requests — your data never leaves your server.

**What is the Vault?**
The Vault encrypts all personally identifiable information (names, emails, phone numbers, addresses) with AES-256 before it is written to the database. You protect it with a master password and a TOTP authenticator app. The session key lives only in an HTTP-Only, SameSite=Strict cookie encrypted with AES-256-GCM — authenticated encryption that rejects any tampered cookie.

**Does it work on shared hosting?**
Yes, as long as the host meets the PHP 7.4+ and WordPress 6.0+ requirements.

**Is there a Pro version?**
Yes. [Fotonic Pro](https://alessandrobonacina.com) is a paid addon sold separately. The free plugin is fully functional without it.

---

## Privacy

The free Fotonic plugin collects no personal data, makes no external HTTP requests, and transmits nothing to external servers.

If you install Fotonic Pro and enable Google Calendar integration, certain work and task data (titles, dates, times, locations, quick notes) is sent to Google's servers under your own Google account. This feature is entirely opt-in and can be disconnected at any time from **Fotonic › Settings › Google Calendar**.

- [Google Privacy Policy](https://policies.google.com/privacy)
- [Google API Terms of Service](https://developers.google.com/terms)

---

## Changelog

### 1.3.2
- **Calendar view** moved from Pro to free — available to all users without a license
- **Calendar locale**: month names and date labels now use the WordPress site language
- **PRO gating**: Work Owner and Collaborators fields hidden when Pro is not installed/licensed
- **Vault description** added to Settings page (detailed when not configured, short reminder when active)
- **WP admin icon** updated to Fotonic logo mark SVG; SPA sidebar shows Fotonic logotype SVG
- **Layout**: WP admin footer hidden on Fotonic page; SPA height fills viewport correctly
- **Settings sidebar**: active item right-side padding and border-radius fixed
- **Works list**: payment status dropdown now sizes to full option text

### 1.3.1
- **Security**: vault session cookie upgraded to AES-256-GCM (authenticated encryption — tampered cookies rejected)
- **Security**: deterministic IV reuse fixed for email/phone fields (PHP and browser-side)
- **Security**: `v1d:` ciphertext format introduced — deterministic ciphertexts are now correctly round-trippable; email/phone no longer display empty after encryption
- **Security**: REST nonce verification enforced in permission callback; file download IDOR fixed; negative price injection prevented
- **Security**: vault audit logging added (unlock ok/fail, lock, password-change) to WP error log
- Added `uninstall.php` for full data cleanup on plugin deletion
- CI: deny-all GITHUB_TOKEN permissions on deploy workflow

### 1.3.0
- Security: vault setup endpoint returns 409 if already configured (prevents accidental PII wipe)
- Security: vault cookie server-secret now uses a random stored key instead of the guessable site URL
- Security: meta box save requires `manage_options` (was `edit_post`)
- Compatibility: `wp_enqueue_script` updated to array-style args (WP 6.3+)
- Compatibility: Tested up to WordPress 7.0
- CI: build artifact check added to deploy workflow

### 1.2.2
- WP admin theme color integration: sidebar nav and CTA buttons adapt to all built-in and custom admin color schemes
- Custom payment types: new CRUD API and UI manager lets administrators define installment types beyond Default/Coupon
- Payment status badge labels (Paid / Partial / Unpaid) are now translatable
- Accessibility: keyboard focus ring restored on all interactive elements

### 1.2.1
- Consistent button design system: primary, secondary, and danger variants using WP admin theme color
- Full-width forms across WorkForm, CustomerForm, ServiceForm, and SettingsPage

### 1.2.0
- Collaborators repeater on Work edit form with individual price and payment status per entry
- Owner dropdown on Work edit form
- Sticky layout with independently scrollable sidebar and content area

### 1.1.0
- Quick Notes WYSIWYG field on Work edit screen
- Calendar Color picker (12-color palette) on Work edit screen
- Full i18n/l10n support; Italian (it_IT) translation at 100%

### 1.0.0
- Initial public release

---

## License

GPLv2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
