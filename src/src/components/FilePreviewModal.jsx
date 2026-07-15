import { lazy, Suspense, useEffect, useState } from 'react'
import Modal from './Modal'
import Spinner from './Spinner'
import { fetchFileBlob } from '../api/files'
import { __ } from '../utils/i18n'

const PdfViewer = lazy(() => import('./PdfViewer'))

const IDLE = { forFile: null, status: 'loading', url: null, data: null, error: null }

export default function FilePreviewModal({ file, onClose }) {
  const [result, setResult] = useState(IDLE)

  useEffect(() => {
    if (!file) return undefined

    let cancelled = false
    let objectUrl = null

    fetchFileBlob(file)
      .then(async (blob) => {
        if (cancelled) return
        if (file.mime === 'application/pdf') {
          const buffer = await blob.arrayBuffer()
          if (cancelled) return
          setResult({ forFile: file, status: 'ready', url: null, data: buffer, error: null })
        } else {
          objectUrl = URL.createObjectURL(blob)
          setResult({ forFile: file, status: 'ready', url: objectUrl, data: null, error: null })
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setResult({ forFile: file, status: 'error', url: null, data: null, error: err.message })
        }
      })

    return () => {
      cancelled = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [file])

  // `result` lags one render behind a freshly-opened file until the fetch above resolves —
  // treat that gap as loading instead of resetting state synchronously inside the effect.
  const state = result.forFile === file ? result : IDLE

  const isImage = file?.mime?.startsWith('image/')
  const isPdf = file?.mime === 'application/pdf'

  return (
    <Modal open={!!file} onClose={onClose} title={file?.label || file?.filename || ''} size="full">
      {state.status === 'loading' && (
        <div className="flex items-center justify-center h-full">
          <Spinner />
        </div>
      )}

      {state.status === 'error' && (
        <div className="flex items-center justify-center h-full text-sm text-red-600">
          {state.error || __('Unable to load this file.')}
        </div>
      )}

      {state.status === 'ready' && isPdf && (
        <Suspense
          fallback={
            <div className="flex items-center justify-center h-full">
              <Spinner />
            </div>
          }
        >
          <PdfViewer data={state.data} />
        </Suspense>
      )}

      {state.status === 'ready' && isImage && (
        <div className="flex items-center justify-center h-full">
          <img src={state.url} alt={file.label || file.filename} className="max-h-full max-w-full object-contain" />
        </div>
      )}
    </Modal>
  )
}
