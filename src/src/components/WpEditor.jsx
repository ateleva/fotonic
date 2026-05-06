import { useEffect, useRef } from 'react'

const EDITOR_ID = 'ftnc_work_notes_mce'

export default function WpEditor({ value = '', onChange }) {
  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange

  const internalChange = useRef(false)

  useEffect(() => {
    if (typeof window === 'undefined' || !window.tinymce) return

    window.tinymce.init({
      selector: `#${EDITOR_ID}`,
      height: 280,
      menubar: false,
      branding: false,
      plugins: 'lists link',
      toolbar: 'bold italic underline | bullist numlist | link | removeformat',
      content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 14px; margin: 8px; }',
      setup(editor) {
        editor.on('init', () => {
          editor.setContent(value || '')
        })
        editor.on('change input keyup', () => {
          internalChange.current = true
          onChangeRef.current(editor.getContent())
          internalChange.current = false
        })
      },
    })

    return () => {
      const editor = window.tinymce?.get(EDITOR_ID)
      if (editor) editor.remove()
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (internalChange.current) return
    const editor = window.tinymce?.get(EDITOR_ID)
    if (!editor) return
    if (editor.getContent() !== (value || '')) {
      editor.setContent(value || '')
    }
  }, [value])

  return (
    <textarea
      id={EDITOR_ID}
      defaultValue={value}
      className="w-full"
    />
  )
}
