// The newer versions of our plugins that WordPress already knows about (localized by class-admin-page.php, empty for
// a person who may not update plugins): `{ items: [{id, name, current, latest, note}], url }`.
export const pendingUpdates = () => window.FotonicApp?.updates?.items ?? []
