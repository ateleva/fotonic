import { useState } from 'react'
import { __ } from '../../utils/i18n'
import { compareField } from './restoreDiff'
import { tint } from './tint'

const changedStyle = { background: tint(14), boxShadow: 'inset 3px 0 0 var(--fotonic-primary, #2563eb)' }

const empty = () => <span className="text-gray-400 italic">{__('empty', 'eleva-crm-for-photographers')}</span>

// Header of the two columns: the side the person is keeping is filled with the theme colour.
function Heads({ first, kept }) {
  const head = (side, title) => {
    const on = kept.includes(side)
    return (
      <th
        scope="col"
        className="px-3 py-2 text-left align-top font-semibold border-l border-gray-200"
        style={on ? { background: 'var(--fotonic-primary, #2563eb)', color: '#fff' } : { background: '#f3f4f6', color: '#4b5563' }}
      >
        <span className="block">{title}</span>
        <span className="block text-[11px] font-normal">
          {on ? `✓ ${__('You will have this', 'eleva-crm-for-photographers')}` : __('Not used', 'eleva-crm-for-photographers')}
        </span>
      </th>
    )
  }
  return (
    <thead>
      <tr>
        <th scope="col" className="px-3 py-2 text-left align-bottom font-semibold text-gray-600 bg-gray-100">{first}</th>
        {head('site', __('On this site', 'eleva-crm-for-photographers'))}
        {head('backup', __('In the backup', 'eleva-crm-for-photographers'))}
      </tr>
    </thead>
  )
}

function Line({ name, line, kept }) {
  const cell = (side) => (
    <td
      className={`px-3 py-1.5 align-top border-l border-gray-200 break-words whitespace-pre-line ${line.changed ? 'font-semibold text-gray-900' : 'text-gray-500'} ${kept.includes(side) ? '' : 'opacity-60'}`}
      style={line.changed ? changedStyle : undefined}
    >
      {line[side] || empty()}
    </td>
  )
  return (
    <tr className="border-t border-gray-200">
      <th scope="row" className="px-3 py-1.5 text-left align-top font-normal text-gray-600 break-words">{name}</th>
      {cell('site')}
      {cell('backup')}
    </tr>
  )
}

function Table({ title, first, kept, children }) {
  return (
    <section className="space-y-1">
      <h4 className="m-0 text-sm font-semibold text-gray-900">{title}</h4>
      <div className="rounded-md border border-gray-300 overflow-x-auto">
        <table className="w-full table-fixed border-collapse text-xs" style={{ minWidth: 420 }}>
          <colgroup><col style={{ width: '24%' }} /><col style={{ width: '38%' }} /><col style={{ width: '38%' }} /></colgroup>
          <Heads first={first} kept={kept} />
          <tbody>{children}</tbody>
        </table>
      </div>
    </section>
  )
}

// One thing of a list (a payment, a person) with its details.
function Entry({ row, kept, showSame }) {
  const shown = row.only || showSame ? row.lines : row.lines.filter((l) => l.changed)
  const badge = row.only === 'site'
    ? __('Only on this site', 'eleva-crm-for-photographers')
    : (row.only === 'backup' ? __('Only in the backup', 'eleva-crm-for-photographers') : __('Changed', 'eleva-crm-for-photographers'))
  return (
    <>
      <tr className="border-t border-gray-300" style={{ background: tint(5) }}>
        <th scope="colgroup" colSpan={3} className="px-3 py-1.5 text-left font-semibold text-gray-900 break-words">
          {row.title}
          <span className="ml-2 rounded-full px-2 py-0.5 text-[11px] font-medium" style={{ background: tint(18) }}>
            {row.same ? __('Same', 'eleva-crm-for-photographers') : badge}
          </span>
        </th>
      </tr>
      {shown.map((line) => <Line key={line.key} name={line.label} line={line} kept={kept} />)}
    </>
  )
}

function Cells({ field, kept }) {
  const side = (name, other, title) => (
    <div className={`min-w-0 ${kept.includes(name) ? '' : 'opacity-60'}`}>
      <p className="m-0 px-2 py-1.5 font-semibold" style={kept.includes(name) ? { background: 'var(--fotonic-primary, #2563eb)', color: '#fff' } : { background: '#f3f4f6', color: '#4b5563' }}>
        {title} {kept.includes(name) ? `✓ ${__('You will have this', 'eleva-crm-for-photographers')}` : ''}
      </p>
      <div className="overflow-x-auto">
        <table className="w-full border-collapse">
          <tbody>
            {field[name].map((cells, y) => (
              <tr key={y} className="border-t border-gray-200">
                {cells.map((value, x) => {
                  const changed = field[other][y]?.[x] !== value
                  return (
                    <td key={x} className={`px-2 py-1 border-l border-gray-100 ${changed ? 'font-semibold text-gray-900' : 'text-gray-500'}`} style={changed ? { background: tint(18) } : undefined}>
                      {value}
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {field[name].length === 0 && <p className="m-0 px-2 py-1.5">{empty()}</p>}
    </div>
  )
  return (
    <section className="space-y-1">
      <h4 className="m-0 text-sm font-semibold text-gray-900">{field.label}</h4>
      <div className="grid gap-2 xl:grid-cols-2 rounded-md border border-gray-300 p-2 text-xs">
        {side('site', 'backup', __('On this site', 'eleva-crm-for-photographers'))}
        {side('backup', 'site', __('In the backup', 'eleva-crm-for-photographers'))}
      </div>
    </section>
  )
}

/**
 * The differences of one record: what this site has next to what the backup has, only what changes unless the person
 * asks for everything. `kept` names the side ('site', 'backup', both or none) the current choice leaves in place.
 */
export default function RestoreCompare({ diff, names, paymentTypes, kept }) {
  const [showSame, setShowSame] = useState(false)
  const fields = diff.map((d) => compareField(d, { names, paymentTypes })).filter((f) => f.type !== 'hidden')
  const values = fields.filter((f) => f.type === 'value')
  const hasSame = fields.some((f) => f.type === 'list' && f.rows.some((r) => r.same || r.lines.some((l) => !l.changed && !r.only)))

  if (fields.length === 0) {
    return <p className="m-0 text-xs text-gray-500">{__('Only internal details change: nothing you see on its page is different.', 'eleva-crm-for-photographers')}</p>
  }
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 justify-between">
        <p className="m-0 text-xs text-gray-600">
          <span className="inline-block px-3 py-1.5 mr-1 font-semibold text-gray-900" style={changedStyle}>{__('Highlighted', 'eleva-crm-for-photographers')}</span>
          {' '}{__('= what is different', 'eleva-crm-for-photographers')}
        </p>
        {hasSame && (
          <label className="flex items-center gap-1.5 text-xs text-gray-600">
            <input type="checkbox" checked={showSame} onChange={(e) => setShowSame(e.target.checked)} />
            {__('Show also what is the same', 'eleva-crm-for-photographers')}
          </label>
        )}
      </div>

      {values.length > 0 && (
        <Table title={__('Information', 'eleva-crm-for-photographers')} first={__('Detail', 'eleva-crm-for-photographers')} kept={kept}>
          {values.map((f) => <Line key={f.field} name={f.label} line={f.rows[0].lines[0]} kept={kept} />)}
        </Table>
      )}

      {fields.filter((f) => f.type !== 'value').map((f) => {
        if (f.type === 'table') return <Cells key={f.field} field={f} kept={kept} />
        const rows = f.type === 'list' ? f.rows.filter((r) => showSame || !r.same) : []
        return (
          <div key={f.field} className="space-y-1">
            {rows.length > 0 ? (
              <Table title={f.label} first={__('Detail', 'eleva-crm-for-photographers')} kept={kept}>
                {rows.map((r, i) => <Entry key={i} row={r} kept={kept} showSame={showSame} />)}
              </Table>
            ) : <h4 className="m-0 text-sm font-semibold text-gray-900">{f.label}</h4>}
            {f.note && <p className="m-0 text-xs text-gray-500">{f.note}</p>}
          </div>
        )
      })}
    </div>
  )
}
