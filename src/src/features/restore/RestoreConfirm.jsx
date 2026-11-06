import { AlertTriangle, ShieldCheck, XCircle } from 'lucide-react'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import { datasetLabel } from './restoreUtils'

/**
 * Step 6: the last look before anything is written. It says what will happen to personal data (protected with this
 * site's own Vault key, or, on a site with no Vault, stored as plain text only if the person explicitly agrees, with
 * the advice to turn the Vault on first), what is not restored, and that "add" can be undone while "replace" cannot.
 */
export default function RestoreConfirm({
  mode, vault, plain, onPlain, preview, counts, error, busy, onStart, onBack, onNewPreview, onSetupVault,
}) {
  const total = mode === 'total'
  const vaultOn = Boolean(vault?.setup)
  const needsPlain = vault && !vaultOn
  const canStart = !busy && (!needsPlain || plain)

  return (
    <div className="space-y-5">
      <p className="text-sm font-medium text-gray-900">
        {total
          ? __('You are about to replace everything in Eleva CRM with this backup.', 'eleva-crm-for-photographers')
          : __('You are about to add this backup to your data.', 'eleva-crm-for-photographers')}
      </p>

      <ul className="text-sm text-gray-700 space-y-1.5 list-disc list-inside">
        {total ? (
          <>
            <li>{__('The site first saves a safety copy of your current data.', 'eleva-crm-for-photographers')}</li>
            <li>{__('Then your current Eleva CRM data is removed and the backup is put in its place.', 'eleva-crm-for-photographers')}</li>
            <li>{__('There is no Undo button afterwards. To go back, restore the safety copy.', 'eleva-crm-for-photographers')}</li>
          </>
        ) : (
          <>
            <li>{__('If your site already has data, it first saves a safety copy of it.', 'eleva-crm-for-photographers')}</li>
            <li>{sprintf(__('%d records will be added.', 'eleva-crm-for-photographers'), counts.added)}</li>
            {counts.replaced > 0 && <li>{sprintf(__('%d records here will be replaced by the version in the backup.', 'eleva-crm-for-photographers'), counts.replaced)}</li>}
            <li>{__('You can undo it afterwards, until you choose to keep the result.', 'eleva-crm-for-photographers')}</li>
          </>
        )}
        <li>{__('While it runs you can read your data, but saving is switched off. It goes on if you open other pages.', 'eleva-crm-for-photographers')}</li>
        <li>{__('The connection with Google Calendar is not restored. Events already on your calendar are left as they are.', 'eleva-crm-for-photographers')}</li>
      </ul>

      {preview.unimportable.length > 0 && (
        <p className="text-xs text-amber-900 bg-amber-50 border border-amber-200 rounded px-3 py-2">
          {sprintf(
            __('Not restored by this version: %s.', 'eleva-crm-for-photographers'),
            preview.unimportable.map((u) => datasetLabel(u.dataset)).filter((v, i, a) => a.indexOf(v) === i).join(', ')
          )}
        </p>
      )}

      {vaultOn && (
        <p className="text-sm text-green-800 bg-green-50 border border-green-200 rounded px-3 py-2 flex items-start gap-2">
          <ShieldCheck size={14} className="shrink-0 mt-0.5" />
          {__('Personal details in the backup are protected again with your own Vault key on this site.', 'eleva-crm-for-photographers')}
        </p>
      )}

      {needsPlain && (
        <div className="rounded-md bg-amber-50 border border-amber-200 p-4 space-y-3">
          <p className="text-sm text-amber-900 flex items-start gap-2">
            <AlertTriangle size={14} className="shrink-0 mt-0.5" />
            {__('This site has no Vault. We recommend turning the Vault on first: your restored client details are then protected with your own key. Without it, they are stored as plain text on this site.', 'eleva-crm-for-photographers')}
          </p>
          <Button variant="secondary" size="sm" onClick={onSetupVault}>
            {__('Set up the Vault first', 'eleva-crm-for-photographers')}
          </Button>
          <label className="flex items-start gap-2 text-sm text-amber-900 cursor-pointer">
            <input type="checkbox" checked={plain} onChange={(e) => onPlain(e.target.checked)} className="mt-1" />
            <span>{__('I understand, restore without the Vault. The restored details will not be protected until I turn the Vault on.', 'eleva-crm-for-photographers')}</span>
          </label>
        </div>
      )}

      {error && (
        <div className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2 space-y-2">
          <p className="flex items-start gap-1.5"><XCircle size={12} className="shrink-0 mt-0.5" />{error.message}</p>
          {error.code === 'ftnc_restore_preview_stale' && (
            <Button size="sm" variant="secondary" onClick={onNewPreview}>{__('Check the backup again', 'eleva-crm-for-photographers')}</Button>
          )}
        </div>
      )}

      <div className="flex flex-wrap gap-3">
        <Button variant="secondary" onClick={onBack} disabled={busy}>{__('← Previous step', 'eleva-crm-for-photographers')}</Button>
        <Button variant={total ? 'danger' : 'primary'} onClick={onStart} disabled={!canStart}>
          {busy
            ? __('Starting…', 'eleva-crm-for-photographers')
            : total ? __('Replace everything', 'eleva-crm-for-photographers') : __('Start the restore', 'eleva-crm-for-photographers')}
        </Button>
      </div>
    </div>
  )
}
