import { useEffect, useState } from 'react'
import { Trash2 } from 'lucide-react'
import { useMemoryCards } from '../../api/memory-cards'
import { __ } from '../../utils/i18n'

const STATUS_STYLES = {
  free:      'bg-green-100 text-green-800',
  in_use:    'bg-blue-100 text-blue-800',
  backed_up: 'bg-teal-100 text-teal-800',
  damaged:   'bg-red-100 text-red-800',
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

  const [allCards, setAllCards] = useState([])

  useEffect(() => {
    const merge = (d) => (Array.isArray(d) ? d : d?.data ?? [])
    const combined = [
      ...merge(freeData),
      ...merge(inUseData),
      ...merge(backedUpData),
    ]
    const seen = new Set()
    const deduped = combined.filter((c) => {
      if (seen.has(c.id)) return false
      seen.add(c.id)
      return true
    })
    setAllCards(deduped)
  }, [freeData, inUseData, backedUpData])

  function optionsForRow(currentCardId) {
    return allCards.filter(
      (c) => c.status !== 'damaged' && (c.status === 'free' || c.id === currentCardId)
    )
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
    <div className="space-y-4">
      {cards.length > 0 && (
        <div className="space-y-2">
          {cards.map((row, idx) => {
            const opts = optionsForRow(row.card_id ? Number(row.card_id) : null)
            const selectedCard = allCards.find((c) => c.id === Number(row.card_id))
            return (
              <div key={idx} className="flex items-center gap-2">
                <select
                  value={row.card_id ?? ''}
                  onChange={(e) => updateRow(idx, 'card_id', e.target.value ? Number(e.target.value) : '')}
                  className="flex-none w-44 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <option value="">{__('Select a card…', 'eleva-crm-for-photographers')}</option>
                  {opts.map((c) => (
                    <option key={c.id} value={c.id}>{c.title}</option>
                  ))}
                  {selectedCard && !opts.find((o) => o.id === selectedCard.id) && (
                    <option value={selectedCard.id} disabled>
                      {selectedCard.title} ({statusLabel(selectedCard.status)})
                    </option>
                  )}
                </select>
                {selectedCard && selectedCard.status !== 'free' ? (
                  <span className={`flex-none inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${STATUS_STYLES[selectedCard.status] ?? 'bg-gray-100 text-gray-700'}`}>
                    {statusLabel(selectedCard.status)}
                  </span>
                ) : null}
                <input
                  type="text"
                  value={row.notes ?? ''}
                  onChange={(e) => updateRow(idx, 'notes', e.target.value)}
                  placeholder={__('Notes…', 'eleva-crm-for-photographers')}
                  className="flex-1 min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <button
                  type="button"
                  onClick={() => removeRow(idx)}
                  className="flex-none p-2 text-gray-400 hover:text-red-500 transition-colors"
                  title={__('Remove', 'eleva-crm-for-photographers')}
                >
                  <Trash2 size={16} />
                </button>
              </div>
            )
          })}
        </div>
      )}

      <button
        type="button"
        onClick={addRow}
        className="text-sm text-indigo-600 hover:text-indigo-800 font-medium transition-colors"
      >
        + {__('Add Card', 'eleva-crm-for-photographers')}
      </button>

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
