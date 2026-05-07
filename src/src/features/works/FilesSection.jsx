import { Download, Trash2, Upload } from 'lucide-react'
import Button from '../../components/Button'

const BASE = window.FotonicApp?.restUrl ?? '/wp-json/fotonic/v1/'

const ALLOWED_TYPES = [
  'image',
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
]
const MAX_BYTES = 10 * 1024 * 1024 // 10 MB

export default function FilesSection({ value = [], onChange }) {
  function removeFile(id) {
    onChange(value.filter((f) => f.id !== id))
  }

  function openMediaFrame() {
    if (!window.wp || !window.wp.media) return

    const frame = window.wp.media({
      title: 'Select Files',
      button: { text: 'Add to Work' },
      multiple: true,
      library: { type: ALLOWED_TYPES },
    })

    frame.on('select', () => {
      const selected = frame.state().get('selection').toJSON()

      const oversized = selected.filter((a) => (a.filesizeInBytes ?? 0) > MAX_BYTES)
      if (oversized.length > 0) {
        const names = oversized.map((a) => a.filename).join(', ')
        // eslint-disable-next-line no-alert
        window.alert(`These files exceed the 10 MB limit and were skipped:\n${names}`)
      }

      const valid = selected.filter((a) => (a.filesizeInBytes ?? 0) <= MAX_BYTES)
      const newFiles = valid.map((a) => ({
        id: a.id,
        url: a.url,
        filename: a.filename,
        mime: a.mime,
      }))

      const existingIds = new Set(value.map((f) => f.id))
      const merged = [...value, ...newFiles.filter((f) => !existingIds.has(f.id))]
      onChange(merged)
    })

    frame.open()
  }

  return (
    <div className="space-y-4">
      {value.length > 0 ? (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200 bg-white text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Filename</th>
                <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                <th className="px-4 py-2 w-24"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {value.map((file) => (
                <tr key={file.id}>
                  <td className="px-4 py-2 text-gray-700">{file.filename}</td>
                  <td className="px-4 py-2 text-gray-500 text-xs">{file.mime}</td>
                  <td className="px-4 py-2">
                    <div className="flex items-center gap-2">
                      <a
                        href={`${BASE}vault-download/${file.id}`}
                        target="_blank"
                        rel="noreferrer"
                        className="text-indigo-600 hover:text-indigo-800 transition-colors"
                        aria-label="Download file"
                      >
                        <Download size={15} />
                      </a>
                      <button
                        type="button"
                        onClick={() => removeFile(file.id)}
                        className="text-gray-400 hover:text-red-500 transition-colors"
                        aria-label="Remove file"
                      >
                        <Trash2 size={15} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <p className="text-sm text-gray-400">No files attached.</p>
      )}

      <div>
        <Button type="button" variant="secondary" size="sm" onClick={openMediaFrame}>
          <Upload size={14} />
          Upload File
        </Button>
        <p className="text-xs text-gray-400 mt-1">
          Images, PDF, DOC, DOCX · max 10 MB each
        </p>
      </div>
    </div>
  )
}
