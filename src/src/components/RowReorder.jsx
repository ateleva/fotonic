import { ChevronUp, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react'
import { __ } from '../utils/i18n'

// Inline, like Pro's copy: the active arrow wears the primary button style, the disabled one the secondary style.
const arrowBase = {
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  width: '1.5rem',
  height: '1rem',
  padding: 0,
  margin: 0,
  border: 'none',
  borderRadius: 4,
  lineHeight: 0,
  boxSizing: 'border-box',
}
const arrowOn = { ...arrowBase, background: 'var(--fotonic-primary, #2563eb)', color: '#fff', cursor: 'pointer' }
const arrowOff = { ...arrowBase, background: '#fff', color: '#9ca3af', boxShadow: 'inset 0 0 0 1px #e5e7eb', cursor: 'default' }

// The same arrows lying down, for items that sit side by side (the installment cards).
const sideBase = { ...arrowBase, width: '2rem', height: '1.75rem' }
const sideOn = { ...sideBase, background: 'var(--fotonic-primary, #2563eb)', color: '#fff', cursor: 'pointer' }
const sideOff = { ...sideBase, background: '#fff', color: '#9ca3af', boxShadow: 'inset 0 0 0 1px #e5e7eb', cursor: 'default' }

/**
 * Two arrows that move a repeater row: stacked (up and down) by default, side by side (left and right) with
 * `horizontal`, for items laid out in a row. Renders nothing for a single row.
 * Preflight is off, so every style is set here.
 */
export default function RowReorder({ index, count, onMove, disabled = false, horizontal = false }) {
  if (count < 2) return null
  const upOff = disabled || index === 0
  const downOff = disabled || index === count - 1
  if (horizontal) {
    return (
      <span style={{ display: 'inline-flex', flexDirection: 'row', gap: 10, marginRight: 12, flexShrink: 0, lineHeight: 1 }}>
        <button type="button" style={upOff ? sideOff : sideOn} onClick={() => onMove(index, -1)} disabled={upOff} aria-label={__('Move left', 'eleva-crm-for-photographers')}>
          <ChevronLeft size={16} strokeWidth={2.5} />
        </button>
        <button type="button" style={downOff ? sideOff : sideOn} onClick={() => onMove(index, 1)} disabled={downOff} aria-label={__('Move right', 'eleva-crm-for-photographers')}>
          <ChevronRight size={16} strokeWidth={2.5} />
        </button>
      </span>
    )
  }
  return (
    <span style={{ display: 'inline-flex', flexDirection: 'column', gap: 4, marginRight: 16, flexShrink: 0, lineHeight: 1 }}>
      <button type="button" style={upOff ? arrowOff : arrowOn} onClick={() => onMove(index, -1)} disabled={upOff} aria-label={__('Move up', 'eleva-crm-for-photographers')}>
        <ChevronUp size={14} strokeWidth={2.5} />
      </button>
      <button type="button" style={downOff ? arrowOff : arrowOn} onClick={() => onMove(index, 1)} disabled={downOff} aria-label={__('Move down', 'eleva-crm-for-photographers')}>
        <ChevronDown size={14} strokeWidth={2.5} />
      </button>
    </span>
  )
}
