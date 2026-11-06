import { useState } from 'react'
import { CheckCircle2, AlertTriangle, XCircle } from 'lucide-react'
import Button from '../../components/Button'
import ConfirmDialog from '../../components/ConfirmDialog'
import { __, sprintf } from '../../utils/i18n'
import { useRestoreActions } from './restoreJob'
import { datasetLabel } from './restoreUtils'

function Line({ children }) {
  return <li>{children}</li>
}

/**
 * The end of a restore (or of an undo): what was done, what was not, and the last choice: keep the result (the way
 * back closes) or undo it. A "replace everything" restore has no undo: the report names the safety copy instead.
 */
export default function RestoreReport({ job, onClose }) {
  const actions = useRestoreActions()
  const [confirmUndo, setConfirmUndo] = useState(false)
  const [working, setWorking] = useState(false)
  const [error, setError] = useState('')

  const report = job.report ?? {}
  const counts = job.counts ?? {}
  const files = report.files ?? {}
  const total = job.mode === 'total'
  const status = job.status
  const failedFiles = (files.missing ?? 0) + (files.rejected ?? 0) + (files.damaged ?? 0)
  const notRestored = (report.unimportable ?? []).map((u) => datasetLabel(u.dataset)).filter((v, i, a) => a.indexOf(v) === i)

  async function run(fn) {
    setWorking(true)
    setError('')
    try {
      await fn(job.job_id)
      return true
    } catch (err) {
      setError(err?.message || __('Something went wrong. Try again.', 'eleva-crm-for-photographers'))
      return false
    } finally {
      setWorking(false)
    }
  }

  async function keep() {
    if (await run(actions.dismiss)) onClose()
  }

  if (status === 'undone') {
    return (
      <div className="space-y-4">
        <p className="text-sm text-green-800 bg-green-50 border border-green-200 rounded px-3 py-2 flex items-start gap-2">
          <CheckCircle2 size={14} className="shrink-0 mt-0.5" />
          {__('The restore was undone. Your data is as it was before.', 'eleva-crm-for-photographers')}
        </p>
        {error && <p className="text-xs text-red-600">{error}</p>}
        <Button variant="primary" disabled={working} onClick={keep}>{__('Close', 'eleva-crm-for-photographers')}</Button>
      </div>
    )
  }

  const failed = status === 'error' || status === 'expired'
  return (
    <div className="space-y-5">
      {failed ? (
        <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2 flex items-start gap-2">
          <XCircle size={14} className="shrink-0 mt-0.5" />
          <div className="space-y-1">
            <p className="font-medium">{__('The restore stopped before the end.', 'eleva-crm-for-photographers')}</p>
            {job.error && <p>{job.error}</p>}
            {!total && <p>{__('What was already restored can be kept or undone.', 'eleva-crm-for-photographers')}</p>}
          </div>
        </div>
      ) : (
        <p className="text-sm text-green-800 bg-green-50 border border-green-200 rounded px-3 py-2 flex items-start gap-2">
          <CheckCircle2 size={14} className="shrink-0 mt-0.5" />
          {__('The restore is finished.', 'eleva-crm-for-photographers')}
        </p>
      )}

      <ul className="text-sm text-gray-700 space-y-1 list-disc list-inside">
        {counts.created > 0 && <Line>{sprintf(__('%d records added.', 'eleva-crm-for-photographers'), counts.created)}</Line>}
        {counts.copied > 0 && <Line>{sprintf(__('%d records added as separate copies.', 'eleva-crm-for-photographers'), counts.copied)}</Line>}
        {counts.overwritten > 0 && <Line>{sprintf(__('%d records replaced by the version in the backup.', 'eleva-crm-for-photographers'), counts.overwritten)}</Line>}
        {counts.kept > 0 && <Line>{sprintf(__('%d records left as they were.', 'eleva-crm-for-photographers'), counts.kept)}</Line>}
        {counts.skipped > 0 && <Line>{sprintf(__('%d records left out, as you chose.', 'eleva-crm-for-photographers'), counts.skipped)}</Line>}
        {total && job.deleted > 0 && <Line>{sprintf(__('%d records removed to make room for the backup.', 'eleva-crm-for-photographers'), job.deleted)}</Line>}
        {files.created > 0 && <Line>{sprintf(__('%d files brought back.', 'eleva-crm-for-photographers'), files.created)}</Line>}
        {files.reused > 0 && <Line>{sprintf(__('%d files were already on this site and were used again.', 'eleva-crm-for-photographers'), files.reused)}</Line>}
      </ul>

      {(failedFiles > 0 || report.attachments > 0 || report.unreadable > 0 || report.unmapped > 0 || notRestored.length > 0 || report.gcal
        || (report.refs_skipped ?? []).length > 0 || (report.options_skipped ?? []).length > 0 || (report.unknown_fields ?? []).length > 0) && (
        <div className="rounded-md bg-amber-50 border border-amber-200 p-4 space-y-2">
          <p className="text-sm font-medium text-amber-900 flex items-center gap-2">
            <AlertTriangle size={14} className="shrink-0" />
            {__('Not everything could be restored', 'eleva-crm-for-photographers')}
          </p>
          <ul className="text-xs text-amber-900 space-y-1 list-disc list-inside">
            {failedFiles > 0 && <Line>{sprintf(__('%d files could not be restored because they were missing or damaged in the backup.', 'eleva-crm-for-photographers'), failedFiles)}</Line>}
            {report.attachments > 0 && <Line>{sprintf(__('%d file entries were left out of their works because the file was not in the backup.', 'eleva-crm-for-photographers'), report.attachments)}</Line>}
            {report.unreadable > 0 && <Line>{sprintf(__('%d details could not be read and were left empty.', 'eleva-crm-for-photographers'), report.unreadable)}</Line>}
            {report.unmapped > 0 && <Line>{sprintf(__('%d links to other records could not be rebuilt.', 'eleva-crm-for-photographers'), report.unmapped)}</Line>}
            {notRestored.length > 0 && <Line>{sprintf(__('Not restored by this version: %s.', 'eleva-crm-for-photographers'), notRestored.join(', '))}</Line>}
            {(report.refs_skipped ?? []).length > 0 && <Line>{__('Some links from your works to Eleva CRM Pro data were left out.', 'eleva-crm-for-photographers')}</Line>}
            {(report.options_skipped ?? []).includes('fotonic_quote_pdf_settings') && <Line>{__('The look of your quote PDFs could not be restored from the backup, because it uses images that a backup cannot bring back. What you have now stays as it is. To use the old look, set it up again in Settings.', 'eleva-crm-for-photographers')}</Line>}
            {(report.options_skipped ?? []).includes('fotonic_owner_signature') && <Line>{__('Your signature could not be restored from the backup, because it is an image that a backup cannot bring back. What you have now stays as it is. To use it again, upload it in Settings.', 'eleva-crm-for-photographers')}</Line>}
            {(report.options_skipped ?? []).some((name) => name !== 'fotonic_quote_pdf_settings' && name !== 'fotonic_owner_signature') && <Line>{__('Some settings of Eleva CRM Pro could not be restored. Check them in Settings.', 'eleva-crm-for-photographers')}</Line>}
            {(report.unknown_fields ?? []).length > 0 && <Line>{__('Some details of Eleva CRM Pro (such as collaborators or suppliers on your works) were not restored, because Eleva CRM Pro is not active on this site.', 'eleva-crm-for-photographers')}</Line>}
            {report.gcal && <Line>{__('The connection with Google Calendar is not restored. Events already on your calendar were left as they are.', 'eleva-crm-for-photographers')}</Line>}
          </ul>
        </div>
      )}

      {job.safety_file && (
        <p className="text-xs text-gray-600">
          {total
            ? sprintf(__('Your data from before the restore was saved in the safety copy %s. It is in the list of backups saved on this site: choose it in "Restore from a backup" to go back.', 'eleva-crm-for-photographers'), job.safety_file)
            : sprintf(__('A safety copy of your data from before the restore was saved: %s.', 'eleva-crm-for-photographers'), job.safety_file)}
        </p>
      )}
      {total && !failed && (
        <p className="text-xs text-gray-600">{__('A restore that replaces everything cannot be undone with a button.', 'eleva-crm-for-photographers')}</p>
      )}

      {error && <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error}</p>}

      <div className="flex flex-wrap gap-3">
        <Button variant="primary" disabled={working} onClick={keep}>
          {total ? __('Close', 'eleva-crm-for-photographers') : __('Keep the result and close', 'eleva-crm-for-photographers')}
        </Button>
        {job.undoable && (
          <Button variant="secondary" disabled={working} onClick={() => setConfirmUndo(true)}>
            {__('Undo this restore', 'eleva-crm-for-photographers')}
          </Button>
        )}
      </div>
      {job.undoable && (
        <p className="text-xs text-gray-500">
          {__('Once you keep the result, the restore can no longer be undone.', 'eleva-crm-for-photographers')}
        </p>
      )}

      <ConfirmDialog
        open={confirmUndo}
        onClose={() => setConfirmUndo(false)}
        onConfirm={() => run(actions.undo)}
        title={__('Undo this restore?', 'eleva-crm-for-photographers')}
        message={__('The records added by the restore are removed, and the ones it replaced go back to how they were. Everything else stays.', 'eleva-crm-for-photographers')}
        confirmLabel={__('Undo the restore', 'eleva-crm-for-photographers')}
      />
    </div>
  )
}
