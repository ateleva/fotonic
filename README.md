# Fotonic

**Fotonic** is a free WordPress plugin that brings a professional CRM and workflow management system directly into your WordPress dashboard — purpose-built for event photographers.

Stop juggling WhatsApp threads, Excel sheets, and scattered folders. Fotonic gives you one place to manage your clients, services, and jobs, with the security and speed of a modern web application — at no recurring cost.

---

## Free Features

### Client Registry
Maintain a complete database of your clients. Each client can include multiple contacts (couples, families, or groups), with individual names, emails, and phone numbers. One contact is flagged as the primary — used for direct communication and automatic CC logic.

### Service Catalog
Define the services you offer (e.g. wedding photography, corporate events, portraits) with base prices and notes. Services are reusable across jobs, with the ability to override price and details per job.

### Job Management
The core of Fotonic. Each job ties together:
- Event details: date, time, location
- Client link
- Services included (with per-job price overrides)
- File attachments (contracts, mood boards) via the native WordPress media library
- Payment tracking: total price, installments, and payment status (Paid / Partial / To Be Paid)
- Private notes

### Vault Mode — Encrypted Data at Rest
All personally identifiable information (names, emails, phone numbers, notes, contracts) is encrypted with AES-256 before being stored in the database. A separate Vault Password — independent of your WordPress login — unlocks access to your data. If your database is ever compromised, the data is mathematically unreadable without the master password.

### Two-Factor Authentication (TOTP)
Fotonic's access gate requires both your Vault Password and a time-based one-time code (Google Authenticator, Authy, or any TOTP app) before any client data is loaded.

### Protected File Storage
Uploaded contracts and files are stored outside the public web root and served only through a secure, session-authenticated endpoint. Direct URL access is blocked.

### Native WordPress Integration
- Zero dependency on third-party field plugins (no ACF required)
- Uses the native WordPress media library for file attachments
- React SPA embedded in the WordPress Admin — no external services, no monthly fees
- Full data ownership: everything lives on your own hosting

---

## Fotonic Pro

A paid addon extends Fotonic with advanced workflow and business intelligence tools:

- **Collaborator Management** — Register second shooters, video operators, drone pilots, and other freelancers. Track which jobs they're assigned to, what they've been paid, and what is still owed.
- **Product Catalog** — Build a physical product upsell catalog (albums, prints, wall art) with galleries, pricing tiers, and descriptions to present to clients.
- **Kanban Pipeline Board** — Drag-and-drop visual board to track the post-production status of every job across custom workflow columns.
- **Analytics Dashboard** — Compare revenue, job count, and collaborator costs month-over-month or year-over-year. Export data as PDF or CSV.
- **Custom Reminders & Notifications** — Set date-and-time reminders directly on a job (e.g. "request album photos", "send final gallery"). Delivered via SMTP or a relay API with full DKIM/DMARC compliance.
- **License Management** — Secure software license activation and automatic in-dashboard update delivery.

---

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher (tested up to PHP 8.3)
- A modern browser

---

## Installation

1. Download the latest release ZIP from the [Releases](../../releases) page
2. In your WordPress dashboard go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate
4. A **Fotonic** menu item will appear in your WordPress sidebar
5. Follow the onboarding steps to set up your Vault Password and two-factor authentication

---

## License

Fotonic is licensed under the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html) or later.

---

## Contributing

This plugin is in active early development. Issues and pull requests are welcome.
