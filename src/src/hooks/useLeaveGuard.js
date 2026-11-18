import { createElement, useCallback, useEffect, useLayoutEffect, useRef } from 'react'
import { useBlocker, useLocation } from 'react-router-dom'
import UnsavedChangesBar from '../components/UnsavedChangesBar'
import { shouldBlockLeave } from '../utils/leaveGuard'

// "Unsaved changes" guard of a record form. `dirty` = the values differ from the loaded or saved ones.
// Returns `bar` (render it: the question at the top of the screen while a navigation is held) and
// `allowLeave()`: call it right before the navigate() that follows a successful save or delete, so that
// navigation goes through (the blocker reads refs, not the last render, so the same tick works).
// Reloading or closing the tab gets the browser's own question. Also used by Eleva CRM Pro
// (window.FotonicShared), so it must stay a plain hook with this signature.
export default function useLeaveGuard(dirty) {
  const dirtyRef = useRef(dirty)
  const bypassRef = useRef(false)
  useLayoutEffect(() => { dirtyRef.current = dirty }, [dirty])

  const location = useLocation()
  // A page reached after allowLeave() (a new record saved and opened again) starts guarded again.
  useEffect(() => { bypassRef.current = false }, [location.pathname])

  const blocker = useBlocker(useCallback(
    ({ currentLocation, nextLocation }) =>
      shouldBlockLeave({ dirty: dirtyRef.current, bypass: bypassRef.current, from: currentLocation, to: nextLocation }),
    []
  ))

  useEffect(() => {
    if (!dirty) return undefined
    const warn = (e) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  const allowLeave = useCallback(() => { bypassRef.current = true }, [])
  const stay = useCallback(() => blocker.reset?.(), [blocker])
  // proceed() lets the held navigation through without asking the blocker again.
  const leave = useCallback(() => blocker.proceed?.(), [blocker])

  const bar = blocker.state === 'blocked' ? createElement(UnsavedChangesBar, { onStay: stay, onLeave: leave }) : null
  return { allowLeave, bar }
}
