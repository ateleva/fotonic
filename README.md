<p align="center">
  <img src="https://raw.githubusercontent.com/ateleva/fotonic/main/.wordpress-org/icon-256x256.png" alt="Eleva CRM for Photographers" width="128">
</p>

# Eleva CRM for Photographers

**CRM and workflow manager for professional event photographers.**

A standalone WordPress plugin with a modern React-powered dashboard — no monthly fees, no external dependencies.

---

## Features

- **Customer Management** — store couples and individuals with multiple contacts per client; full search across all fields; each customer page shows a linked Works table with totals (count, total price, paid, unpaid)
- **Service Catalog** — define services and base prices; override price and notes per project
- **Works / Projects** — the central hub linking customers, services, files, and payment installments; Total Price tracked per project
- **Payment Tracking** — track deposits and balances; paid/partial/unpaid status assigned automatically
- **File Vault** — attach contracts and files to any project; protected storage with REST-gated downloads
- **Vault Security** — AES-256 encryption behind a master password and TOTP two-factor authentication; all PII is encrypted at rest
- **Dashboard** — annual revenue, upcoming events, unpaid balances, and the next five scheduled works
- **Quick Notes** — a WYSIWYG notes field on each Work for short reminders visible at a glance
- **Calendar Color** — assign an event color (12-color palette) to each Work
- **Monthly Calendar** — a full monthly calendar view showing all scheduled works as colored pills; click any entry for details

---

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher

## Installation

1. Download the plugin ZIP from WordPress.org.
2. Go to **Plugins › Add New › Upload Plugin** in your WordPress admin.
3. Upload the ZIP and click **Install Now**, then **Activate**.
4. Navigate to **CRM** in the WP Admin sidebar.
5. On first launch, set your Vault master password and scan the QR code with an authenticator app.
6. Enter your password and the current OTP code to unlock the CRM.

---

## FAQ

**Does this require ACF or WooCommerce?**
No. Eleva CRM for Photographers is fully standalone and has no third-party plugin dependencies.

**Where is my data stored?**
All data is stored in your own WordPress database. The plugin makes no external HTTP requests — your data never leaves your server.

**What is the Vault?**
The Vault encrypts all personally identifiable information (names, emails, phone numbers, addresses) with AES-256 before it is written to the database. You protect it with a master password and a TOTP authenticator app. The session key lives only in an HTTP-Only, SameSite=Strict cookie encrypted with AES-256-GCM — authenticated encryption that rejects any tampered cookie.

**Does it work on shared hosting?**
Yes, as long as the host meets the PHP 7.4+ and WordPress 6.0+ requirements.

---

## Privacy

Eleva CRM for Photographers collects no personal data, makes no external HTTP requests, and transmits nothing to external servers. All data is stored in your own WordPress database.

---

## Development

Full source code (React/Vite) is publicly available at [github.com/ateleva/fotonic](https://github.com/ateleva/fotonic).

```bash
cd src && npm install
npm run build   # outputs compiled assets to dist/
```

---

## Changelog

### 1.3.5

- WP.org compliance: plugin renamed to "Eleva CRM for Photographers"; slug changed to eleva-crm-for-photographers
- WP.org compliance: removed all Pro-gated code blocks from the free plugin — no locked features remain
- WP.org compliance: converted inline script/style tags in meta boxes to `wp_add_inline_script` / `wp_add_inline_style`
- Security: added REST nonce verification to vault file download permission callback
- Admin menu position changed to auto to avoid conflicting with core items

### 1.3.4

- Security: vault file download ownership check rewritten — exact integer match on the stored file list
- Security: PBKDF2 raised from 100,000 → 600,000 iterations (OWASP 2023)
- Security: customer search SQL filters scoped to `_ftnc_people` meta key only
- WP.org compliance: replaced `remove_all_actions()` on admin-notice hooks with CSS-only hiding
- Reliability: activator `deactivate_plugins()` argument fixed to the canonical plugin slug
- Performance: menu icon SVG cached in a static property
- i18n: translators comments added; `_n()` placeholders converted to positional form

### 1.3.3

- **Customer Works recap**: Customer edit page shows a table of all linked works with totals
- **GET /works `customer_id` filter**: REST endpoint now accepts `customer_id` to filter by customer
- i18n: Italian translations added for all new strings

### 1.3.2

- **Calendar view** included for all users — full monthly calendar showing all scheduled works
- **Calendar locale**: month names and date labels now use the WordPress site language
- **Vault description** added to Settings page
- **Layout**: WP admin footer hidden on plugin page; SPA height fills viewport correctly

### 1.3.1

- **Security**: vault session cookie upgraded to AES-256-GCM (authenticated encryption)
- **Security**: deterministic IV reuse fixed for email/phone fields (PHP and browser-side)
- **Security**: `v1d:` ciphertext format introduced — email/phone correctly round-trippable
- **Security**: REST nonce verification enforced in permission callback; file download IDOR fixed
- **Security**: vault audit logging added to WP error log
- Added `uninstall.php` for full data cleanup on plugin deletion
- CI: deny-all GITHUB_TOKEN permissions on deploy workflow

### 1.3.0

- Security: vault setup endpoint returns 409 if already configured
- Security: vault cookie server-secret now uses a random stored key
- Security: meta box save requires `manage_options`
- Compatibility: `wp_enqueue_script` updated to array-style args (WP 6.3+)

### 1.2.2

- WP admin theme color integration: sidebar nav and CTA buttons adapt to all admin color schemes
- Custom payment types: CRUD API and UI manager for installment types
- Payment status badge labels are now translatable
- Accessibility: keyboard focus ring restored on all interactive elements

### 1.2.1

- Consistent button design system: primary, secondary, and danger variants
- Full-width forms across WorkForm, CustomerForm, ServiceForm, and SettingsPage

### 1.2.0

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
