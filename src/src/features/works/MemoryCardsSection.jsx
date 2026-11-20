import { useMemo } from 'react'
import { Trash2, PlusCircle } from 'lucide-react'
import { useMemoryCards } from '../../api/memory-cards'
import Button from '../../components/Button'
import SearchSelect from '../../components/SearchSelect'
import RowReorder from '../../components/RowReorder'
import { moveRow } from '../../utils/moveRow'
import { __ } from '../../utils/i18n'
import { formatDate, formatTime } from '../../utils/date'

const STATUS_STYLES = {
  free:      'bg-green-100 text-green-800',
  in_use:    'bg-blue-100 text-blue-800',
  backed_up: 'bg-teal-100 text-teal-800',
  damaged:   'bg-red-100 text-red-800',
}

// 'Y-m-d H:i' written by the server in the site's time zone: the date and the time, in the site's formats (no conversion here).
// A value from before the time was kept is just a date.
function changedAt(value) {
  const day = formatDate(value.slice(0, 10))
  return value.length > 10 ? `${day} ${formatTime(value.slice(11, 16))}` : day
}

function statusLabel(status) {
  const map = {
    free:      __('Ready', 'eleva-crm-for-photographers'),
    in_use:    __('In Use', 'eleva-crm-for-photographers'),
    backed_up: __('Backed Up', 'eleva-crm-for-photographers'),
    damaged:   __('Damaged', 'eleva-crm-for-photographers'),
  }
  return map[status] ?? status
}

/**
 * value = { cards: [{card_id, notes}], backup_done: bool, formatting_done: bool }
 * onChange = (value) => void
 */
export default function MemoryCardsSection({ value = {}, onChange }) {
  const cards          = value.cards ?? []
  const backupDone     = value.backup_done ?? false
  const formattingDone = value.formatting_done ?? false

  const { data: freeData }     = useMemoryCards({ status: 'free' })
  const { data: inUseData }    = useMemoryCards({ status: 'in_use' })
  const { data: backedUpData } = useMemoryCards({ status: 'backed_up' })
  const { data: damagedData }  = useMemoryCards({ status: 'damaged' })

  const allCards = useMemo(() => {
    const merge = (d) => (Array.isArray(d) ? d : d?.data ?? [])
    const combined = [
      ...merge(freeData),
      ...merge(inUseData),
      ...merge(backedUpData),
      ...merge(damagedData),
    ]
    const seen = new Set()
    return combined.filter((c) => {
      if (seen.has(c.id)) return false
      seen.add(c.id)
      return true
    })
  }, [freeData, inUseData, backedUpData, damagedData])

  function optionsForRow(currentCardId) {
    // Only ready cards can be picked; the card already in this row always stays in it, even a damaged one, so a row never turns blank.
    return allCards.filter((c) => c.status === 'free' || c.id === currentCardId)
  }

  function update(newCards, newBackup, newFormatting) {
    onChange({ cards: newCards, backup_done: newBackup, formatting_done: newFormatting })
  }

  function addRow() {
    update([...cards, { card_id: '', notes: '' }], backupDone, formattingDone)
  }

  function removeRow(idx) {
    update(cards.filter((_, i) => i !== idx), backupDone, formattingDone)
  }

  // Same as services: a row with an unreadable note is matched to its stored note by position.
  const moveLocked = cards.some((c) => c.notes === null)

  function moveRowBy(idx, dir) {
    update(moveRow(cards, idx, dir), backupDone, formattingDone)
  }

  function updateRow(idx, field, val) {
    update(cards.map((row, i) => (i === idx ? { ...row, [field]: val } : row)), backupDone, formattingDone)
  }

  function handleBackupChange(checked) {
    update(cards, checked, checked ? formattingDone : false)
  }

  function handleFormattingChange(checked) {
    update(cards, backupDone, checked)
  }

  return (
    <div className="ftnc-fields">
      {cards.length > 0 && (
        <div className="space-y-2">
          {cards.map((row, idx) => {
            const opts = optionsForRow(row.card_id ? Number(row.card_id) : null)
            const selectedCard = allCards.find((c) => c.id === Number(row.card_id))
            return (
              <div key={idx} className="flex items-center gap-2">
                <SearchSelect
                  className="flex-none w-72 max-w-[50%]"
                  value={row.card_id ?? ''}
                  onChange={(val) => updateRow(idx, 'card_id', val ? Number(val) : '')}
                  options={[
                    ...opts.map((c) => ({ value: String(c.id), label: c.title })),
                    // A card already in use elsewhere stays visible, but cannot be picked again.
                    ...(selectedCard && !opts.find((o) => o.id === selectedCard.id)
                      ? [{ value: String(selectedCard.id), label: `${selectedCard.title} (${statusLabel(selectedCard.status)})`, disabled: true }]
                      : []),
                  ]}
                  selectedLabel={selectedCard?.title}
                  placeholder={__('Select a card…', 'eleva-crm-for-photographers')}
                  emptyText={__('No cards available', 'eleva-crm-for-photographers')}
                />
                <input
                  type="text"
                  value={row.notes ?? ''}
                  onChange={(e) => updateRow(idx, 'notes', e.target.value)}
                  placeholder={__('Notes…', 'eleva-crm-for-photographers')}
                  className="flex-1 min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {/* The status of the card (it can also be changed from the card itself) with the day it last changed.
                    Same height as the select and the notes field. A ready card shows it only once it has a day. */}
                {selectedCard && (selectedCard.status !== 'free' || selectedCard.status_changed) ? (
                  <span className={`flex-none inline-flex h-[38px] items-center whitespace-nowrap px-2 rounded text-xs font-medium ${STATUS_STYLES[selectedCard.status] ?? 'bg-gray-100 text-gray-700'}`}>
                    {statusLabel(selectedCard.status)}
                    {selectedCard.status_changed ? ` · ${changedAt(selectedCard.status_changed)}` : ''}
                  </span>
                ) : null}
                <RowReorder index={idx} count={cards.length} onMove={moveRowBy} disabled={moveLocked} />
                <Button type="button" variant="danger" size="sm" onClick={() => removeRow(idx)} aria-label={__('Remove', 'eleva-crm-for-photographers')}>
                  <Trash2 size={14} />
                </Button>
              </div>
            )
          })}
        </div>
      )}

      <Button type="button" variant="secondary" size="sm" onClick={addRow}>
        <PlusCircle size={14} />
        {__('Add Card', 'eleva-crm-for-photographers')}
      </Button>

      {cards.length > 0 && (
        <div className="flex flex-wrap gap-4 pt-2 border-t border-gray-100">
          <label className="flex items-center gap-2 cursor-pointer select-none">
            <input
              type="checkbox"
              className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              checked={backupDone}
              onChange={(e) => handleBackupChange(e.target.checked)}
            />
            <span className="text-sm font-medium text-gray-700">
              {__('Backup Done', 'eleva-crm-for-photographers')}
            </span>
          </label>
          <label className={`flex items-center gap-2 select-none ${backupDone ? 'cursor-pointer' : 'cursor-not-allowed opacity-50'}`}>
            <input
              type="checkbox"
              className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              checked={formattingDone}
              disabled={!backupDone}
              onChange={(e) => handleFormattingChange(e.target.checked)}
            />
            <span className="text-sm font-medium text-gray-700">
              {__('Formatting Done', 'eleva-crm-for-photographers')}
            </span>
          </label>
        </div>
      )}
    </div>
  )
}
