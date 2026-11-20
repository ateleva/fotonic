import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ChevronDown, Search, X } from 'lucide-react'
import PageHeader from '../../components/PageHeader'
import Spinner from '../../components/Spinner'
import { useHelp } from '../../api/help'
import { __, sprintf } from '../../utils/i18n'
import { searchCards, sanitizeQuery, MIN_QUERY, MAX_QUERY } from './helpSearch'
import HelpMarkdown, { Marked } from './HelpMarkdown'

const FIRST_STEPS = 'first-steps'

function Card({ card, open, onToggle, tokens, via }) {
  const panelId = `ftnc-help-${card.id}`
  return (
    <div className="rounded-lg border border-gray-200 bg-white">
      <button
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={onToggle}
        className="flex w-full items-center gap-3 px-4 py-3 text-left cursor-pointer"
      >
        <span className="flex-1 text-sm font-medium text-gray-900"><Marked text={card.question} tokens={tokens} /></span>
        <ChevronDown size={16} className={`shrink-0 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div id={panelId} className="border-t border-gray-100 px-4 py-3 text-sm leading-relaxed text-gray-700">
          {card.available
            ? <HelpMarkdown text={card.answer} tokens={tokens} />
            : <p>{card.unavailable || __('This feature is not available right now.', 'eleva-crm-for-photographers')}</p>}
          {via && <p className="mt-3 text-xs text-gray-400">{__('Found by:', 'eleva-crm-for-photographers')} {via}</p>}
        </div>
      )}
    </div>
  )
}

// Help: one column of accordions. Empty search: the first steps, then the cards by section. A search: the cards
// ranked by relevance, and the top one opens itself when it is clearly the one asked for. The cards come bundled with
// the plugins (generated from the Notion source), per language; nothing is fetched from outside.
export default function HelpPage() {
  const { data, isLoading } = useHelp()
  const [params, setParams] = useSearchParams()
  const [query, setQuery] = useState('')
  // Which cards the person opened or closed by hand for one search; a different search starts again from the rule
  // below (a clear winner opens by itself), so there is no effect that resets state.
  const [manual, setManual] = useState({ key: null, ids: new Set() })
  const sectionFilter = params.get('section') || ''

  const cards = useMemo(() => data?.cards ?? [], [data])
  const sections = useMemo(() => data?.sections ?? {}, [data])
  const scoped = useMemo(() => (sectionFilter ? cards.filter((c) => c.section === sectionFilter) : cards), [cards, sectionFilter])

  const search = useMemo(() => (query.trim().length >= MIN_QUERY ? searchCards(scoped, query) : null), [scoped, query])

  const searchKey = `${query.trim()}|${sectionFilter}`
  const autoOpen = useMemo(() => (search?.strong ? new Set([search.results[0].card.id]) : new Set()), [search])
  const openIds = manual.key === searchKey ? manual.ids : autoOpen
  const toggle = (id) => {
    const next = new Set(openIds)
    if (next.has(id)) next.delete(id); else next.add(id)
    setManual({ key: searchKey, ids: next })
  }

  const groups = useMemo(() => {
    if (search) return []
    const sorted = [...scoped].sort((a, b) => a.order - b.order)
    const isFirst = (c) => c.section === FIRST_STEPS || c.first_steps
    const out = []
    const first = sorted.filter(isFirst)
    // The first steps are shown once, in their own block on top, and not again in their section.
    if (first.length && !sectionFilter) out.push({ key: FIRST_STEPS, label: sections[FIRST_STEPS] || __('First steps', 'eleva-crm-for-photographers'), cards: first })
    const rest = sectionFilter ? sorted : sorted.filter((c) => !isFirst(c))
    const order = Object.keys(sections)
    const keys = [...new Set(rest.map((c) => c.section))].sort((a, b) => order.indexOf(a) - order.indexOf(b))
    for (const key of keys) out.push({ key, label: sections[key] || key, cards: rest.filter((c) => c.section === key) })
    return out
  }, [scoped, sections, search, sectionFilter])

  if (isLoading) return <div className="flex justify-center py-12"><Spinner /></div>

  // How many guides are on screen: all of them, or the ones the search found.
  const shown = search ? search.results.length : scoped.length
  const countText = search
    ? (shown === 1 ? __('Showing 1 guide.', 'eleva-crm-for-photographers') : sprintf(__('Showing %d guides.', 'eleva-crm-for-photographers'), shown))
    : (shown === 1 ? __('There is 1 guide.', 'eleva-crm-for-photographers') : sprintf(__('There are %d guides.', 'eleva-crm-for-photographers'), shown))

  const clearSection = () => { const p = new URLSearchParams(params); p.delete('section'); setParams(p) }

  return (
    <div className="p-6">
      <PageHeader title={__('Help', 'eleva-crm-for-photographers')} action={<span className="text-sm text-gray-500" role="status" aria-live="polite">{countText}</span>} />
      <div className="max-w-3xl">
        <div className="relative mb-4">
          <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(sanitizeQuery(e.target.value))}
            maxLength={MAX_QUERY}
            autoComplete="off"
            spellCheck={false}
            placeholder={__('Search the help: for example, how do I add a customer?', 'eleva-crm-for-photographers')}
            aria-label={__('Search the help', 'eleva-crm-for-photographers')}
            className="w-full rounded-md border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm focus:border-fotonic-primary focus:outline-none"
          />
        </div>

        {sectionFilter && sections[sectionFilter] && (
          <button type="button" onClick={clearSection} className="mb-4 inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs text-gray-700 cursor-pointer hover:bg-gray-50">
            {sections[sectionFilter]}
            <X size={12} aria-hidden="true" />
            <span className="sr-only">{__('Show all topics', 'eleva-crm-for-photographers')}</span>
          </button>
        )}

        {search ? (
          search.results.length === 0 ? (
            <p className="text-sm text-gray-500">{__('No results. Try other words.', 'eleva-crm-for-photographers')}</p>
          ) : (
            <div className="flex flex-col gap-2">
              {search.results.map(({ card, via }) => (
                <Card key={card.id} card={card} open={openIds.has(card.id)} onToggle={() => toggle(card.id)} tokens={search.tokens} via={via} />
              ))}
            </div>
          )
        ) : groups.length === 0 ? (
          <p className="text-sm text-gray-500">{__('No help available yet.', 'eleva-crm-for-photographers')}</p>
        ) : (
          groups.map((g) => (
            <section key={g.key} className="mb-6">
              <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{g.label}</h2>
              <div className="flex flex-col gap-2">
                {g.cards.map((card) => (
                  <Card key={card.id} card={card} open={openIds.has(card.id)} onToggle={() => toggle(card.id)} tokens={[]} />
                ))}
              </div>
            </section>
          ))
        )}
      </div>
    </div>
  )
}
