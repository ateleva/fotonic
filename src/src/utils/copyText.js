// Copy text to the system clipboard. Returns true when it worked.
// Uses the Clipboard API when the page allows it (HTTPS plus a user gesture) and falls back
// to a hidden textarea and execCommand('copy'), which also works over plain HTTP and on
// older Safari. The system clipboard is what Apple's Universal Clipboard syncs, so a value
// copied this way on a Mac can be pasted on an iPhone signed in to the same Apple ID.
export async function copyText(text) {
  const value = String(text ?? '')

  if (window.isSecureContext && navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(value)
      return true
    } catch {
      // Permission denied or no user gesture: try the legacy path below.
    }
  }

  const area = document.createElement('textarea')
  area.value = value
  area.setAttribute('readonly', '')
  area.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none'
  document.body.appendChild(area)
  area.focus()
  area.select()
  area.setSelectionRange(0, value.length)

  let ok
  try {
    ok = document.execCommand('copy')
  } catch {
    ok = false
  }
  document.body.removeChild(area)
  return ok
}
