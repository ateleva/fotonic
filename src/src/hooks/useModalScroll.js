import { useEffect } from 'react'

// Keeps a scrolling window usable from the keyboard from the moment it opens. The window takes the focus (retried
// for a second, in case the page or the browser moves it away after it mounts), and the arrow, Page Up/Down, Home,
// End and Space keys scroll it wherever the focus is, as long as it is not in a field or on a button.
//
// A window's content is mounted a moment after the component that owns it (it goes through a portal), so the
// element may not exist yet when this runs: it waits for it, up to two seconds.
export default function useModalScroll(listRef) {
  useEffect(() => {
    let detach = null
    let waited = 0

    const attach = () => {
      const list = listRef.current
      if (!list) return false

      const grab = () => {
        const active = document.activeElement
        if (active !== list && !list.contains(active)) {
          list.focus({ preventScroll: true })
        }
      }
      const timers = [0, 120, 400, 900].map((ms) => window.setTimeout(grab, ms))

      const onKey = (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey) return
        const t = e.target
        if (t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) return
        if ((e.key === ' ' || e.key === 'Enter') && t && t.tagName === 'BUTTON') return
        const page = list.clientHeight * 0.9
        let to
        switch (e.key) {
          case 'ArrowDown': to = list.scrollTop + 48; break
          case 'ArrowUp': to = list.scrollTop - 48; break
          case 'PageDown': to = list.scrollTop + page; break
          case 'PageUp': to = list.scrollTop - page; break
          case ' ': to = list.scrollTop + (e.shiftKey ? -page : page); break
          case 'Home': to = 0; break
          case 'End': to = list.scrollHeight; break
          default: return
        }
        e.preventDefault()
        list.scrollTo({ top: to })
      }
      document.addEventListener('keydown', onKey)

      detach = () => {
        timers.forEach((id) => window.clearTimeout(id))
        document.removeEventListener('keydown', onKey)
      }
      return true
    }

    let poll = null
    if (!attach()) {
      poll = window.setInterval(() => {
        waited += 50
        if (attach() || waited >= 2000) window.clearInterval(poll)
      }, 50)
    }
    return () => {
      if (poll) window.clearInterval(poll)
      if (detach) detach()
    }
  }, [listRef])
}
