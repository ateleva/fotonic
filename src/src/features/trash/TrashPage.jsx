import { useState, useRef } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { Trash2, RotateCcw } from 'lucide-react'
import { __, sprintf } from '../../utils/i18n'
import { fetchTrashSummary, fetchTrash, restoreTrash, deleteTrash, TRASH_LIST_ROUTES } from '../../api/trash'
import PageHeader from '../../components/PageHeader'
import Modal from '../../components/Modal'
import Button from '../../components/Button'
import Pagination from '../../components/Pagination'

const PAGE_SIZE = 24
const DAY = 86400000

function fmtDate(ts) {
  return new Date(ts * 1000).toLocaleDateString()
}

/** When the Bin removes an item by itself, as a short phrase. `soon` = within a week (shown stronger). */
function expiryInfo(expiresAt) {
  if (!expiresAt) return null
  const left = Math.ceil((expiresAt * 1000 - Date.now()) / DAY)
  if (left <= 0) return { soon: true, text: __('Disappears today', 'eleva-crm-for-photographers') }
  if (left === 1) return { soon: true, text: __('Disappears tomorrow', 'eleva-crm-for-photographers') }
  if (left <= 7) return { soon: true, text: sprintf(__('Disappears in %s days', 'eleva-crm-for-photographers'), left) }
  return { soon: false, text: sprintf(__('Disappears on %s', 'eleva-crm-for-photographers'), fmtDate(expiresAt)) }
}

function itemTitle(item) {
  if (item.title === null) return __('Protected item', 'eleva-crm-for-photographers')
  return item.title !== '' ? item.title : __('No title', 'eleva-crm-for-photographers')
}

/**
 * The Bin: what was deleted in the app and has not been removed yet. A page of its own (not a part of Settings),
 * opened from the Bin card in Settings or from the small Bin button of each list. `?type=ftnc_customer` shows only
 * one kind of record, and "select all" then means all of THAT kind.
 */
export default function TrashPage() {
  const [params] = useSearchParams()
  const type = params.get('type') || ''
  return <TrashBody type={type} />
}

function TrashBody({ type }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [, setParams] = useSearchParams()
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState(() => new Set())
  const [allMode, setAllMode] = useState(false)
  const [dialog, setDialog] = useState(null) // { ids, all, count } while the delete window is open
  const [word, setWord] = useState('')
  const [busy, setBusy] = useState('') // '' | 'restore' | 'delete'
  const [left, setLeft] = useState(null) // items left to delete while the loop runs
  const [report, setReport] = useState([]) // { tone: 'ok' | 'info' | 'warn', text, lines[] }

  // A selection belongs to what the list shows: when the filter changes (the menu, or the browser's back button) it is cleared
  // and the person is told, so a "Delete" can never act on items that are no longer on screen. "All of this kind" is tied to the
  // kind too, so it goes with it.
  const [shownType, setShownType] = useState(type)
  if (shownType !== type) {
    const had = selected.size > 0 || allMode
    setShownType(type)
    setSelected(new Set())
    setAllMode(false)
    setPage(1)
    setReport(had ? [{ tone: 'info', text: __('Your selection was cleared because you changed what the list shows.', 'eleva-crm-for-photographers'), lines: [] }] : [])
  }

  const { data: summary } = useQuery({ queryKey: ['trash-summary'], queryFn: fetchTrashSummary, staleTime: 0 })
  const total = summary?.count ?? 0
  const byType = summary?.by_type ?? {}
  const names = summary?.names ?? {}
  const count = type ? (byType[type] ?? 0) : total
  const autoDays = summary?.auto_days ?? 0

  const { data } = useQuery({
    queryKey: ['trash-items', type, page],
    queryFn: () => fetchTrash(page, PAGE_SIZE, type),
    enabled: count > 0,
    staleTime: 0,
    placeholderData: keepPreviousData,
  })
  const items = data?.data ?? []
  // Every item seen so far (a selection can span pages), to name an item in a message.
  const known = useRef(new Map())
  items.forEach((i) => known.current.set(i.id, i))

  const pageChecked = items.length > 0 && items.every((i) => selected.has(i.id))
  const selectedCount = allMode ? count : selected.size
  const typeOptions = Object.keys(byType)
  // The filter lists: "Everything (N)" and "<name> (N)" for each type. The field is at least a quarter wider than its
  // longest option (plus room for the arrow), so no name is ever cut.
  const optionLabels = [sprintf(__('Everything (%s)', 'eleva-crm-for-photographers'), total), ...typeOptions.map((t) => `${names[t] ?? t} (${byType[t]})`)]
  if (type && !byType[type]) optionLabels.push(`${names[type] ?? type} (0)`)
  const selectWidth = `${Math.ceil(Math.max(...optionLabels.map((l) => l.length)) * 1.25) + 4}ch`

  function refresh() {
    // Everything: the lists of the app (works, customers, the Pro ones, ...) may have changed.
    queryClient.invalidateQueries()
  }

  function clearSelection() {
    setSelected(new Set())
    setAllMode(false)
  }

  // After an action the list may be shorter than the page we were on: start again from the first page.
  function afterAction() {
    clearSelection()
    setPage(1)
    refresh()
  }

  function toggleOne(id) {
    setAllMode(false)
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  function togglePage() {
    setAllMode(false)
    setSelected((prev) => {
      const next = new Set(prev)
      if (pageChecked) items.forEach((i) => next.delete(i.id))
      else items.forEach((i) => next.add(i.id))
      return next
    })
  }

  function changeType(value) {
    setParams(value ? { type: value } : {}, { replace: true })
  }

  async function handleRestore(ids) {
    if (busy || !ids.length) return
    setBusy('restore')
    const byId = known.current
    try {
      const res = await restoreTrash(ids)
      const ok = res.results.filter((r) => r.ok)
      const msgs = []
      if (ok.length) {
        msgs.push({ tone: 'ok', text: sprintf(__('Restored: %s.', 'eleva-crm-for-photographers'), ok.length), lines: [] })
      }
      for (const r of ok) {
        const it = byId.get(r.id)
        for (const w of r.warnings ?? []) {
          const what = (it?.type_label ?? '').toLowerCase()
          const name = it ? itemTitle(it) : ''
          msgs.push({
            tone: 'warn',
            text: sprintf(
              w.code === 'parent_missing'
                ? __('Restored the %1$s "%2$s", but its customer no longer exists. Open the %1$s and choose another customer.', 'eleva-crm-for-photographers')
                : __('Restored the %1$s "%2$s", but its customer is still in the Bin. Restore the customer too, or open the %1$s and choose another customer.', 'eleva-crm-for-photographers'),
              what,
              name
            ),
            lines: [],
          })
        }
      }
      const failed = res.results.filter((r) => !r.ok).length
      if (failed) msgs.push({ tone: 'warn', text: sprintf(__('Could not restore: %s.', 'eleva-crm-for-photographers'), failed), lines: [] })
      setReport(msgs)
    } catch (err) {
      setReport([{ tone: 'warn', text: err.message || __('Something went wrong. Try again.', 'eleva-crm-for-photographers'), lines: [] }])
    } finally {
      setBusy('')
      afterAction()
    }
  }

  function askDelete(ids, all) {
    setWord('')
    setDialog({ ids, all, count: all ? count : ids.length })
  }

  async function handleDelete() {
    const d = dialog
    if (!d || busy) return
    const needWord = d.all || d.count > 1
    setBusy('delete')
    setLeft(d.count)
    let deleted = 0
    const blocked = []
    let skip = []
    let todo = d.ids
    try {
      for (;;) {
        const res = await deleteTrash({ ids: todo, all: d.all, type, skip, confirm: needWord ? word : '' })
        deleted += res.deleted
        blocked.push(...res.blocked)
        if (d.all) skip = skip.concat(res.blocked.map((b) => b.id))
        else todo = todo.filter((id) => !res.processed.includes(id))
        setLeft(res.remaining)
        if (res.remaining <= 0 || res.processed.length === 0) break
      }
      const msgs = []
      if (deleted) msgs.push({ tone: 'ok', text: sprintf(__('Deleted for good: %s.', 'eleva-crm-for-photographers'), deleted), lines: [] })
      if (blocked.length) {
        msgs.push({
          tone: 'warn',
          text: sprintf(__('Not deleted: %s.', 'eleva-crm-for-photographers'), blocked.length),
          lines: blocked.map((b) => `${b.type_label}: ${b.reason.replace(/\n/g, ' ')}`),
        })
      }
      setReport(msgs)
    } catch (err) {
      setReport([{ tone: 'warn', text: err.message || __('Something went wrong. Try again.', 'eleva-crm-for-photographers'), lines: [] }])
    } finally {
      setBusy('')
      setLeft(null)
      setDialog(null)
      afterAction()
    }
  }

  const needWord = !!dialog && (dialog.all || dialog.count > 1)
  const wordOk = !needWord || word.trim() === (summary?.word ?? 'DELETE')
  const backTo = TRASH_LIST_ROUTES[type] ?? '/settings'

  return (
    <div className="p-6 space-y-4">
      <PageHeader title={__('Bin', 'eleva-crm-for-photographers')} backTo={backTo} />

      <div className="ftnc-panel border-red-300">
        <div className="ftnc-panel__head">
          <Trash2 size={16} className="text-red-600 shrink-0" />
          <h2 className="text-sm font-bold text-red-700">
            {type && names[type] ? sprintf(__('Deleted items: %s', 'eleva-crm-for-photographers'), names[type]) : __('Deleted items', 'eleva-crm-for-photographers')}
          </h2>
        </div>
        <div className="ftnc-panel__body ftnc-fields">
          <p className="text-sm text-gray-600">
            {autoDays > 0
              ? sprintf(__('Items you delete are not removed straight away. They wait here and disappear by themselves after %s days. You can bring them back, or delete them for good now.', 'eleva-crm-for-photographers'), autoDays)
              : __('Items you delete are not removed straight away. They wait here until you delete them for good. You can bring them back, or delete them for good now.', 'eleva-crm-for-photographers')}
          </p>

          {report.map((m, i) => (
            <div
              key={i}
              className={['rounded-md border px-3 py-2 text-sm', m.tone === 'ok' ? 'border-green-200 bg-green-50 text-green-700' : m.tone === 'info' ? 'border-gray-200 bg-gray-50 text-gray-700' : 'border-amber-200 bg-amber-50 text-amber-800'].join(' ')}
            >
              <p>{m.text}</p>
              {m.lines.length > 0 && (
                <ul className="mt-1 list-disc list-inside text-xs">
                  {m.lines.map((l, j) => <li key={j}>{l}</li>)}
                </ul>
              )}
            </div>
          ))}
          {report.length > 0 && (
            <button type="button" className="text-xs text-gray-500 underline" onClick={() => setReport([])}>
              {__('Hide these messages', 'eleva-crm-for-photographers')}
            </button>
          )}

          {typeOptions.length > 1 || (type && typeOptions.length >= 1) ? (
            <div className="flex flex-wrap items-center gap-2">
              <label htmlFor="ftnc-trash-type" className="text-sm text-gray-700">{__('Show', 'eleva-crm-for-photographers')}</label>
              <select
                id="ftnc-trash-type"
                value={type}
                onChange={(e) => changeType(e.target.value)}
                style={{ width: selectWidth, maxWidth: '100%' }}
                className="rounded-md border border-gray-300 pl-2 pr-8 py-1.5 text-sm"
              >
                <option value="">{sprintf(__('Everything (%s)', 'eleva-crm-for-photographers'), total)}</option>
                {typeOptions.map((t) => (
                  <option key={t} value={t}>{`${names[t] ?? t} (${byType[t]})`}</option>
                ))}
                {type && !byType[type] && <option value={type}>{`${names[type] ?? type} (0)`}</option>}
              </select>
            </div>
          ) : null}

          {count === 0 && (
            <div className="rounded-md border border-gray-200 px-4 py-6 text-center text-sm text-gray-500 space-y-3">
              <p>{type && total > 0 ? __('Nothing of this kind is in the Bin.', 'eleva-crm-for-photographers') : __('The Bin is empty. Nothing you deleted is waiting here.', 'eleva-crm-for-photographers')}</p>
              {type && total > 0 ? (
                <Button variant="secondary" size="sm" onClick={() => changeType('')}>{__('Show everything in the Bin', 'eleva-crm-for-photographers')}</Button>
              ) : (
                <Button variant="secondary" size="sm" onClick={() => navigate(backTo)}>{__('← Back', 'eleva-crm-for-photographers')}</Button>
              )}
            </div>
          )}

          {count > 0 && (
            <div className="space-y-3">
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <label className="inline-flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                  <input type="checkbox" className="ftnc-check" checked={allMode || pageChecked} onChange={allMode ? clearSelection : togglePage} />
                  {__('Select all on this page', 'eleva-crm-for-photographers')}
                </label>
                {pageChecked && !allMode && count > items.length && (
                  <button type="button" className="text-sm text-red-700 underline" onClick={() => setAllMode(true)}>
                    {type
                      ? sprintf(__('Select all %s items of this kind in the Bin', 'eleva-crm-for-photographers'), count)
                      : sprintf(__('Select all %s items in the Bin', 'eleva-crm-for-photographers'), count)}
                  </button>
                )}
                {allMode && (
                  <span className="text-sm text-gray-700">
                    {sprintf(__('All %s items are selected.', 'eleva-crm-for-photographers'), count)}{' '}
                    <button type="button" className="text-red-700 underline" onClick={clearSelection}>{__('Clear selection', 'eleva-crm-for-photographers')}</button>
                  </span>
                )}
              </div>

              {selectedCount > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                  {!allMode && (
                    <Button variant="secondary" size="sm" disabled={!!busy} onClick={() => handleRestore([...selected])}>
                      <RotateCcw size={12} aria-hidden="true" />
                      {sprintf(__('Restore (%s)', 'eleva-crm-for-photographers'), selectedCount)}
                    </Button>
                  )}
                  <Button variant="danger" size="sm" disabled={!!busy} onClick={() => askDelete(allMode ? [] : [...selected], allMode)}>
                    <Trash2 size={12} aria-hidden="true" />
                    {sprintf(__('Delete for good (%s)', 'eleva-crm-for-photographers'), selectedCount)}
                  </Button>
                </div>
              )}

              <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
                {items.map((item) => {
                  const exp = expiryInfo(item.expires_at)
                  return (
                    <li key={item.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
                      <input
                        type="checkbox"
                        className="ftnc-check"
                        checked={allMode || selected.has(item.id)}
                        disabled={allMode}
                        onChange={() => toggleOne(item.id)}
                        aria-label={sprintf(__('Select %s', 'eleva-crm-for-photographers'), itemTitle(item))}
                      />
                      <div style={{ flex: '1 1 180px', minWidth: 0 }}>
                        <p className="text-sm font-medium text-gray-800 break-words">
                          <span className="mr-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-normal text-gray-600">{item.type_label}</span>
                          <span className={item.title === null ? 'italic text-gray-500' : ''}>{itemTitle(item)}</span>
                        </p>
                        <p className="text-xs text-gray-400">
                          {sprintf(__('Deleted on %s', 'eleva-crm-for-photographers'), fmtDate(item.trashed_at))}
                          {exp && <> · <span className={exp.soon ? 'font-medium text-red-700' : ''}>{exp.text}</span></>}
                        </p>
                        {item.blocked && <p className="mt-0.5 text-xs text-amber-700 whitespace-pre-line">{item.blocked}</p>}
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        <Button variant="secondary" size="sm" disabled={!!busy} onClick={() => handleRestore([item.id])}>
                          <RotateCcw size={12} aria-hidden="true" />
                          {__('Restore item', 'eleva-crm-for-photographers')}
                        </Button>
                        <button
                          type="button"
                          disabled={!!busy || !!item.blocked}
                          title={item.blocked ? item.blocked : __('Delete for good', 'eleva-crm-for-photographers')}
                          aria-label={__('Delete for good', 'eleva-crm-for-photographers')}
                          onClick={() => askDelete([item.id], false)}
                          className="inline-flex items-center rounded-md border border-red-300 p-1.5 text-red-700 hover:bg-red-50 disabled:opacity-40"
                        >
                          <Trash2 size={14} aria-hidden="true" />
                        </button>
                      </div>
                    </li>
                  )
                })}
              </ul>
              <Pagination page={page} pages={data?.pages ?? 1} total={data?.total ?? 0} size={PAGE_SIZE} onPage={setPage} />
            </div>
          )}
        </div>
      </div>

      <Modal open={dialog !== null} onClose={() => !busy && setDialog(null)} title={__('Delete for good', 'eleva-crm-for-photographers')}>
        {dialog && (
          <div className="space-y-3">
            <p className="text-sm text-gray-700">
              {dialog.count === 1
                ? __('This item will be deleted for good. You cannot undo this.', 'eleva-crm-for-photographers')
                : sprintf(__('%s items will be deleted for good. You cannot undo this.', 'eleva-crm-for-photographers'), dialog.count)}
            </p>
            <p className="text-sm text-gray-600">
              {__('The documents attached to deleted works are deleted too. Backups you already made still contain them.', 'eleva-crm-for-photographers')}
            </p>
            {needWord && (
              <div className="space-y-1">
                <label className="block text-sm text-gray-700" htmlFor="ftnc-trash-word">
                  {sprintf(__('To confirm, type %s', 'eleva-crm-for-photographers'), summary?.word ?? 'DELETE')}
                </label>
                <input
                  id="ftnc-trash-word"
                  type="text"
                  value={word}
                  onChange={(e) => setWord(e.target.value)}
                  autoComplete="off"
                  disabled={!!busy}
                  className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
                />
              </div>
            )}
            {busy === 'delete' && left !== null && (
              <p className="text-xs text-gray-500">{sprintf(__('Deleting... %s left', 'eleva-crm-for-photographers'), left)}</p>
            )}
            <div className="flex justify-end gap-3 pt-1">
              <Button variant="secondary" disabled={!!busy} onClick={() => setDialog(null)}>{__('Cancel', 'eleva-crm-for-photographers')}</Button>
              <Button variant="danger" disabled={!wordOk || !!busy} onClick={handleDelete}>
                {busy === 'delete' ? __('Deleting...', 'eleva-crm-for-photographers') : __('Delete for good', 'eleva-crm-for-photographers')}
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
