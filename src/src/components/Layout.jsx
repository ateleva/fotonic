import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useMatches, Link } from 'react-router-dom'
import { LayoutDashboard, Users, Briefcase, Camera, Lock, Kanban, BarChart2, UserCheck, Package, Store, Settings, CalendarDays, Receipt, HardDrive, PanelLeftClose, PanelLeftOpen, FileText } from 'lucide-react'
import logoPng from '../assets/icon-256x256.png'
import { useQueryClient } from '@tanstack/react-query'
import { useVault } from '../context/VaultContext'
import useSidebarCollapse from '../hooks/useSidebarCollapse'
import { __ } from '../utils/i18n'
import { useWhatsNew } from '../api/whatsNew'
import { WhatsNewIcon, WhatsNewSectionBanner } from './WhatsNew'
import WhatsNewHost from './WhatsNewHost'
import { groupBySection } from '../utils/whatsNew'

const features = window.FotonicApp?.features ?? {}

// The "What's new" section each sidebar item stands for (Pro adds its entries under the same keys).
const SECTION_OF = {
  '/dashboard': 'dashboard', '/services': 'services', '/works': 'works', '/storage': 'storage', '/quotes': 'quotes',
  '/kanban': 'kanban', '/analytics': 'analytics', '/suppliers': 'suppliers', '/expenses': 'expenses', '/settings': 'settings',
}

// `.ftnc-navlink` owns display / grid-template-columns / align-items / gap / transition
// (see index.css), so `flex items-center gap-3 transition-colors` is deliberately absent
// here. `transition-colors` in particular MUST NOT come back: it compiles to (1,1,0) and
// sets the `transition-property` longhand, which would outrank the class and silently
// kill the collapse animation. The class transitions colour itself instead.
const NAV_LINK_BASE = [
  'ftnc-navlink px-3 py-2 rounded-md text-sm font-medium',
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fotonic-primary focus-visible:ring-offset-1',
].join(' ')

const navLinkClass = (inactiveColor, forceActive = false) => ({ isActive }) =>
  `${NAV_LINK_BASE} ${isActive || forceActive ? 'nav-link-active' : `${inactiveColor} fotonic-nav-inactive`}`

export default function Layout() {
  const queryClient = useQueryClient()
  const { pathname } = useLocation()

  // Name the browser tab after the page ("Edit Work · Eleva CRM"), so several tabs of the app can be told apart.
  // The title comes from the route (see ROUTE_TITLES in router.jsx). Leaving the app (the lock screen unmounts this
  // layout) puts the title WordPress had given the page back.
  const matches = useMatches()
  const leaf = matches[matches.length - 1]
  const pageTitle = leaf?.handle?.title ? leaf.handle.title(leaf.params) : ''
  useEffect(() => {
    if (!pageTitle) return undefined
    const original = document.title
    document.title = `${pageTitle} · Eleva CRM`
    return () => { document.title = original }
  }, [pageTitle])
  const { isUnlocked, lock } = useVault()
  const shellRef = useRef(null)
  const [collapsed, toggleCollapsed] = useSidebarCollapse()

  // What changed in the last update, for this admin: an icon next to each section that has something to
  // read, and one overview window right after the update. Closing either dismisses it for good.
  const { data: whatsNew } = useWhatsNew()
  const bySection = groupBySection(whatsNew?.entries ?? [])
  const [openSection, setOpenSection] = useState(null)
  // The section of the page being visited ('/services/12' is still Services).
  const currentSection = SECTION_OF[`/${pathname.split('/')[1]}`] ?? null

  // One tooltip node for the whole rail. See the `.ftnc-sidebar-tip` comment in index.css
  // for why it has to be `position: fixed` and a sibling of <aside>.
  const [tip, setTip] = useState(null)

  useEffect(() => {
    let frame = 0
    const measure = () => {
      if (!shellRef.current) return
      const top = shellRef.current.getBoundingClientRect().top + window.scrollY
      shellRef.current.style.height = `${window.innerHeight - top}px`
      shellRef.current.style.top = `${top}px`
    }
    // The measurement itself is unchanged; only its scheduling is. `resize` fires
    // unthrottled during a drag, and `measure` both reads layout and writes style.
    const onResize = () => {
      if (frame) return
      frame = requestAnimationFrame(() => { frame = 0; measure() })
    }
    measure()
    window.addEventListener('resize', onResize)
    return () => {
      if (frame) cancelAnimationFrame(frame)
      window.removeEventListener('resize', onResize)
    }
  }, [])

  // Never let a tooltip measured against one state survive into the other, or it pops back
  // up unhovered the next time the rail collapses. Covers both paths that can change
  // `collapsed`: the manual toggle and the matchMedia threshold crossing.
  // This is React's "adjust state during render" pattern, not an effect: an effect here
  // would be a setState-in-effect cascade (and is what the lint rule flags).
  const [tipFor, setTipFor] = useState(collapsed)
  if (tipFor !== collapsed) {
    setTipFor(collapsed)
    setTip(null)
  }

  const hideTip = () => setTip(null)
  const tipProps = (label) => {
    const show = (e) => {
      if (!collapsed) return
      const r = e.currentTarget.getBoundingClientRect()
      setTip({ label, left: r.right + 8, top: r.top, height: r.height })
    }
    return { onMouseEnter: show, onFocus: show, onMouseLeave: hideTip, onBlur: hideTip }
  }

  const navItems = [
    { to: '/dashboard',    label: __('Dashboard', 'eleva-crm-for-photographers'),     icon: LayoutDashboard },
    { to: '/customers',    label: __('Customers', 'eleva-crm-for-photographers'),     icon: Users },
    { to: '/services',     label: __('Services', 'eleva-crm-for-photographers'),      icon: Briefcase },
    { to: '/works',        label: __('Works', 'eleva-crm-for-photographers'),         icon: Camera },
    { to: '/storage',      label: __('Storage', 'eleva-crm-for-photographers'),       icon: HardDrive },
    { to: '/calendar',     label: __('Calendar', 'eleva-crm-for-photographers'),      icon: CalendarDays },
  ]

  const proNavItems = [
    { to: '/quotes',        label: __('Quotes', 'eleva-crm-for-photographers'),         icon: FileText,     feature: 'quotes', alsoActive: ['/quote-settings'] },
    { to: '/kanban',         label: __('Kanban', 'eleva-crm-for-photographers'),         icon: Kanban,       feature: 'kanban' },
    { to: '/analytics',     label: __('Analytics', 'eleva-crm-for-photographers'),      icon: BarChart2,    feature: 'analytics' },
    { to: '/collaborators', label: __('Collaborators', 'eleva-crm-for-photographers'),  icon: UserCheck,    feature: 'collaborators' },
    { to: '/products',      label: __('Products', 'eleva-crm-for-photographers'),       icon: Package,      feature: 'products' },
    { to: '/suppliers',     label: __('Suppliers', 'eleva-crm-for-photographers'),      icon: Store,        feature: 'suppliers' },
    { to: '/expenses',      label: __('Expenses', 'eleva-crm-for-photographers'),       icon: Receipt,      feature: 'expenses' },
  ]

  const settingsLabel = __('Settings', 'eleva-crm-for-photographers')
  const lockLabel = __('Lock Vault', 'eleva-crm-for-photographers')

  const handleLock = async () => {
    await lock()
    // Optimistically mark the cached status locked so VaultGate's reconcile
    // effect can't resurrect the unlocked state from a stale cache entry.
    queryClient.setQueryData(['vault-status'], (old) =>
      old ? { ...old, unlocked: false } : old)
    queryClient.invalidateQueries({ queryKey: ['vault-status'] })
  }

  // Auto-lock after 15 minutes of inactivity when vault is unlocked.
  useEffect(() => {
    if (!isUnlocked) return
    const IDLE_MS = 15 * 60 * 1000
    let timer
    const reset = () => {
      clearTimeout(timer)
      timer = setTimeout(handleLock, IDLE_MS)
    }
    const events = ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll']
    events.forEach(e => window.addEventListener(e, reset, { passive: true }))
    reset()
    return () => {
      clearTimeout(timer)
      events.forEach(e => window.removeEventListener(e, reset))
    }
  }, [isUnlocked]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div
      ref={shellRef}
      className="flex bg-gray-50 overflow-hidden"
      style={{ position: 'sticky' }}
    >
      {/* Sidebar. `overflow-x-hidden` stays: it is what clips the nav labels while the
          grid track animates to 0fr. `overflow-y-auto` is why the tooltip is fixed. */}
      <aside
        className="ftnc-sidebar shrink-0 bg-white border-r border-gray-200 flex flex-col overflow-y-auto overflow-x-hidden"
        data-collapsed={collapsed ? 'true' : 'false'}
        onScroll={hideTip}
      >
        {/* Logo + collapse toggle */}
        <div className="ftnc-sidebar__head border-b border-gray-100">
          <div className="ftnc-sidebar__brand">
            <img src={logoPng} alt="" className="h-8 w-8 object-contain" />
            <span style={{ fontFamily: '-apple-system,"Segoe UI",sans-serif', fontSize: '13px', fontWeight: 600, color: '#1d2327', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
              {__('Eleva CRM for Photographers', 'eleva-crm-for-photographers')}
            </span>
          </div>
          {/* Only ever visible expanded — index.css hides it via [data-collapsed="true"].
              The collapsed rail uses a separate handle below, straddling <aside>'s edge;
              a single button cannot do both jobs without either being clipped by
              <aside>'s own `overflow-x: hidden` (if kept inside) or losing the plain,
              proven in-flow position it already has here (if always moved outside). */}
          <button
            type="button"
            onClick={toggleCollapsed}
            className="ftnc-sidebar__toggle"
            aria-expanded="true"
            aria-controls="ftnc-sidebar-nav"
            aria-label={__('Collapse sidebar', 'eleva-crm-for-photographers')}
          >
            <PanelLeftClose size={18} />
          </button>
        </div>

        {/* Nav */}
        <nav id="ftnc-sidebar-nav" className="ftnc-sidebar__nav flex-1 px-3 py-1.5 space-y-0.5">
          {navItems.map(({ to, label, icon: Icon }) => (
            <div key={to} className="relative">
              <NavLink to={to} className={navLinkClass('text-gray-600')} {...tipProps(label)}>
                <Icon size={16} />
                <span>{label}</span>
              </NavLink>
              {bySection[SECTION_OF[to]] && <WhatsNewIcon section={SECTION_OF[to]} onOpen={setOpenSection} />}
            </div>
          ))}

          {proNavItems.some(({ feature }) => features[feature]) && (
            <>
              <div className="ftnc-sidebar__group-label pt-1 pb-0.5 px-3">
                <span className="text-xs font-semibold uppercase tracking-wider text-indigo-400">{__('Pro', 'eleva-crm-for-photographers')}</span>
              </div>
              {proNavItems.filter(({ feature }) => features[feature]).map(({ to, label, icon: Icon, alsoActive }) => (
                <div key={to} className="relative">
                  <NavLink to={to} className={navLinkClass('text-gray-600', alsoActive?.includes(pathname) ?? false)} {...tipProps(label)}>
                    <Icon size={16} />
                    <span>{label}</span>
                  </NavLink>
                  {bySection[SECTION_OF[to]] && <WhatsNewIcon section={SECTION_OF[to]} onOpen={setOpenSection} />}
                </div>
              ))}
            </>
          )}
        </nav>

        <div className="ftnc-sidebar__foot px-3 pt-2 pb-3 border-t border-gray-100 flex flex-col gap-1">
          <div className="relative">
            <NavLink to="/settings" className={navLinkClass('text-gray-500')} {...tipProps(settingsLabel)}>
              <Settings size={16} />
              <span>{settingsLabel}</span>
            </NavLink>
            {bySection.settings && <WhatsNewIcon section="settings" onOpen={setOpenSection} />}
          </div>
          {isUnlocked && (
            <button
              type="button"
              onClick={handleLock}
              className={`${NAV_LINK_BASE} text-gray-500 hover:bg-gray-100 hover:text-gray-700 cursor-pointer w-full text-left`}
              {...tipProps(lockLabel)}
            >
              <Lock size={16} />
              <span>{lockLabel}</span>
            </button>
          )}
          <div className="ftnc-sidebar__meta px-3 pt-1.5 mt-0.5 border-t border-gray-100 flex items-center gap-2 flex-wrap">
            <Link to="/changelog?tab=free" className="text-xs text-gray-400 hover:text-gray-600 hover:underline" title={__('See what changed in each version', 'eleva-crm-for-photographers')}>CRM v{window.FotonicApp?.version ?? '–'}</Link>
            {window.FotonicApp?.proVersion && (
              <Link to="/changelog?tab=pro" className="text-xs text-indigo-400 hover:text-indigo-600 hover:underline" title={__('See what changed in each version', 'eleva-crm-for-photographers')}>CRM Pro v{window.FotonicApp.proVersion}</Link>
            )}
          </div>
        </div>
      </aside>

      {/* Collapsed-rail toggle: half in, half out of <aside>, straddling its right edge.
          A sibling of <aside>, not a descendant — <aside> keeps `overflow-x: hidden` for
          the label-clipping it does during the collapse animation, so anything meant to
          render outside its box has to live outside its box. Its `left` position in
          index.css is not measured: it is 4px past `.ftnc-sidebar[data-collapsed="true"]`'s
          own hardcoded 64px width (48px under 768px), kept in sync by hand rather than re-derived. */}
      {collapsed && (
        <button
          type="button"
          onClick={toggleCollapsed}
          className="ftnc-sidebar-toggle-handle"
          aria-expanded="false"
          aria-controls="ftnc-sidebar-nav"
          aria-label={__('Expand sidebar', 'eleva-crm-for-photographers')}
        >
          <PanelLeftOpen size={14} />
        </button>
      )}

      {/* Main content */}
      <main className="ftnc-main flex-1 min-w-0 overflow-y-auto">
        {/* The changes of the section being visited, laid over the top of the page until they are dismissed. */}
        <WhatsNewSectionBanner section={currentSection} entries={currentSection ? bySection[currentSection] ?? [] : []} />
        <Outlet />
      </main>

      {/* Rail hover/focus label. Sibling of <aside> and `position: fixed` so it escapes the
          aside's horizontal clip; `aria-hidden` because the real label is still in the DOM
          inside the clipped span and remains the link's accessible name. */}
      {collapsed && tip && (
        <div
          className="ftnc-sidebar-tip"
          aria-hidden="true"
          style={{ left: `${tip.left}px`, top: `${tip.top}px`, height: `${tip.height}px` }}
        >
          {tip.label}
        </div>
      )}

      {/* What's new: the overview once after an update, and the window of one section. */}
      <WhatsNewHost openSection={openSection} setOpenSection={setOpenSection} />
    </div>
  )
}
