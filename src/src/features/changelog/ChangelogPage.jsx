import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import PageHeader from '../../components/PageHeader'
import Spinner from '../../components/Spinner'
import { useChangelog } from '../../api/whatsNew'
import { __, sprintf } from '../../utils/i18n'

const FIRST = 8

// Tints of the admin theme colour (--fotonic-primary), never a colour of their own.
const TINT = 'color-mix(in srgb, var(--fotonic-primary) 12%, white)'
const TINT_SOFT = 'color-mix(in srgb, var(--fotonic-primary) 6%, white)'

// Version history of the plugins, one tab each: Eleva CRM, and Eleva CRM Pro when Pro is active. The text
// comes from each plugin's own changelog (English). The sidebar footer links here with ?tab=free or ?tab=pro.
// A new feature starts with its label ("New", "Novità" in Italian) in capitals and bold, so it stands out from the
// fixes and changes. The label comes from the changelog line itself (already translated by the server).
function ChangelogItem({ text }) {
  const m = /^(New|Novità): ([\s\S]*)$/.exec(text)
  if (!m) return <li>{text}</li>
  return (
    <li>
      <strong className="font-bold uppercase text-gray-900">{m[1]}</strong>: {m[2]}
    </li>
  )
}

export default function ChangelogPage() {
  const { data, isLoading } = useChangelog()
  const [params, setParams] = useSearchParams()
  const [showAll, setShowAll] = useState(false)

  if (isLoading) {
    return <div className="flex justify-center py-12"><Spinner /></div>
  }

  const tabs = Object.keys(data ?? {})
  const wanted = params.get('tab')
  const active = tabs.includes(wanted) ? wanted : tabs[0]
  const current = data?.[active]
  const releases = current?.releases ?? []
  const shown = showAll ? releases : releases.slice(0, FIRST)

  return (
    <div className="p-6">
      <PageHeader title={__('Changelog', 'eleva-crm-for-photographers')} />

      {tabs.length > 1 && (
        <div className="mb-6 flex gap-2 border-b border-gray-200" role="tablist">
          {tabs.map((key) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={key === active}
              onClick={() => { setShowAll(false); setParams({ tab: key }) }}
              className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium cursor-pointer ${key === active ? 'border-fotonic-primary text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700'}`}
            >
              {data[key].label}
            </button>
          ))}
        </div>
      )}

      {current?.update && (
        <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 p-4 max-w-3xl" style={{ background: TINT_SOFT }}>
          <span className="rounded px-2 py-0.5 text-xs font-medium text-fotonic-primary" style={{ background: TINT }}>{__('New version available', 'eleva-crm-for-photographers')}</span>
          <span className="flex-1 text-sm text-gray-800">{sprintf(__('Version %s is available.', 'eleva-crm-for-photographers'), current.update.latest)}</span>
          <a
            href={current.update.update_url}
            className="inline-flex items-center justify-center gap-2 rounded-md font-medium bg-fotonic-primary text-white px-3 py-1.5 text-xs hover:opacity-90"
          >
            {__('Update now', 'eleva-crm-for-photographers')}
          </a>
        </div>
      )}

      {releases.length === 0 ? (
        <p className="text-sm text-gray-500">{__('No changelog available.', 'eleva-crm-for-photographers')}</p>
      ) : (
        <div className="space-y-6 max-w-3xl">
          {shown.map((r) => (
            <section key={r.version}>
              <h2 className="text-base font-semibold text-gray-900">
                {__('Version', 'eleva-crm-for-photographers')} {r.version}
                {r.version === current.version && (
                  <span className="ml-2 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{__('Installed', 'eleva-crm-for-photographers')}</span>
                )}
                {current.update && r.version === current.update.latest && (
                  <span className="ml-2 rounded px-2 py-0.5 text-xs font-medium text-fotonic-primary" style={{ background: TINT }}>{__('New version available', 'eleva-crm-for-photographers')}</span>
                )}
              </h2>
              <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700">
                {r.items.map((item, i) => <ChangelogItem key={i} text={item} />)}
              </ul>
            </section>
          ))}
          {releases.length > FIRST && !showAll && (
            <button type="button" onClick={() => setShowAll(true)} className="text-sm font-medium text-fotonic-primary underline cursor-pointer">
              {__('Show older versions', 'eleva-crm-for-photographers')}
            </button>
          )}
        </div>
      )}
    </div>
  )
}
