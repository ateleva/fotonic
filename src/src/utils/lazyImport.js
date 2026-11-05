import { lazy } from 'react'

const FLAG = 'ftnc_chunk_reload'

// The three browsers word the same failure differently.
function isChunkLoadError(err) {
  const msg = String((err && err.message) || err || '')
  return /dynamically imported module|importing a module script failed|error loading dynamically|Loading chunk|ChunkLoadError/i.test(msg)
}

/**
 * React.lazy for the app's own pages. After a plugin update the old files are gone, so a tab that was
 * left open asks for a page file that no longer exists and the import fails. The new files are on the
 * server, so one reload of the page fixes it. The reload happens at most once in 30 seconds: if it did
 * not help (a real network problem), the error is shown instead of looping.
 */
export function loadWithReload(importer) {
  return importer().catch((err) => {
    if (!isChunkLoadError(err)) throw err
    let recent
    try {
      const last = Number(window.sessionStorage.getItem(FLAG) || 0)
      recent = Date.now() - last < 30000
      if (!recent) window.sessionStorage.setItem(FLAG, String(Date.now()))
    } catch {
      recent = true // no storage: do not risk a reload loop
    }
    if (recent) throw err
    window.location.reload()
    return new Promise(() => {}) // stay on the loading screen until the page reloads
  })
}

export function lazyPage(importer) {
  return lazy(() => loadWithReload(importer))
}
