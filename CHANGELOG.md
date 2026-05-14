# Changelog

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
