# Changelog

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
