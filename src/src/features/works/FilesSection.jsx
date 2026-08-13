import { useEffect, useRef, useState } from 'react'
import { Download, Trash2, Upload, PlusCircle, Link as LinkIcon, Eye, ExternalLink, Pencil } from 'lucide-react'
import Button from '../../components/Button'
import Modal from '../../components/Modal'
import FilePreviewModal from '../../components/FilePreviewModal'
import { fetchFileBlob } from '../../api/files'
import { __, sprintf } from '../../utils/i18n'

const ALLOWED_TYPES = [
  'image',
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
]
const MAX_BYTES = window.FotonicApp?.maxUploadBytes ?? 10 * 1024 * 1024

function displayNameOf(file) {
  const type = file.type ?? 'attachment'
  if (file.label) return file.label
  return type === 'link' ? file.url : file.filename
}

function hostnameOf(url) {
  try {
    return new URL(url).hostname
  } catch {
    return url
  }
}

function formatBytes(bytes) {
  const mb = Math.round((bytes / (1024 * 1024)) * 10) / 10
  if (mb >= 1) return `${mb} MB`
  return `${Math.max(1, Math.round(bytes / 1024))} KB`
}

export default function FilesSection({ value = [], onChange }) {
  const [downloadingId, setDownloadingId] = useState(null)
  const [previewFile, setPreviewFile] = useState(null)
  const [menuOpen, setMenuOpen] = useState(false)
  const [linkFormOpen, setLinkFormOpen] = useState(false)
  const [linkUrl, setLinkUrl] = useState('')
  const [linkLabel, setLinkLabel] = useState('')
  const [editingIdx, setEditingIdx] = useState(null)
  const [labelDraft, setLabelDraft] = useState('')
  const menuRef = useRef(null)

  useEffect(() => {
    if (!menuOpen) return undefined
    function handleClickOutside(e) {
      if (menuRef.current && !menuRef.current.contains(e.target)) setMenuOpen(false)
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [menuOpen])

  function removeFileAt(idx) {
    onChange(value.filter((_, i) => i !== idx))
  }

  function updateLabelAt(idx, label) {
    onChange(value.map((f, i) => (i === idx ? { ...f, label } : f)))
  }

  function startEditLabel(idx, file) {
    setLabelDraft(file.label || '')
    setEditingIdx(idx)
  }

  function commitLabel(idx) {
    updateLabelAt(idx, labelDraft.trim())
    setEditingIdx(null)
  }

  async function handleDownload(file) {
    setDownloadingId(file.id)
    try {
      const blob = await fetchFileBlob(file)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = file.label || file.filename
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      window.alert(err.message || __('Unable to download file.', 'eleva-crm-for-photographers'))
    } finally {
      setDownloadingId(null)
    }
  }

  function openMediaFrame() {
    setMenuOpen(false)
    if (!window.wp || !window.wp.media) return

    const frame = window.wp.media({
      title: __('Select Files', 'eleva-crm-for-photographers'),
      button: { text: __('Add to Work', 'eleva-crm-for-photographers') },
      multiple: true,
      library: { type: ALLOWED_TYPES },
    })

    frame.on('select', () => {
      const selected = frame.state().get('selection').toJSON()

      const oversized = selected.filter((a) => (a.filesizeInBytes ?? 0) > MAX_BYTES)
      if (oversized.length > 0) {
        const names = oversized.map((a) => a.filename).join(', ')
        /* translators: %s is a file size, e.g. "50 MB". */
        const limitMessage = sprintf(__('These files exceed the %s limit and were skipped:', 'eleva-crm-for-photographers'), formatBytes(MAX_BYTES))
        window.alert(`${limitMessage}
${names}`)
      }

      const valid = selected.filter((a) => (a.filesizeInBytes ?? 0) <= MAX_BYTES)
      const newFiles = valid.map((a) => ({
        type: 'attachment',
        id: a.id,
        url: a.url,
        filename: a.filename,
        mime: a.mime,
        label: '',
      }))

      const existingIds = new Set(
        value.filter((f) => (f.type ?? 'attachment') === 'attachment').map((f) => f.id)
      )
      const merged = [...value, ...newFiles.filter((f) => !existingIds.has(f.id))]
      onChange(merged)
    })

    frame.open()
  }

  function submitLink(e) {
    e.preventDefault()
    const url = linkUrl.trim()
    if (!url) return
    onChange([...value, { type: 'link', url, label: linkLabel.trim() }])
    setLinkUrl('')
    setLinkLabel('')
    setLinkFormOpen(false)
  }

  return (
    <div className="ftnc-fields">
      {value.length > 0 ? (
        <div className="ftnc-table-scroll">
          <table className="min-w-full divide-y divide-gray-200 bg-white text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">{__('Filename', 'eleva-crm-for-photographers')}</th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">{__('Type', 'eleva-crm-for-photographers')}</th>
                <th className="px-4 py-2 w-24"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {value.map((file, index) => {
                const type = file.type ?? 'attachment'
                const isLink = type === 'link'
                const isPreviewable = !isLink && (file.mime === 'application/pdf' || file.mime?.startsWith('image/'))
                const name = displayNameOf(file)

                return (
                  <tr key={index}>
                    <td className="px-4 py-2 text-gray-700">
                      {editingIdx === index ? (
                        <input
                          autoFocus
                          type="text"
                          value={labelDraft}
                          onChange={(e) => setLabelDraft(e.target.value)}
                          onBlur={() => commitLabel(index)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') e.currentTarget.blur()
                            if (e.key === 'Escape') setEditingIdx(null)
                          }}
                          placeholder={name}
                          className="w-full rounded border border-indigo-300 px-1.5 py-0.5 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                      ) : (
                        <div className="flex items-center gap-1.5 group">
                          {isPreviewable ? (
                            <button
                              type="button"
                              onClick={() => setPreviewFile(file)}
                              className="font-bold text-gray-800 hover:underline inline-flex items-center gap-1"
                            >
                              {name}
                              <Eye size={12} className="opacity-60" />
                            </button>
                          ) : isLink ? (
                            <button
                              type="button"
                              onClick={() => window.open(file.url, '_blank', 'noopener,noreferrer')}
                              className="font-bold text-gray-800 hover:underline inline-flex items-center gap-1"
                            >
                              {name}
                              <ExternalLink size={12} className="opacity-60" />
                            </button>
                          ) : (
                            <span className="font-bold text-gray-800">{name}</span>
                          )}
                          <button
                            type="button"
                            onClick={() => startEditLabel(index, file)}
                            className="text-gray-300 hover:text-gray-500 opacity-0 group-hover:opacity-100 transition-opacity"
                            aria-label={__('Rename', 'eleva-crm-for-photographers')}
                          >
                            <Pencil size={11} />
                          </button>
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-gray-500 text-xs">
                      {isLink ? `${__('Link', 'eleva-crm-for-photographers')} · ${hostnameOf(file.url)}` : file.mime}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex items-center gap-2">
                        {!isLink && (
                          <Button
                            type="button"
                            variant="primary"
                            size="sm"
                            onClick={() => handleDownload(file)}
                            disabled={downloadingId === file.id}
                            aria-label={__('Download file', 'eleva-crm-for-photographers')}
                          >
                            <Download size={14} />
                          </Button>
                        )}
                        <Button type="button" variant="danger" size="sm" onClick={() => removeFileAt(index)} aria-label={__('Remove file', 'eleva-crm-for-photographers')}>
                          <Trash2 size={14} />
                        </Button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      ) : (
        <p className="text-sm text-gray-400">{__('No files attached.', 'eleva-crm-for-photographers')}</p>
      )}

      <div>
        <div className="relative inline-block" ref={menuRef}>
          <Button type="button" variant="secondary" size="sm" onClick={() => setMenuOpen((v) => !v)}>
            <PlusCircle size={14} />
            {__('Add File', 'eleva-crm-for-photographers')}
          </Button>
          {menuOpen && (
            <div className="absolute z-10 mt-1 w-56 rounded-md border border-gray-200 bg-white shadow-lg py-1">
              <button
                type="button"
                onClick={openMediaFrame}
                className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2 text-gray-700"
              >
                <Upload size={14} />
                {__('Upload from Media Library', 'eleva-crm-for-photographers')}
              </button>
              <button
                type="button"
                onClick={() => {
                  setMenuOpen(false)
                  setLinkFormOpen(true)
                }}
                className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2 text-gray-700"
              >
                <LinkIcon size={14} />
                {__('Add Link', 'eleva-crm-for-photographers')}
              </button>
            </div>
          )}
        </div>
        <p className="text-xs text-gray-400 mt-1 ftnc-prose">
          {/* translators: %s is a file size, e.g. "50 MB". */}
          {sprintf(__('Images and PDF can be previewed after upload · DOC/DOCX and links open in a new tab · max %s per uploaded file.', 'eleva-crm-for-photographers'), formatBytes(MAX_BYTES))}
        </p>
      </div>

      <Modal open={linkFormOpen} onClose={() => setLinkFormOpen(false)} title={__('Add Link', 'eleva-crm-for-photographers')}>
        {/* Plain div, not <form>: this is portaled by Radix Dialog, but React bubbles
            events along the component tree (not the DOM tree) — a nested <form>'s submit
            would still reach WorkForm's outer <form onSubmit> and save/redirect the whole work. */}
        <div className="ftnc-fields">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{__('URL', 'eleva-crm-for-photographers')}</label>
            <input
              type="url"
              required
              autoFocus
              value={linkUrl}
              onChange={(e) => setLinkUrl(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && submitLink(e)}
              placeholder="https://drive.google.com/…"
              className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{__('Label (optional)', 'eleva-crm-for-photographers')}</label>
            <input
              type="text"
              value={linkLabel}
              onChange={(e) => setLinkLabel(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && submitLink(e)}
              placeholder={__('e.g. Contract', 'eleva-crm-for-photographers')}
              className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" size="sm" onClick={() => setLinkFormOpen(false)}>
              {__('Cancel', 'eleva-crm-for-photographers')}
            </Button>
            <Button type="button" variant="primary" size="sm" onClick={submitLink}>
              {__('Add', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        </div>
      </Modal>

      <FilePreviewModal file={previewFile} onClose={() => setPreviewFile(null)} />
    </div>
  )
}
