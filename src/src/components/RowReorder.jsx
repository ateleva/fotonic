import { ChevronUp, ChevronDown } from 'lucide-react'
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

/**
 * Two stacked arrows that move a repeater row up or down. Renders nothing for a single row.
 * Preflight is off, so every style is set here.
 */
export default function RowReorder({ index, count, onMove, disabled = false }) {
  if (count < 2) return null
  const upOff = disabled || index === 0
  const downOff = disabled || index === count - 1
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
