import { useMemo, useState } from 'react'
import Button from '../../components/Button'
import { __, sprintf } from '../../utils/i18n'
import { datasetLabel, fieldLabel, friendlyValue, isLinkField } from './restoreUtils'
import {
  PAGE_SIZE, itemKey, groupItems, defaultChoice, optionsFor, resolveBulk, countMissing,
} from './restoreChoices'

const selectClass = 'w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm'

function DiffLines({ item }) {
  if (item.status === 'possible') {
    if (item.candidates.length === 0) {
      return <p className="text-xs text-gray-500">{__('It points to something that may be a duplicate, so it needs your decision too.', 'eleva-crm-for-photographers')}</p>
    }
    return (
      <p className="text-xs text-gray-500">
        {sprintf(__('This site has something similar: %s', 'eleva-crm-for-photographers'), item.candidates.map((c) => c.title ?? '').join(', '))}
      </p>
    )
  }
  return (
    <ul className="text-xs text-gray-600 space-y-1.5 mt-1">
      {item.diff.map((d) => {
        const here = friendlyValue(d.site)
        const there = friendlyValue(d.backup)
        const link = isLinkField(d.field)
        return (
          <li key={d.field}>
            <span className="font-medium">{fieldLabel(d.field)}</span>
            {d.unreadable && <span>{': '}{__('one of the two cannot be read', 'eleva-crm-for-photographers')}</span>}
            {!d.unreadable && (link || here === there) && <span>{': '}{__('they are not the same (the details are not shown here)', 'eleva-crm-for-photographers')}</span>}
            {!d.unreadable && !link && here !== there && (
              <span className="block pl-3 text-gray-500">
                <span className="block break-words">{sprintf(__('On this site: %s', 'eleva-crm-for-photographers'), here || __('empty', 'eleva-crm-for-photographers'))}</span>
                <span className="block break-words">{sprintf(__('In the backup: %s', 'eleva-crm-for-photographers'), there || __('empty', 'eleva-crm-for-photographers'))}</span>
              </span>
            )}
          </li>
        )
      })}
    </ul>
  )
}

function Row({ item, choice, onChoice }) {
  const value = choice ?? defaultChoice(item) ?? ''
  const id = `ftnc-restore-${item.dataset}-${item.backup_id}`
  return (
    <li className="px-3 py-3 grid gap-2 sm:grid-cols-2">
      <div className="min-w-0">
        <p className="text-sm font-medium text-gray-900 break-words">{item.title || __('(no name)', 'eleva-crm-for-photographers')}</p>
        <DiffLines item={item} />
      </div>
      <div>
        <label htmlFor={id} className="sr-only">{__('What to do with this record', 'eleva-crm-for-photographers')}</label>
        <select id={id} value={value} onChange={(e) => onChoice(e.target.value)} className={`${selectClass} ${value === '' ? 'border-amber-400' : ''}`}>
          {value === '' && <option value="" disabled>{__('Choose…', 'eleva-crm-for-photographers')}</option>}
          {optionsFor(item).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
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
      <div className="flex flex-wrap items-center gap-2 justify-between">
        <p className="text-sm font-medium text-gray-800">{title}</p>
        <div className="flex items-center gap-2">
          <label className="text-xs text-gray-500">{bulkLabel}</label>
          <select
            value=""
            onChange={(e) => { if (e.target.value) onBulk(e.target.value) }}
            className="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs"
          >
            <option value="">{__('Choose…', 'eleva-crm-for-photographers')}</option>
            {bulkOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </div>
      </div>
      <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
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
      <p className="text-sm text-gray-600">
        {__('Anything that is the same on both sides is left alone and is not listed here. For the rest, decide what to do. If you are not sure, keep the ones on this site: nothing of yours is touched.', 'eleva-crm-for-photographers')}
      </p>

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
