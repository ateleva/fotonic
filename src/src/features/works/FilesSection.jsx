import { useEffect, useRef, useState } from 'react'
import { Download, Trash2, Upload, PlusCircle, Link as LinkIcon, Eye, ExternalLink, Pencil, Loader2 } from 'lucide-react'
import Button from '../../components/Button'
import RowReorder from '../../components/RowReorder'
import { moveRow } from '../../utils/moveRow'
import Modal from '../../components/Modal'
import FilePreviewModal from '../../components/FilePreviewModal'
import { fetchFileBlob, uploadWorkFile } from '../../api/files'
import { __, sprintf } from '../../utils/i18n'

// What the file picker offers; the server checks the real content again.
const ACCEPT = 'image/*,.pdf,.doc,.docx'
const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'heic', 'tif', 'tiff', 'bmp']
const MAX_BYTES = window.FotonicApp?.maxUploadBytes ?? 10 * 1024 * 1024

function displayNameOf(file) {
  const type = file.type ?? 'attachment'
  if (file.label) return file.label
  if (type === 'link') return file.url
  // null = the name is protected and cannot be read now (never an empty text).
  return file.filename ?? __('Name not readable', 'eleva-crm-for-photographers')
}

// "contract.pdf" -> { stem: 'contract', ext: '.pdf' }. A name with no real extension has an empty ext.
function splitExt(name) {
  const dot = name.lastIndexOf('.')
  return dot > 0 && dot < name.length - 1 ? { stem: name.slice(0, dot), ext: name.slice(dot) } : { stem: name, ext: '' }
}

// The type of an uploaded file (".pdf") stays when its name is changed: only the name part is editable.
function lockedExtOf(file) {
  return (file.type ?? 'attachment') === 'link' ? '' : splitExt(file.filename ?? '').ext
}

function extensionOf(name) {
  const dot = name.lastIndexOf('.')
  return dot < 0 ? '' : name.slice(dot + 1).toLowerCase()
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
  const [uploading, setUploading] = useState(0)
  const [uploadError, setUploadError] = useState(null)
  const menuRef = useRef(null)
  const inputRef = useRef(null)
  const valueRef = useRef(value)
  useEffect(() => { valueRef.current = value }, [value])

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

  function moveFileBy(idx, dir) {
    onChange(moveRow(value, idx, dir))
  }

  function updateLabelAt(idx, label) {
    onChange(value.map((f, i) => (i === idx ? { ...f, label } : f)))
  }

  function startEditLabel(idx, file) {
    const ext = lockedExtOf(file)
    const shown = displayNameOf(file)
    const stem = ext && shown.toLowerCase().endsWith(ext.toLowerCase()) ? shown.slice(0, shown.length - ext.length) : shown
    setLabelDraft(stem)
    setEditingIdx(idx)
  }

  function commitLabel(idx) {
    const file = value[idx]
    const ext = file ? lockedExtOf(file) : ''
    const draft = labelDraft.trim()
    let label = ''
    if (draft) {
      label = ext && !draft.toLowerCase().endsWith(ext.toLowerCase()) ? draft + ext : draft
    }
    if (file && label === file.filename) label = '' // the real name again: no label needed
    updateLabelAt(idx, label)
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

  function openFilePicker() {
    setMenuOpen(false)
    setUploadError(null)
    inputRef.current?.click()
  }

  async function handleFilesChosen(e) {
    const chosen = Array.from(e.target.files ?? [])
    e.target.value = '' // the same file can be chosen again
    if (chosen.length === 0) return
    setUploadError(null)

    const problems = []
    const toSend = []
    for (const file of chosen) {
      if (!ALLOWED_EXT.includes(extensionOf(file.name))) {
        problems.push(sprintf(__('%s is not a PDF, a Word document or an image.', 'eleva-crm-for-photographers'), file.name))
      } else if (file.size > MAX_BYTES) {
        /* translators: 1: file name, 2: a file size, e.g. "10 MB". */
        problems.push(sprintf(__('%1$s is bigger than the %2$s limit.', 'eleva-crm-for-photographers'), file.name, formatBytes(MAX_BYTES)))
      } else {
        toSend.push(file)
      }
    }

    const added = []
    setUploading((n) => n + toSend.length)
    for (const file of toSend) {
      try {
        const entry = await uploadWorkFile(file)
        added.push({ type: 'attachment', id: entry.id, filename: entry.filename, mime: entry.mime, label: '' })
      } catch (err) {
        problems.push(`${file.name}: ${err.message || __('The file could not be uploaded.', 'eleva-crm-for-photographers')}`)
      } finally {
        setUploading((n) => n - 1)
      }
    }
    if (added.length > 0) onChange([...valueRef.current, ...added])
    if (problems.length > 0) setUploadError(problems.join('\n'))
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
                <th className="px-4 py-2 w-40"></th>
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
                        <div className="flex items-center gap-1">
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
                            placeholder={splitExt(file.filename ?? name).stem}
                            className="w-full rounded border border-indigo-300 px-1.5 py-0.5 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500"
                          />
                          {lockedExtOf(file) && <span className="text-sm text-gray-500 whitespace-nowrap">{lockedExtOf(file)}</span>}
                        </div>
                      ) : (
                        <div className="flex items-center gap-1 group">
                          {isPreviewable ? (
                            <button
                              type="button"
                              onClick={() => setPreviewFile(file)}
                              className="font-bold text-gray-800 hover:underline inline-flex items-center gap-1"
                            >
                              {name}
                              <span className="ml-2 inline-flex items-center justify-center rounded bg-fotonic-primary p-1 text-white">
                                <Eye size={13} />
                              </span>
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
                            className="inline-flex items-center justify-center rounded border border-gray-300 bg-gray-100 p-1 text-gray-900 hover:bg-gray-200 transition-colors"
                            aria-label={__('Rename', 'eleva-crm-for-photographers')}
                          >
                            <Pencil size={13} />
                          </button>
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-gray-500 text-xs">
                      {isLink ? `${__('Link', 'eleva-crm-for-photographers')} · ${hostnameOf(file.url)}` : file.mime}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex items-center gap-2 whitespace-nowrap">
                        {/* editingIdx is a row position: no moving while a name is being edited. */}
                        <RowReorder index={index} count={value.length} onMove={moveFileBy} disabled={editingIdx !== null} />
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
                onClick={openFilePicker}
                className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center gap-2 text-gray-700"
              >
                <Upload size={14} />
                {__('Upload a file', 'eleva-crm-for-photographers')}
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
        <input ref={inputRef} type="file" multiple accept={ACCEPT} className="hidden" onChange={handleFilesChosen} />
        {uploading > 0 && (
          <p className="text-xs text-gray-500 mt-1 inline-flex items-center gap-1">
            <Loader2 size={12} className="animate-spin" />
            {__('Uploading…', 'eleva-crm-for-photographers')}
          </p>
        )}
        {uploadError && (
          <p role="alert" className="text-xs text-red-600 mt-1 whitespace-pre-line">{uploadError}</p>
        )}
        <p className="text-xs text-gray-400 mt-1">
          {/* translators: %s is a file size, e.g. "50 MB". */}
          {sprintf(__('Images and PDF can be previewed after upload · DOC/DOCX and links open in a new tab · max %s per uploaded file.', 'eleva-crm-for-photographers'), formatBytes(MAX_BYTES))}
        </p>
        <p className="text-xs text-gray-400 mt-1">
          {__('Renaming a file only changes the name shown in this list: the type of file (for example .pdf) stays the same.', 'eleva-crm-for-photographers')}
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
