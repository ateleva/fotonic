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

## Fotonic Pro

The premium addon adds advanced workflow, analytics, team management, and Google Calendar integration.

- Kanban board with drag-and-drop task management
- Monthly Calendar view
- Google Calendar and Google Tasks sync
- Analytics with charts and CSV / PDF export
- Collaborator and Supplier management
- Products catalog
- Custom email notifications with SMTP delivery

[Learn more about Fotonic Pro](#)

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
The Vault encrypts all personally identifiable information (names, emails, phone numbers, addresses) before it is written to the database. You protect it with a master password and a TOTP authenticator app.

**Does it work on shared hosting?**
Yes, as long as the host meets the PHP 7.4+ and WordPress 6.0+ requirements.

**Is there a Pro version?**
Yes. [Fotonic Pro](#) is a paid addon sold separately. The free plugin is fully functional without it.

---

## Privacy

The free Fotonic plugin collects no personal data, makes no external HTTP requests, and transmits nothing to external servers.

If you install Fotonic Pro and enable Google Calendar integration, certain work and task data (titles, dates, times, locations, quick notes) is sent to Google's servers under your own Google account. This feature is entirely opt-in and can be disconnected at any time from **Fotonic › Settings › Google Calendar**.

- [Google Privacy Policy](https://policies.google.com/privacy)
- [Google API Terms of Service](https://developers.google.com/terms)

---

## Changelog

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
