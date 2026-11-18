// Pure helpers of the "unsaved changes" guard (hooks/useLeaveGuard.js). No React here, so a node script tests them.

function stable(value) {
  if (Array.isArray(value)) return value.map(stable)
  if (value && typeof value === 'object') {
    const out = {}
    for (const key of Object.keys(value).sort()) out[key] = stable(value[key])
    return out
  }
  return value
}

// The same text for the same values, whatever the order of the keys: a form compares the fingerprint of what it
// holds now with the one of what was loaded or saved.
export function fingerprint(value) {
  return JSON.stringify(stable(value)) ?? ''
}

// Leaving is stopped only with changes that are not saved, when no save or delete has just allowed it, and only
// for another page (a change of the query alone stays on the same record).
export function shouldBlockLeave({ dirty, bypass, from, to } = {}) {
  if (!dirty || bypass || !from || !to) return false
  return from.pathname !== to.pathname
}
