import { useCallback, useEffect, useLayoutEffect, useRef } from 'react'
import { shouldPushValue, shouldReport, pushKnown, ackKnown, receiveKnown } from './editorSync'

const EDITOR_ID = 'ftnc_work_notes_mce'

function editorHasFocus(editor, flag) {
  if (flag) return true
  try {
    return typeof editor.hasFocus === 'function' && editor.hasFocus()
  } catch {
    return false
  }
}

function selectionBookmark(editor) {
  try {
    return editor.selection.getBookmark(2, true)
  } catch {
    return null
  }
}

// setContent() puts the caret at the very start: remember where it was and put it back.
function setEditorHtml(editor, html) {
  const bookmark = selectionBookmark(editor)
  editor.setContent(html || '')
  if (bookmark) {
    try {
      editor.selection.moveToBookmark(bookmark)
    } catch {
      // The new text has no such position: the caret stays where setContent left it.
    }
  }
}

// TinyMCE field for the notes of a work. The editor is the source of its own text: it reports its
// HTML through onChange and the form echoes it back as `value`. That echo (and any value the editor
// already holds, or that arrives while the admin is typing) is never written back with setContent():
// it would throw the caret to the start of the field and eat the characters typed meanwhile (the
// render that carries a value can be committed after the editor moved on by a few characters).
// See shouldPushValue() in editorSync.js, which Eleva CRM Pro's editors use too.
export default function WpEditor({ value = '', onChange }) {
  const onChangeRef = useRef(onChange)
  const valueRef = useRef(value)
  const editorRef = useRef(null)
  const focusedRef = useRef(false)
  // Recent texts the editor was given (at start-up, by a push) or reported through onChange: any of
  // them can still come back as `value` after the editor moved on.
  const knownRef = useRef([])
  useEffect(() => {
    onChangeRef.current = onChange
    valueRef.current = value
  })

  // The editor reports `html` to the form.
  const report = useCallback((html) => {
    knownRef.current = pushKnown(knownRef.current, html)
    onChangeRef.current?.(html)
  }, [])

  // Write the latest `value` into the editor if (and only if) that is a real outside change
  // (the work loaded, the form reset after a save).
  const syncEditor = useCallback(() => {
    const editor = editorRef.current
    if (!editor || !editor.initialized || editor.removed) return
    const next = valueRef.current || ''
    const current = editor.getContent()
    knownRef.current = receiveKnown(ackKnown(knownRef.current, next), next, current)
    const focused = editorHasFocus(editor, focusedRef.current)
    if (!shouldPushValue({ value: next, current, known: knownRef.current, focused })) return
    knownRef.current = [next]
    setEditorHtml(editor, next)
  }, [])

  // A layout effect: its cleanup then runs while React removes the field but the iframe is still
  // attached (a dead editor left registered under the id would make the next init skip the textarea).
  useLayoutEffect(() => {
    if (typeof window === 'undefined' || !window.tinymce) return undefined
    let instance = null

    window.tinymce.init({
      selector: `#${EDITOR_ID}`,
      height: 280,
      menubar: false,
      branding: false,
      plugins: 'lists link',
      toolbar: 'bold italic underline | bullist numlist | link | removeformat',
      content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 14px; margin: 8px; }',
      setup(editor) {
        instance = editor
        editorRef.current = editor
        focusedRef.current = false
        knownRef.current = []
        editor.on('init', () => {
          const start = valueRef.current || ''
          knownRef.current = [start]
          editor.setContent(start)
        })
        editor.on('focus', () => {
          focusedRef.current = true
        })
        editor.on('blur', () => {
          focusedRef.current = false
          // A value that arrived while the admin was typing is applied now that the editor is idle.
          syncEditor()
        })
        editor.on('change input keyup', () => {
          // keyup also fires for arrow keys: only report a text different from the last one reported or
          // received (not from the lagging `value`: see shouldReport).
          const html = editor.getContent()
          if (shouldReport({ html, known: knownRef.current })) report(html)
        })
      },
    })

    return () => {
      editorRef.current = null
      const editor = instance || window.tinymce?.get(EDITOR_ID)
      if (!editor) return
      try {
        editor.remove()
      } catch {
        // The host node may already be detached when React unmounts the parent: nothing left to clean.
      }
      try {
        // A remove() that failed half-way leaves the dead editor registered under this id.
        const manager = window.tinymce?.EditorManager
        if (manager && manager.get(EDITOR_ID) === editor) {
          editor.removed = true
          manager.remove(editor)
        }
      } catch {
        // Nothing more can be done for an editor TinyMCE refuses to forget.
      }
    }
  }, [syncEditor, report])

  // Later value changes (hydration, save response): see syncEditor().
  useEffect(() => {
    syncEditor()
  }, [syncEditor, value])

  return (
    <textarea
      id={EDITOR_ID}
      defaultValue={value}
      className="w-full"
    />
  )
}
