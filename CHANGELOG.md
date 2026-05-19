# Changelog

## [1.2.2] — 2026-05-19

### Added
- **Custom payment types**: CRUD REST API (`GET/POST /payment-types`, `PUT/DELETE /payment-types/:id`) backed by a `fotonic_payment_types` wp_option. Defaults seed `Payment` and `Discount` types on first use.
- **Payment Type Manager**: UI in the Works list to create, rename, and delete custom installment types.
- Installment type selector in WorkForm is now a `<select>` dropdown populated from custom payment types, replacing the hardcoded Default/Coupon toggle.

### Changed
- **WP admin theme color**: sidebar nav active background and CTA button accent now read actual computed colors from the WP admin DOM at runtime via an inline script (reads `#adminmenu .current > a` for `--fotonic-nav` and a hidden `.wp-core-ui .button-primary` for `--fotonic-primary`). Works for all built-in and custom admin color schemes without hardcoding.
- Sidebar nav: all nav items (free + Pro) now use `nav-link-active` CSS class for active state; Pro items no longer render hardcoded `bg-indigo-50 text-indigo-700`.
- Payment status badge labels (Paid / Partial / Unpaid) moved inside component render scope so they are correctly translated via `__()`.

### Fixed
- Secondary button border disappeared after WP admin bleed-through reset. Fixed by adding `border-solid` to the Tailwind variant class (`border border-solid border-gray-200`).
- `focus:outline-none` removed from `Button` base classes — keyboard users now see a proper focus ring on all interactive elements.
- `cursor-pointer` added to `Button` base classes to restore pointer cursor after the `appearance: none` reset.

## [1.2.1] — 2026-05-18

### Changed
- **Consistent button design system**: all buttons now use three semantic variants — `primary` (WP admin theme color, adapts to user's chosen color scheme), `secondary` (white + gray border), `danger` (red). No more shadows, gradients, or mixed inline styles.
- **WP admin theme color integration**: primary buttons pick up `--wp-admin-theme-color` CSS variable automatically, so the plugin matches whatever admin color scheme the user selects in their WP profile.
- Button reset (`appearance: none`, `box-shadow: none`, `outline: none` on focus/active) prevents WP admin CSS bleed-through on all button elements inside `#fotonic-app-root`.
- **Full-width forms**: removed `max-w-*` constraints from WorkForm, CustomerForm, ServiceForm, and SettingsPage — all forms now use full available width.
- Trash/remove buttons in PeopleRepeater, ServicesRepeater, and CollaboratorsRepeater are now solid danger buttons (red background, white icon/text).
- Dashboard work-title links reset to plain link style (no WP admin button border bleed).
- Vault lock button neutral gray instead of amber.
- Analytics toolbar: Apply, Export CSV, Export PDF buttons aligned to same height (34px) as date inputs.

## [1.2.0] — 2026-05-14

### Added
- Collaborators repeater on Work edit form: assign collaborators to a work with individual price and payment status per entry.
- Owner dropdown on Work edit form: select yourself (admin) or any collaborator as the work owner.
- New REST endpoint `GET /collaborator-options`: returns admin user and all collaborators with their service terms, used by the owner/collaborator dropdowns in WorkForm.
- Work REST API now saves `collaborators[]` field (id, services, price, status, type) and full owner type/id assignment.
- Collaborator Services nav item in sidebar (Pro only, when collaborators feature is enabled).
- Layout: sticky shell with dynamic height calculation to fill the viewport correctly.

### Fixed
- Sidebar layout no longer overflows viewport; main content scrolls independently.

## [1.1.0] — 2026-05-12

### Added
- Full i18n/l10n support: all React UI strings wrapped in gettext `__()` functions
- Italian (it_IT) translations for all strings — PHP and JavaScript
- Regenerated POT template covering 100% of translatable strings (PHP + JSX)
- JSON translation file (`fotonic-it_IT-fotonic-app-js.json`) for React components

### Fixed
- Calendar event display and date-handling edge cases
- Layout nav labels now translated correctly (moved arrays inside component render scope)

## [1.0.0] — 2026-05-02

- Initial release
