import { __ } from '../../utils/i18n'
import Button from '../../components/Button'

export default function CollaboratorsRepeater({ value = [], onChange, ownerType, ownerId, options }) {
  const admin = options?.admin ?? null
  const collaborators = options?.collaborators ?? []

  const ownerKey = ownerType === 'collaborator' ? `collaborator:${ownerId}` : `admin:${admin?.id ?? ''}`

  function buildOptions() {
    const opts = []
    if (ownerType === 'collaborator' && admin) {
      opts.push({ value: `admin:${admin.id}`, label: `${__('Io')} (${admin.name})`, services: [] })
    }
    collaborators.forEach((c) => {
      const key = `collaborator:${c.id}`
      if (key !== ownerKey) {
        opts.push({ value: key, label: c.name, services: c.services ?? [] })
      }
    })
    return opts
  }

  function addRow() {
    const opts = buildOptions()
    if (opts.length === 0) return
    const [type, rawId] = opts[0].value.split(':')
    onChange([...value, { type, id: parseInt(rawId, 10), services: [], price: 0, status: 'to_pay' }])
  }

  function updateRow(index, patch) {
    onChange(value.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }

  function removeRow(index) {
    onChange(value.filter((_, i) => i !== index))
  }

  const selectOpts = buildOptions()

  function getCollabServices(rowKey) {
    const opt = selectOpts.find((o) => o.value === rowKey)
    return opt ? opt.services : []
  }

  const thStyle = { padding: '6px 10px', textAlign: 'left', fontWeight: 600, fontSize: 12, background: '#f0f0f1', whiteSpace: 'nowrap' }
  const tdStyle = { padding: '6px 10px', verticalAlign: 'middle' }
  const inputCls = 'rounded border border-gray-300 px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500'

  return (
    <div style={{ overflowX: 'auto' }}>
      {value.length > 0 && (
        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 12, tableLayout: 'fixed' }}>
          <colgroup>
            <col style={{ width: '30%' }} />
            <col style={{ width: '28%' }} />
            <col style={{ width: '14%' }} />
            <col style={{ width: '16%' }} />
            <col style={{ width: '12%' }} />
          </colgroup>
          <thead>
            <tr>
              <th style={thStyle}>{__('Collaboratore')}</th>
              <th style={thStyle}>{__('Servizi')}</th>
              <th style={thStyle}>{__('Prezzo (€)')}</th>
              <th style={thStyle}>{__('Stato pagamento')}</th>
              <th style={thStyle}></th>
            </tr>
          </thead>
          <tbody>
            {value.map((row, i) => {
              const rowKey = `${row.type}:${row.id}`
              const isPaid = row.status === 'paid'
              const availableServices = getCollabServices(rowKey)
              const selectedServices = Array.isArray(row.services) ? row.services : []

              return (
                <tr key={i} style={{ borderTop: '1px solid #ddd' }}>
                  {/* Collaborator dropdown */}
                  <td style={tdStyle}>
                    <select
                      className={inputCls}
                      style={{ width: '100%' }}
                      value={rowKey}
                      onChange={(e) => {
                        const [t, rid] = e.target.value.split(':')
                        updateRow(i, { type: t, id: parseInt(rid, 10), services: [] })
                      }}
                    >
                      {selectOpts.map((o) => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                      ))}
                      {!selectOpts.find((o) => o.value === rowKey) && (
                        <option value={rowKey}>{rowKey}</option>
                      )}
                    </select>
                  </td>

                  {/* Services multi-select */}
                  <td style={tdStyle}>
                    {availableServices.length > 0 ? (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                        {availableServices.map((svc) => {
                          const checked = selectedServices.includes(svc.id)
                          return (
                            <label key={svc.id} style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 12, cursor: 'pointer', whiteSpace: 'nowrap' }}>
                              <input
                                type="checkbox"
                                checked={checked}
                                onChange={() => {
                                  const next = checked
                                    ? selectedServices.filter((id) => id !== svc.id)
                                    : [...selectedServices, svc.id]
                                  updateRow(i, { services: next })
                                }}
                              />
                              {svc.name}
                            </label>
                          )
                        })}
                      </div>
                    ) : (
                      <span style={{ color: '#aaa', fontSize: 12 }}>—</span>
                    )}
                  </td>

                  {/* Price */}
                  <td style={tdStyle}>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      className={inputCls}
                      style={{ width: '100%' }}
                      value={row.price}
                      onChange={(e) => updateRow(i, { price: parseFloat(e.target.value) || 0 })}
                    />
                  </td>

                  {/* Payment status */}
                  <td style={tdStyle}>
                    <button
                      type="button"
                      onClick={() => updateRow(i, { status: isPaid ? 'to_pay' : 'paid' })}
                      style={{
                        borderRadius: 12,
                        padding: '3px 12px',
                        fontSize: 12,
                        border: 'none',
                        cursor: 'pointer',
                        whiteSpace: 'nowrap',
                        background: isPaid ? '#d4edda' : '#fff3cd',
                        color: isPaid ? '#155724' : '#856404',
                      }}
                    >
                      {isPaid ? __('Pagato') : __('Da pagare')}
                    </button>
                  </td>

                  {/* Remove */}
                  <td style={tdStyle}>
                    <button
                      type="button"
                      onClick={() => removeRow(i)}
                      style={{ color: '#a00', background: 'none', border: 'none', cursor: 'pointer', fontSize: 13 }}
                    >
                      {__('Rimuovi')}
                    </button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
      <Button
        type="button"
        variant="secondary"
        onClick={addRow}
        disabled={selectOpts.length === 0}
      >
        {__('+ Aggiungi collaboratore')}
      </Button>
      {selectOpts.length === 0 && (
        <p className="text-sm text-gray-500 mt-2">{__('Nessun collaboratore disponibile. Aggiungine uno nella sezione Collaboratori.')}</p>
      )}
    </div>
  )
}
