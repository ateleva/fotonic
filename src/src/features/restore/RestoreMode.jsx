import { AlertTriangle, CheckCircle2 } from 'lucide-react'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import { datasetLabel, formatWhen } from './restoreUtils'

function Stat({ value, label }) {
  return (
    <div className="rounded-md border border-gray-200 px-3 py-2 text-center">
      <p className="text-lg font-semibold text-gray-900">{value}</p>
      <p className="text-xs text-gray-500">{label}</p>
    </div>
  )
}

/**
 * Step 4: what the backup holds compared with this site, and how to restore it: merge (the safe default, with the
 * choice record by record) or replace everything (the site ends up exactly like the backup). The confirmation word
 * for "replace everything" is the one the server sent; it is never written here.
 */
export default function RestoreMode({ session, preview, mode, onMode, typed, onTyped, onNext }) {
  const counts = preview.counts ?? {}
  const total = session.total ?? { word: '', blocking: [] }
  const blocking = total.blocking ?? []
  const word = total.word
  // Several Pro kinds share one name on screen: add their counts up so the name appears once.
  const blockingTotals = {}
  blocking.forEach((b) => {
    const name = b.dataset === 'other' ? b.label : datasetLabel(b.dataset)
    blockingTotals[name] = (blockingTotals[name] ?? 0) + b.count
  })
  const unimportableTotals = {}
  preview.unimportable.forEach((u) => {
    unimportableTotals[datasetLabel(u.dataset)] = (unimportableTotals[datasetLabel(u.dataset)] ?? 0) + u.count
  })
  const unimportableLines = Object.keys(unimportableTotals).map((name) => sprintf(__('%1$s (%2$d)', 'eleva-crm-for-photographers'), name, unimportableTotals[name]))
  const blockingLines = Object.keys(blockingTotals).map((name) => sprintf(__('%1$s (%2$d)', 'eleva-crm-for-photographers'), name, blockingTotals[name]))
  const totalOk = mode !== 'total' || (blocking.length === 0 && typed.trim().toLowerCase() === String(word).toLowerCase() && word !== '')
  const made = formatWhen(session.manifest?.generated_at)

  return (
    <div className="space-y-5">
      {session.same_vault && (
        <p className="text-sm text-green-800 bg-green-50 border border-green-200 rounded px-3 py-2 flex items-start gap-2">
          <CheckCircle2 size={14} className="shrink-0 mt-0.5" />
          {__('This backup was made by this site, so it opened without a password.', 'eleva-crm-for-photographers')}
        </p>
      )}
      {made && (
        <p className="text-sm text-gray-700">{sprintf(__('Backup made on %s.', 'eleva-crm-for-photographers'), made)}</p>
      )}

      <div>
        <p className="text-sm font-medium text-gray-900 mb-2">{__('What the backup holds, compared with this site', 'eleva-crm-for-photographers')}</p>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
          <Stat value={counts.new ?? 0} label={__('Not on this site', 'eleva-crm-for-photographers')} />
          <Stat value={counts.identical ?? 0} label={__('Already here, the same', 'eleva-crm-for-photographers')} />
          <Stat value={counts.different ?? 0} label={__('Already here, different', 'eleva-crm-for-photographers')} />
          <Stat value={counts.possible ?? 0} label={__('Maybe the same', 'eleva-crm-for-photographers')} />
        </div>
      </div>

      {preview.unimportable.length > 0 && (
        <div className="rounded-md bg-amber-50 border border-amber-200 p-3 flex items-start gap-2">
          <AlertTriangle size={14} className="text-amber-600 shrink-0 mt-0.5" />
          <div className="text-xs text-amber-900 space-y-1">
            <p>{__('Some data in this backup belongs to Eleva CRM Pro, which is not active on this site or has no valid license. It is not offered and not restored:', 'eleva-crm-for-photographers')}</p>
            <ul className="list-disc list-inside">
              {unimportableLines.map((line) => <li key={line}>{line}</li>)}
            </ul>
          </div>
        </div>
      )}

      <fieldset className="space-y-3">
        <legend className="text-sm font-medium text-gray-900 mb-1">{__('How do you want to restore?', 'eleva-crm-for-photographers')}</legend>

        <label className={`block rounded-md border p-4 cursor-pointer ${mode === 'merge' ? 'border-fotonic-primary' : 'border-gray-200'}`}>
          <span className="flex items-start gap-3">
            <input type="radio" name="ftnc-restore-mode" checked={mode === 'merge'} onChange={() => onMode('merge')} className="mt-1" />
            <span>
              <span className="block text-sm font-medium text-gray-900">{__('Add to what I have (recommended)', 'eleva-crm-for-photographers')}</span>
              <span className="block text-xs text-gray-500 mt-1">
                {__('Your current data stays. Anything missing is added, and what is already here is left alone unless you choose otherwise. You can undo it afterwards.', 'eleva-crm-for-photographers')}
              </span>
            </span>
          </span>
        </label>

        <label className={`block rounded-md border p-4 ${blocking.length ? 'opacity-70' : 'cursor-pointer'} ${mode === 'total' ? 'border-red-400' : 'border-gray-200'}`}>
          <span className="flex items-start gap-3">
            <input type="radio" name="ftnc-restore-mode" checked={mode === 'total'} onChange={() => onMode('total')} disabled={blocking.length > 0} className="mt-1" />
            <span className="min-w-0">
              <span className="block text-sm font-medium text-gray-900">{__('Replace everything', 'eleva-crm-for-photographers')}</span>
              <span className="block text-xs text-gray-500 mt-1">
                {__('Your current Eleva CRM data is removed and replaced with the backup, so the site ends up exactly like it was when the backup was made.', 'eleva-crm-for-photographers')}
              </span>
              {blocking.length > 0 && (
                <span className="block text-xs text-amber-800 mt-2">
                  {__('Replacing everything is not possible right now, because you have data that cannot be replaced while Eleva CRM Pro is switched off:', 'eleva-crm-for-photographers')}
                  <span className="block mt-1">
                    {blockingLines.join(', ')}
                  </span>
                  <span className="block mt-1">{__('Use "Add to what I have" instead.', 'eleva-crm-for-photographers')}</span>
                </span>
              )}
            </span>
          </span>
        </label>

        {mode === 'total' && blocking.length === 0 && (
          <div className="rounded-md bg-red-50 border border-red-200 p-4 space-y-3">
            {/* Icon on its own line, so the text below uses the whole width. Black text on the light red: readable, no red on red. */}
            <AlertTriangle size={20} className="text-gray-900" aria-hidden="true" />
            <p className="text-sm text-gray-900">
              {__('This cannot be undone with a button. Before anything is removed, the site saves a safety copy of your current data. If you change your mind, restore that safety copy.', 'eleva-crm-for-photographers')}
            </p>
            <div>
              <label htmlFor="ftnc-restore-word" className="block text-sm text-gray-900 mb-1">
                {sprintf(__('To confirm, type %s', 'eleva-crm-for-photographers'), word)}
              </label>
              <input
                id="ftnc-restore-word"
                type="text"
                value={typed}
                onChange={(e) => onTyped(e.target.value)}
                autoComplete="off"
                className="w-full max-w-xs rounded-md border border-red-300 bg-white px-3 py-2 text-sm"
              />
            </div>
          </div>
        )}
      </fieldset>

      <div className="flex gap-3">
        <Button variant="primary" onClick={onNext} disabled={!totalOk}>
          {__('Continue', 'eleva-crm-for-photographers')}
        </Button>
      </div>
    </div>
  )
}
