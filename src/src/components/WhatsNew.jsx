import { useEffect } from 'react'
import { Sparkles, X, LayoutDashboard, Briefcase, Camera, FileText, Kanban, BarChart2, Store, Receipt, ArrowRight, HardDrive, Settings } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import Modal from './Modal'
import Button from './Button'
import { __, sprintf } from '../utils/i18n'
import { useDismissWhatsNew } from '../api/whatsNew'
import { sectionLabel, groupBySection, orderedSections, sectionPath, releasePageLockSoon } from '../utils/whatsNew'

// Colours: only the admin theme colour (--fotonic-primary, set on #fotonic-app-root from the WordPress colour
// scheme) in full, tints of it, and the app's grays. No colour of its own per section.
const TINT = 'color-mix(in srgb, var(--fotonic-primary) 12%, white)'
const BAND = 'linear-gradient(135deg, color-mix(in srgb, var(--fotonic-primary) 18%, white), color-mix(in srgb, var(--fotonic-primary) 5%, white))'

// The icon of each section is the one of its sidebar item.
const SECTION_ICON = {
  dashboard: LayoutDashboard,
  services: Briefcase,
  works: Camera,
  storage: HardDrive,
  quotes: FileText,
  kanban: Kanban,
  analytics: BarChart2,
  suppliers: Store,
  expenses: Receipt,
  settings: Settings,
}

function SectionBadge({ section, size = 36 }) {
  const Icon = SECTION_ICON[section] ?? Sparkles
  return (
    <span
      className="inline-flex shrink-0 items-center justify-center rounded-lg text-fotonic-primary"
      style={{ width: size, height: size, background: TINT }}
    >
      <Icon size={Math.round(size * 0.5)} />
    </span>
  )
}

function EntryText({ entry }) {
  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4">
      <h3 className="text-sm font-semibold text-gray-900">{entry.title}</h3>
      <p className="mt-1 text-sm text-gray-600 leading-relaxed">{entry.body}</p>
    </div>
  )
}

// The star next to a sidebar item: a solid button in the theme colour, easy to see in the wide menu and on the narrow
// rail. A sibling of the link, never inside it (a button inside a link is not valid, and a click must open the
// explanation, not navigate).
export function WhatsNewIcon({ section, onOpen }) {
  const label = sprintf(__('What\'s new in %s', 'eleva-crm-for-photographers'), sectionLabel(section))
  return (
    <button
      type="button"
      onClick={(e) => { e.preventDefault(); e.stopPropagation(); onOpen(section) }}
      className="ftnc-whatsnew-btn"
      aria-label={label}
      title={label}
    >
      <Sparkles size={14} />
    </button>
  )
}

// One section's changes. Closing it, any way (the X, Escape, a click outside), dismisses them for good.
export function WhatsNewSectionModal({ section, entries, onClose }) {
  const dismiss = useDismissWhatsNew()
  const navigate = useNavigate()
  useEffect(() => releasePageLockSoon, []) // when this window goes away, however it goes
  const close = () => {
    dismiss.mutate(entries.map((e) => e.id))
    onClose()
  }
  // Going to the section counts as having read it: the same as closing, then the page opens.
  const goTo = () => {
    close()
    navigate(sectionPath(section))
  }
  return (
    <Modal
      open
      onClose={close}
      title={sprintf(__('What\'s new in %s', 'eleva-crm-for-photographers'), sectionLabel(section))}
      size="lg"
      scroll
      headerAction={(
        <Button size="sm" onClick={goTo}>
          {sprintf(__('Go to the %s section', 'eleva-crm-for-photographers'), sectionLabel(section))}
          <ArrowRight size={14} />
        </Button>
      )}
    >
      <div className="mb-4 flex items-center gap-3 rounded-lg p-3" style={{ background: BAND }}>
        <SectionBadge section={section} />
        <span className="text-sm font-semibold text-fotonic-primary">{sectionLabel(section)}</span>
      </div>
      <div className="space-y-3">
        {entries.map((e) => <EntryText key={e.id} entry={e} />)}
      </div>
    </Modal>
  )
}

// Shown once after an update: everything that changed, section by section, in one window that scrolls. There is
// nothing to click but the way out (the X, Escape or a click outside): closing it only ends this window. The
// sections keep their star in the sidebar (and their banner) until their own changes have been seen.
export function WhatsNewOverview({ entries, version, onClose }) {
  const dismiss = useDismissWhatsNew()
  const grouped = groupBySection(entries)
  useEffect(() => releasePageLockSoon, [])
  const close = () => {
    dismiss.mutate([`overview:${version}`])
    onClose()
  }
  return (
    <Modal open onClose={close} title={sprintf(__('What\'s new in version %s', 'eleva-crm-for-photographers'), version)} size="xl" scroll>
      <div className="mb-5 flex items-center gap-3 rounded-lg p-4" style={{ background: BAND }}>
        <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-fotonic-primary text-white">
          <Sparkles size={20} />
        </span>
        <p className="text-sm leading-relaxed text-gray-700">{__('Here is everything that changed in this update. Each section keeps a star in the menu until you have seen its changes.', 'eleva-crm-for-photographers')}</p>
      </div>
      <div className="space-y-4">
        {orderedSections(grouped).map((section) => (
          <section key={section} className="rounded-xl border border-gray-200 bg-white p-4">
            <div className="mb-3 flex items-center gap-3">
              <SectionBadge section={section} size={36} />
              <h3 className="text-base font-semibold text-fotonic-primary">{sectionLabel(section)}</h3>
            </div>
            <div className="space-y-2">
              {grouped[section].map((e) => (
                <div key={e.id} className="rounded-lg bg-gray-50 p-3">
                  <h4 className="text-sm font-semibold text-gray-900">{e.title}</h4>
                  <p className="mt-1 text-sm text-gray-600 leading-relaxed">{e.body}</p>
                </div>
              ))}
            </div>
          </section>
        ))}
      </div>
    </Modal>
  )
}

// A banner laid over the top of a section page (no backdrop, above the page title) while that section's changes have
// not been seen. The only way to dismiss it is its own Close button, so an action always follows the reading.
// Closing it (or opening the sidebar star) dismisses the section's changes for good.
export function WhatsNewSectionBanner({ section, entries }) {
  const dismiss = useDismissWhatsNew()
  if (!section || entries.length === 0) return null
  const title = sprintf(__('What\'s new in %s', 'eleva-crm-for-photographers'), sectionLabel(section))
  return (
    <div className="sticky top-0 z-30 h-0">
      <div
        className="absolute inset-x-4 top-3 max-h-[70vh] overflow-y-auto rounded-lg bg-white p-4"
        // The theme colour all around it: a 1px border and a soft shadow of the same colour.
        style={{ border: '1px solid var(--fotonic-primary)', boxShadow: '0 6px 18px color-mix(in srgb, var(--fotonic-primary) 20%, transparent)' }}
        role="region"
        aria-label={title}
      >
        {/* Icon and title on the left, Close on the right, all on one line at the top */}
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <SectionBadge section={section} />
            <div className="text-sm font-semibold text-fotonic-primary">{title}</div>
          </div>
          <Button size="sm" onClick={() => dismiss.mutate(entries.map((e) => e.id))}>
            <X size={14} />
            {__('Close', 'eleva-crm-for-photographers')}
          </Button>
        </div>
        {/* The changes, full width under it */}
        <ul className="mt-3 list-none space-y-2 p-0">
          {entries.map((e) => (
            <li key={e.id}>
              <div className="text-sm font-semibold text-gray-900">{e.title}</div>
              <p className="text-sm text-gray-600 leading-relaxed">{e.body}</p>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
