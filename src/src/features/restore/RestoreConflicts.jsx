import { useMemo, useState } from 'react'
import { usePaymentTypes } from '../../api/paymentTypes'
import RestoreCompare from './RestoreCompare'
import { tint } from './tint'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import { datasetLabel } from './restoreUtils'
import {
  PAGE_SIZE, itemKey, groupItems, defaultChoice, optionsFor, resolveBulk, countMissing,
} from './restoreChoices'

// What each choice does, in one sentence under its name.
function hintFor(value) {
  const kind = value.split(':')[0]
  if (kind === 'keep') return __('Nothing changes on this site.', 'eleva-crm-for-photographers')
  if (kind === 'backup') return __('What is on this site is replaced by the backup.', 'eleva-crm-for-photographers')
  if (kind === 'copy') return __('You will have both: the backup one is added as a new one.', 'eleva-crm-for-photographers')
  if (kind === 'import') return __('It is added from the backup.', 'eleva-crm-for-photographers')
  return __('The backup one is not used.', 'eleva-crm-for-photographers')
}

// The sides the current choice leaves on the site.
function keptSides(value) {
  const kind = value.split(':')[0]
  if (kind === 'backup' || kind === 'import') return ['backup']
  if (kind === 'copy') return ['site', 'backup']
  return kind === '' ? [] : ['site']
}

function Possible({ item }) {
  if (item.candidates.length === 0) {
    return <p className="m-0 text-xs text-gray-500">{__('It points to something that may be a duplicate, so it needs your decision too.', 'eleva-crm-for-photographers')}</p>
  }
  return (
    <p className="m-0 text-xs text-gray-500">
      {sprintf(__('This site has something similar: %s', 'eleva-crm-for-photographers'), item.candidates.map((c) => c.title ?? '').join(', '))}
    </p>
  )
}

// The payment types are read here so a payment shows the name of its type, not its code.
function Differences({ item, kept }) {
  const { data = [] } = usePaymentTypes()
  const paymentTypes = Object.fromEntries(data.map((t) => [t.slug, t.label]))
  return <RestoreCompare diff={item.diff} names={item.names} paymentTypes={paymentTypes} kept={kept} />
}

function Row({ item, choice, onChoice }) {
  const value = choice ?? defaultChoice(item) ?? ''
  const id = `ftnc-restore-${item.dataset}-${item.backup_id}`
  return (
    <li className="list-none m-0 rounded-lg border-2 bg-white overflow-hidden" style={{ borderColor: value === '' ? '#fbbf24' : tint(35) }}>
      <p className="m-0 px-4 py-2.5 text-base font-semibold text-gray-900 break-words" style={{ background: tint(10) }}>
        {item.title || __('(no name)', 'eleva-crm-for-photographers')}
      </p>
      <div className="p-4 space-y-4">
        <fieldset className="m-0 p-0 border-0">
          <legend className="p-0 mb-2 text-sm font-semibold text-gray-900">{__('What do you want to do?', 'eleva-crm-for-photographers')}</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {optionsFor(item).map((o) => {
              const on = value === o.value
              return (
                <label
                  key={o.value}
                  className="flex items-start gap-2 rounded-md border-2 p-2.5 cursor-pointer"
                  style={on ? { borderColor: 'var(--fotonic-primary, #2563eb)', background: tint(10) } : { borderColor: '#e5e7eb' }}
                >
                  <input type="radio" className="mt-0.5" name={id} value={o.value} checked={on} onChange={() => onChoice(o.value)} />
                  <span className="min-w-0">
                    <span className="block text-sm font-medium text-gray-900 break-words">{o.label}</span>
                    <span className="block text-xs text-gray-500">{hintFor(o.value)}</span>
                  </span>
                </label>
              )
            })}
          </div>
        </fieldset>
        {item.status === 'possible' ? <Possible item={item} /> : <Differences item={item} kept={keptSides(value)} />}
      </div>
    </li>
  )
}

function Section({ title, items, choices, onChoice, onBulk, bulkOptions, bulkLabel }) {
  const [page, setPage] = useState(0)
  const pages = Math.max(1, Math.ceil(items.length / PAGE_SIZE))
  const shown = items.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE)
  return (
    <div className="space-y-2">
      <p className="m-0 text-sm font-medium text-gray-800">{title}</p>
      {items.length > 1 && (
        <div className="flex flex-wrap items-center gap-2 rounded-md border border-dashed p-3" style={{ borderColor: 'var(--fotonic-primary, #2563eb)', background: tint(6) }}>
          <label className="text-sm text-gray-800">
            <span className="font-semibold">{__('Quick choice', 'eleva-crm-for-photographers')}</span>
            {' '}{bulkLabel}
          </label>
          <select
            value=""
            onChange={(e) => { if (e.target.value) onBulk(e.target.value) }}
            className="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm"
          >
            <option value="">{__('Choose…', 'eleva-crm-for-photographers')}</option>
            {bulkOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </div>
      )}
      <ul className="m-0 p-0 space-y-4">
        {shown.map((item) => (
          <Row key={itemKey(item)} item={item} choice={choices[itemKey(item)]} onChoice={(v) => onChoice(item, v)} />
        ))}
      </ul>
      {pages > 1 && (
        <div className="flex items-center gap-3 text-xs text-gray-500">
          <Button size="sm" variant="secondary" disabled={page === 0} onClick={() => setPage(page - 1)}>{__('Previous', 'eleva-crm-for-photographers')}</Button>
          <span>{sprintf(__('Page %1$d of %2$d', 'eleva-crm-for-photographers'), page + 1, pages)}</span>
          <Button size="sm" variant="secondary" disabled={page >= pages - 1} onClick={() => setPage(page + 1)}>{__('Next', 'eleva-crm-for-photographers')}</Button>
        </div>
      )}
    </div>
  )
}

/**
 * Step 5 (only when adding to what is here): the records that exist on both sides but differ, or that may be the
 * same, and the records that are new. Records that are new are added unless the person says otherwise, one choice
 * per kind of record. A "maybe the same" record always needs a choice.
 */
export default function RestoreConflicts({ items, choices, onChoices, newBulk, onNewBulk, error, onBack, onNext }) {
  const groups = useMemo(() => groupItems(items), [items])
  const missing = countMissing(items, choices)

  function setOne(item, value) {
    onChoices({ ...choices, [itemKey(item)]: value })
  }
  function setMany(list, bulk) {
    const next = { ...choices }
    list.forEach((item) => { next[itemKey(item)] = resolveBulk(item, bulk) })
    onChoices(next)
  }

  const differentBulk = [
    { value: 'keep', label: __('Keep the ones on this site', 'eleva-crm-for-photographers') },
    { value: 'backup', label: __('Use the versions from the backup', 'eleva-crm-for-photographers') },
    { value: 'copy', label: __('Add the backup versions as separate ones', 'eleva-crm-for-photographers') },
    { value: 'skip', label: __('Leave them all out', 'eleva-crm-for-photographers') },
  ]
  const possibleBulk = [
    { value: 'keep', label: __('Same one: keep the ones on this site', 'eleva-crm-for-photographers') },
    { value: 'backup', label: __('Same one: use the versions from the backup', 'eleva-crm-for-photographers') },
    { value: 'copy', label: __('Different ones: add them', 'eleva-crm-for-photographers') },
    { value: 'skip', label: __('Leave them all out', 'eleva-crm-for-photographers') },
  ]

  return (
    <div className="space-y-6">
      <div className="rounded-md p-3 text-sm text-gray-800" style={{ background: tint(8) }}>
        <ol className="m-0 pl-5 list-decimal space-y-1">
          <li className="m-0">{__('Below is only what is different between this site and the backup.', 'eleva-crm-for-photographers')}</li>
          <li className="m-0">{__('For each one, choose what to do. If you are not sure, keep the one on this site: nothing of yours is touched.', 'eleva-crm-for-photographers')}</li>
          <li className="m-0">{__('Press Continue at the bottom of the page.', 'eleva-crm-for-photographers')}</li>
        </ol>
      </div>

      {error && (
        <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{error.message}</p>
      )}

      {groups.length === 0 && (
        <p className="text-sm text-gray-700">{__('Nothing to decide: everything in the backup is already on this site.', 'eleva-crm-for-photographers')}</p>
      )}

      {groups.map((g) => (
        <div key={g.dataset} className="space-y-4">
          <h3 className="text-base font-semibold text-gray-900">{datasetLabel(g.dataset)}</h3>

          {g.new.length > 0 && (
            <div className="rounded-md border border-gray-200 p-3 flex flex-wrap items-center gap-3 justify-between">
              <p className="text-sm text-gray-700">
                {sprintf(__('%d not on this site yet', 'eleva-crm-for-photographers'), g.new.length)}
              </p>
              <select
                aria-label={__('What to do with the ones that are not on this site yet', 'eleva-crm-for-photographers')}
                value={newBulk[g.dataset] ?? 'import'}
                onChange={(e) => onNewBulk({ ...newBulk, [g.dataset]: e.target.value })}
                className="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm"
              >
                <option value="import">{__('Add them', 'eleva-crm-for-photographers')}</option>
                <option value="skip">{__('Leave them out', 'eleva-crm-for-photographers')}</option>
              </select>
            </div>
          )}

          {g.possible.length > 0 && (
            <Section
              title={sprintf(__('%d may be the same as something you already have', 'eleva-crm-for-photographers'), g.possible.length)}
              items={g.possible}
              choices={choices}
              onChoice={setOne}
              onBulk={(bulk) => setMany(g.possible, bulk)}
              bulkOptions={possibleBulk}
              bulkLabel={__('Do this for all of them:', 'eleva-crm-for-photographers')}
            />
          )}

          {g.different.length > 0 && (
            <Section
              title={sprintf(__('%d already here, but different', 'eleva-crm-for-photographers'), g.different.length)}
              items={g.different}
              choices={choices}
              onChoice={setOne}
              onBulk={(bulk) => setMany(g.different, bulk)}
              bulkOptions={differentBulk}
              bulkLabel={__('Do this for all of them:', 'eleva-crm-for-photographers')}
            />
          )}
        </div>
      ))}

      {missing > 0 && (
        <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2">
          {sprintf(__('%d still need a choice.', 'eleva-crm-for-photographers'), missing)}
        </p>
      )}

      <div className="flex flex-wrap gap-3">
        <Button variant="secondary" onClick={onBack}>{__('← Previous step', 'eleva-crm-for-photographers')}</Button>
        <Button variant="primary" onClick={onNext} disabled={missing > 0}>{__('Continue', 'eleva-crm-for-photographers')}</Button>
      </div>
    </div>
  )
}
