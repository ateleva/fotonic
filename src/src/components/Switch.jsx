// Yes / no switch, the same look as the supplier "manual fee" switch. Theme colour only. Plain
// {checked, onChange} props, no react-hook-form import.
export default function Switch({ checked = false, onChange, disabled = false, label }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={!!checked}
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={() => onChange?.(!checked)}
      style={{
        flexShrink: 0, width: 40, height: 22, padding: 2, border: 'none', borderRadius: 11,
        cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.55 : 1,
        background: checked ? 'var(--fotonic-primary, #2563eb)' : '#d1d5db', transition: 'background .15s',
      }}
    >
      <span style={{ display: 'block', width: 18, height: 18, borderRadius: '50%', background: '#fff', transform: checked ? 'translateX(18px)' : 'translateX(0)', transition: 'transform .15s' }} />
    </button>
  )
}
