import { useCallback, useEffect, useState } from 'react'

/**
 * Collapsed/expanded state for the app sidebar.
 *
 * Precedence rule, in full (the subtlety is the whole point of this hook):
 *
 *   - At >= 1200px the persisted preference is the source of truth.
 *   - Below 1200px the app force-collapses, but ONLY on the threshold *crossing*
 *     (a matchMedia `change` event, never a `resize` handler), and that
 *     force-collapse never writes to storage.
 *   - A manual toggle always wins immediately, at any width, and IS persisted.
 *
 * Net effect: after the user manually re-opens the sidebar at a narrow width,
 * nothing silently re-collapses it while they keep working there — the only
 * thing that could is another crossing, and they are already past it. And
 * because auto-collapse never writes, growing back over 1200px restores what
 * the user actually chose rather than what the viewport imposed.
 *
 * There is no localStorage precedent anywhere else in the free plugin, so this
 * establishes it: every access is wrapped, because Safari in private mode
 * throws on `setItem` rather than failing quietly.
 */

const STORAGE_KEY = 'ftnc.sidebar.collapsed'
const NARROW_QUERY = '(max-width: 1200px)'

function readStored() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (raw === 'true') return true
    if (raw === 'false') return false
  } catch {
    /* storage disabled or quota-throwing (Safari private mode) — fall through */
  }
  return null
}

function writeStored(value) {
  try {
    window.localStorage.setItem(STORAGE_KEY, value ? 'true' : 'false')
  } catch {
    /* same: persistence is best-effort, never load-bearing */
  }
}

function matchesNarrow() {
  return typeof window.matchMedia === 'function' && window.matchMedia(NARROW_QUERY).matches
}

export default function useSidebarCollapse() {
  const [collapsed, setCollapsed] = useState(() => {
    // Booting below the threshold counts as the first crossing: start collapsed
    // regardless of the stored preference, and leave storage untouched.
    if (matchesNarrow()) return true
    return readStored() ?? false
  })

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return
    const mql = window.matchMedia(NARROW_QUERY)

    const onCrossing = (e) => {
      // Re-read storage on every crossing rather than closing over a value, so a
      // manual toggle made since the last crossing is what gets restored.
      setCollapsed(e.matches ? true : (readStored() ?? false))
    }

    // Safari < 14 exposes only the deprecated addListener/removeListener pair.
    if (mql.addEventListener) mql.addEventListener('change', onCrossing)
    else mql.addListener(onCrossing)

    return () => {
      if (mql.removeEventListener) mql.removeEventListener('change', onCrossing)
      else mql.removeListener(onCrossing)
    }
  }, [])

  const toggle = useCallback(() => {
    setCollapsed((prev) => {
      const next = !prev
      writeStored(next)
      return next
    })
  }, [])

  return [collapsed, toggle]
}
