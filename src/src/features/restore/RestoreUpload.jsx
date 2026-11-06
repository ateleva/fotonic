import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { UploadCloud, FileArchive, Loader, XCircle } from 'lucide-react'
import { fetchRestoreBackups, fetchRestoreSources, fetchRemoteBackup, closeRestore } from '../../api/restore'
import { chunkedUpload } from '../../utils/chunkedUpload'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import { formatBytes, formatWhen } from './restoreUtils'

/**
 * Step 1: pick the backup. Either a file from this computer (sent to the site in small pieces, with a bar, and
 * resumed from where it stopped after a connection problem) or a backup that is already saved on the site.
 */
export default function RestoreUpload({ onChosen, busy, error, autoRemote }) {
  const inputRef = useRef(null)
  const [dragging, setDragging] = useState(false)
  const [upload, setUpload] = useState(null) // { file, uploadId, percent, failed, message }

  const { data: saved } = useQuery({
    queryKey: ['restore-backups'],
    queryFn: fetchRestoreBackups,
    staleTime: 0,
  })
  const backups = saved?.backups ?? []

  // Backups kept elsewhere (Google Drive, with Pro). A source that cannot be listed is just not shown.
  const { data: remote } = useQuery({ queryKey: ['restore-sources'], queryFn: fetchRestoreSources, staleTime: 0, retry: false })
  const groups = (remote?.sources ?? []).filter((g) => g.items.length > 0)

  // An interrupted upload stays on the server (and counts toward the limit of open uploads) until it is dropped:
  // when the person picks another file, or leaves, it is deleted.
  const leftover = useRef('')
  const abortRef = useRef(null)
  useEffect(() => () => {
    abortRef.current?.abort() // a transfer that is still going stops when the person leaves
    if (leftover.current) closeRestore(leftover.current).catch(() => {})
  }, [])
  const dropLeftover = () => {
    abortRef.current?.abort()
    if (leftover.current) closeRestore(leftover.current).catch(() => {})
    leftover.current = ''
  }
  const newSignal = () => {
    abortRef.current = new AbortController()
    return abortRef.current.signal
  }

  async function send(file, uploadId = '', chunkSize = 0) {
    setUpload({ file, name: file.name, size: file.size, uploadId, chunkSize, percent: 0, failed: false, message: '' })
    try {
      const done = await chunkedUpload(file, {
        uploadId,
        chunkSize,
        signal: newSignal(),
        onUploadId: (id) => { leftover.current = id },
        onProgress: ({ percent }) => setUpload((u) => (u ? { ...u, percent } : u)),
      })
      leftover.current = ''
      setUpload(null)
      onChosen({ kind: 'upload', uploadId: done.uploadId, name: file.name, size: file.size })
    } catch (err) {
      if (err.name === 'AbortError') return
      leftover.current = err.uploadId || leftover.current
      setUpload({
        file,
        name: file.name,
        size: file.size,
        chunkSize: err.chunkSize || 0,
        // A refused file cannot be resumed; a dropped connection can.
        uploadId: err.status >= 400 && err.status < 500 && err.status !== 408 && err.status !== 429 ? '' : (err.uploadId || ''),
        percent: 0,
        failed: true,
        message: err.message || __('The file could not be sent. Check your connection and try again.', 'eleva-crm-for-photographers'),
      })
    }
  }

  // A backup that lives somewhere else is first brought into the site, a few pieces at a time, then opened like a file.
  async function sendRemote(group, item, uploadId = '') {
    const base = { remote: { source: group.source, ref: item.ref, group, item }, name: item.name, size: item.size }
    setUpload({ ...base, uploadId, percent: 0, failed: false, message: '' })
    try {
      const done = await fetchRemoteBackup(group.source, item.ref, {
        uploadId,
        signal: newSignal(),
        onUploadId: (id) => { leftover.current = id },
        onProgress: (percent) => setUpload((u) => (u ? { ...u, percent } : u)),
      })
      leftover.current = ''
      setUpload(null)
      onChosen({ kind: 'upload', uploadId: done.uploadId, name: item.created ? formatWhen(item.created) : item.name, size: item.size })
    } catch (err) {
      if (err.name === 'AbortError') return
      leftover.current = err.uploadId || leftover.current
      setUpload({
        ...base,
        uploadId: err.uploadId || '',
        percent: 0,
        failed: true,
        message: err.message || __('The backup could not be brought to your site. Try again.', 'eleva-crm-for-photographers'),
      })
    }
  }

  // Opened from a link that names a remote backup (Pro's "Restore this backup" next to a backup on Google Drive):
  // the parent hands the choice over once, and the ref keeps a second run of the effect (StrictMode) from starting twice.
  const autoStarted = useRef(false)
  useEffect(() => {
    if (!autoRemote || autoStarted.current) return
    autoStarted.current = true
    queueMicrotask(() => sendRemote({ source: autoRemote.source, label: '' }, { ref: autoRemote.ref, name: '', size: 0 }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoRemote])

  function pick(file) {
    if (!file) return
    dropLeftover()
    send(file)
  }

  const sending = Boolean(upload) && !upload.failed

  return (
    <div className="space-y-5">
      {/* One short line for what to do (it depends on what there is below), one for what happens on a clash. */}
      <p className="text-sm text-gray-900">
        {groups.length > 0
          ? __('Choose a backup made on this site or on Google Drive, checking the date carefully, or upload a .zip file.', 'eleva-crm-for-photographers')
          : backups.length > 0
            ? __('Choose one of the backups made on this site, checking the date carefully, or upload a .zip file.', 'eleva-crm-for-photographers')
            : __('Upload the .zip backup file you downloaded from Eleva CRM.', 'eleva-crm-for-photographers')}
      </p>
      <p className="text-sm text-gray-900">
        {__('If the backup clashes with data already on this site, you will be asked what to do, only for those items.', 'eleva-crm-for-photographers')}
      </p>

      <div
        onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
        onDragLeave={() => setDragging(false)}
        onDrop={(e) => { e.preventDefault(); setDragging(false); if (!sending && !busy) pick(e.dataTransfer.files?.[0]) }}
        className={`rounded-lg border-2 border-dashed p-6 text-center ${dragging ? 'border-fotonic-primary bg-gray-50' : 'border-gray-200'}`}
      >
        <UploadCloud size={28} className="mx-auto text-gray-400" aria-hidden="true" />
        <p className="mt-2 text-sm text-gray-700">{__('Drop the backup file here, or', 'eleva-crm-for-photographers')}</p>
        <div className="mt-3">
          <Button variant="primary" onClick={() => inputRef.current?.click()} disabled={sending || busy}>
            {__('Choose a file', 'eleva-crm-for-photographers')}
          </Button>
        </div>
        <input
          ref={inputRef}
          type="file"
          accept=".zip,application/zip"
          className="hidden"
          onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; pick(f) }}
        />
      </div>

      {upload && (
        <div className="rounded-md border border-gray-200 p-4 space-y-2" role="status" aria-live="polite">
          <p className="text-sm text-gray-800 flex items-center gap-2">
            {!upload.failed && <Loader size={14} className="animate-spin shrink-0" />}
            <span className="font-medium break-all">{upload.name || __('Your backup', 'eleva-crm-for-photographers')}</span>
            {upload.size > 0 && <span className="text-gray-400">({formatBytes(upload.size)})</span>}
          </p>
          {!upload.failed && upload.remote && upload.percent === 0 && (
            <>
              {/* The first answer of the server (it asks Google Drive for the file) can take several seconds and says
                  nothing about progress until it arrives: a bar stuck at 0% looks like a frozen page. */}
              <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div className="h-full w-full rounded-full bg-fotonic-primary animate-pulse" />
              </div>
              <p className="text-xs text-gray-500">
                {__('Asking Google Drive for the backup. This can take a few seconds, keep this page open.', 'eleva-crm-for-photographers')}
              </p>
            </>
          )}
          {!upload.failed && !(upload.remote && upload.percent === 0) && (
            <>
              <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div className="h-full rounded-full bg-fotonic-primary transition-all" style={{ width: upload.percent + '%' }} />
              </div>
              <p className="text-xs text-gray-500">
                {upload.remote
                  ? sprintf(__('Bringing the backup to your site: %d%%. Keep this page open.', 'eleva-crm-for-photographers'), upload.percent)
                  : sprintf(__('Sending the file to your site: %d%%. Keep this page open.', 'eleva-crm-for-photographers'), upload.percent)}
              </p>
            </>
          )}
          {upload.failed && (
            <div className="space-y-2">
              <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2 flex items-start gap-1.5">
                <XCircle size={12} className="shrink-0 mt-0.5" />{upload.message}
              </p>
              <Button size="sm" variant="secondary" onClick={() => (upload.remote ? sendRemote(upload.remote.group, upload.remote.item, upload.uploadId) : send(upload.file, upload.uploadId, upload.chunkSize))}>
                {upload.uploadId
                  ? __('Try again from where it stopped', 'eleva-crm-for-photographers')
                  : __('Try again', 'eleva-crm-for-photographers')}
              </Button>
            </div>
          )}
        </div>
      )}

      {busy && !upload && (
        <p className="text-sm text-gray-500 flex items-center gap-2"><Loader size={14} className="animate-spin" />{__('Opening the backup…', 'eleva-crm-for-photographers')}</p>
      )}

      {error && (
        <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2 flex items-start gap-1.5">
          <XCircle size={12} className="shrink-0 mt-0.5" />{error}
        </p>
      )}

      {groups.map((g) => (
        <div key={g.source} className="space-y-2">
          <p className="text-sm font-medium text-gray-900">{sprintf(__('Backups on %s', 'eleva-crm-for-photographers'), g.label)}</p>
          <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
            {g.items.map((item) => (
              <li key={item.ref} className="flex items-center gap-3 px-3 py-2">
                <FileArchive size={16} className="shrink-0 text-gray-400" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm text-gray-800">{formatWhen(item.created) || item.name}</p>
                  <p className="text-xs text-gray-400">{formatBytes(item.size)}</p>
                </div>
                <Button size="sm" variant="secondary" disabled={sending || busy} onClick={() => { dropLeftover(); sendRemote(g, item) }}>
                  {__('Use this backup', 'eleva-crm-for-photographers')}
                </Button>
              </li>
            ))}
          </ul>
        </div>
      ))}

      {backups.length > 0 && (
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-900">{__('Backups already saved on this site', 'eleva-crm-for-photographers')}</p>
          <p className="text-xs text-gray-500">
            {__('No need to send a file: these were made by this site. The safety copy made before a restore is here too.', 'eleva-crm-for-photographers')}
          </p>
          <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
            {backups.map((b) => (
              <li key={b.file} className="flex items-center gap-3 px-3 py-2">
                <FileArchive size={16} className="shrink-0 text-gray-400" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm text-gray-800">{formatWhen(b.modified)}</p>
                  <p className="text-xs text-gray-400">{formatBytes(b.size)}</p>
                </div>
                <Button size="sm" variant="secondary" disabled={sending || busy} onClick={() => onChosen({ kind: 'server', file: b.file, name: formatWhen(b.modified), size: b.size })}>
                  {__('Use this backup', 'eleva-crm-for-photographers')}
                </Button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
