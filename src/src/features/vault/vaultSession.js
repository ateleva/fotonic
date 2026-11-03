// The fingerprint prompt may open by itself only on the lock screen that follows a page load. Once
// the Vault was open in this page, any later lock screen (the Lock button, the idle timer, another
// browser or device that locked) waits for the person to press the button: a prompt that pops up
// right after pressing Lock, or in a window nobody is looking at, would be a nuisance.
let freshLoad = true

export function autoPromptAllowed() {
  return freshLoad
}

export function noteUnlockedHere() {
  freshLoad = false
}
