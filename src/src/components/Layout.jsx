import { useEffect, useRef } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { LayoutDashboard, Users, Briefcase, Camera, Lock, Kanban, BarChart2, UserCheck, Package, Store, Settings, CalendarDays, Receipt, MemoryStick } from 'lucide-react'
import logoPng from '../assets/icon-256x256.png'
import { useQueryClient } from '@tanstack/react-query'
import { useVault } from '../context/VaultContext'
import { __ } from '../utils/i18n'

const features = window.FotonicApp?.features ?? {}

export default function Layout() {
  const queryClient = useQueryClient()
  const { isUnlocked, lock } = useVault()
  const shellRef = useRef(null)

  useEffect(() => {
    const measure = () => {
      if (!shellRef.current) return
      const top = shellRef.current.getBoundingClientRect().top + window.scrollY
      shellRef.current.style.height = `${window.innerHeight - top}px`
      shellRef.current.style.top = `${top}px`
    }
    measure()
    window.addEventListener('resize', measure)
    return () => window.removeEventListener('resize', measure)
  }, [])

  const navItems = [
    { to: '/dashboard',    label: __('Dashboard'),     icon: LayoutDashboard },
    { to: '/customers',    label: __('Customers'),     icon: Users },
    { to: '/services',     label: __('Services'),      icon: Briefcase },
    { to: '/works',        label: __('Works'),         icon: Camera },
    { to: '/memory-cards', label: __('Memory Cards'),  icon: MemoryStick },
    { to: '/calendar',     label: __('Calendar'),      icon: CalendarDays },
  ]

  const proNavItems = [
    { to: '/kanban',         label: __('Kanban'),         icon: Kanban,       feature: 'kanban' },
    { to: '/analytics',     label: __('Analytics'),      icon: BarChart2,    feature: 'analytics' },
    { to: '/collaborators', label: __('Collaborators'),  icon: UserCheck,    feature: 'collaborators' },
    { to: '/products',      label: __('Products'),       icon: Package,      feature: 'products' },
    { to: '/suppliers',     label: __('Suppliers'),      icon: Store,        feature: 'suppliers' },
    { to: '/expenses',      label: __('Expenses'),       icon: Receipt,      feature: 'expenses' },
  ]

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
      {/* Sidebar */}
      <aside className="w-[20%] min-w-[200px] max-w-[260px] shrink-0 bg-white border-r border-gray-200 flex flex-col overflow-y-auto overflow-x-hidden">
        {/* Logo */}
        <div className="px-4 py-2 border-b border-gray-100 flex items-center gap-2 min-w-0">
          <img src={logoPng} alt="" className="h-8 w-8 object-contain shrink-0" />
          <span style={{ fontFamily: '-apple-system,"Segoe UI",sans-serif', fontSize: '13px', fontWeight: 600, color: '#1d2327', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
            {__('Eleva CRM for Photographers')}
          </span>
        </div>

        {/* Nav */}
        <nav className="flex-1 px-3 py-1.5 space-y-0.5">
          {navItems.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) =>
                [
                  'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fotonic-primary focus-visible:ring-offset-1',
                  isActive
                    ? 'nav-link-active'
                    : 'text-gray-600 fotonic-nav-inactive',
                ].join(' ')
              }
            >
              <Icon size={16} />
              {label}
            </NavLink>
          ))}

          {proNavItems.some(({ feature }) => features[feature]) && (
            <>
              <div className="pt-1 pb-0.5 px-3">
                <span className="text-xs font-semibold uppercase tracking-wider text-indigo-400">{__('Pro')}</span>
              </div>
              {proNavItems.filter(({ feature }) => features[feature]).map(({ to, label, icon: Icon }) => (
                <NavLink
                  key={to}
                  to={to}
                  className={({ isActive }) =>
                    [
                      'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors',
                      'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fotonic-primary focus-visible:ring-offset-1',
                      isActive
                        ? 'nav-link-active'
                        : 'text-gray-600 fotonic-nav-inactive',
                    ].join(' ')
                  }
                >
                  <Icon size={16} />
                  {label}
                </NavLink>
              ))}
            </>
          )}
        </nav>

        <div className="px-3 pt-2 pb-3 border-t border-gray-100 flex flex-col gap-1">
          <NavLink
            to="/settings"
            className={({ isActive }) =>
              [
                'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fotonic-primary focus-visible:ring-offset-1',
                isActive
                  ? 'nav-link-active'
                  : 'text-gray-500 fotonic-nav-inactive',
              ].join(' ')
            }
          >
            <Settings size={16} />
            {__('Settings')}
          </NavLink>
          {isUnlocked && (
            <button
              onClick={handleLock}
              className="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition-colors cursor-pointer w-full text-left"
            >
              <Lock size={16} />
              {__('Lock Vault')}
            </button>
          )}
          <div className="px-3 pt-1.5 mt-0.5 border-t border-gray-100 flex items-center gap-2 flex-wrap">
            <span className="text-xs text-gray-400">CRM v{window.FotonicApp?.version ?? '–'}</span>
            {window.FotonicApp?.proVersion && (
              <span className="text-xs text-indigo-400">CRM Pro v{window.FotonicApp.proVersion}</span>
            )}
          </div>
        </div>
      </aside>

      {/* Main content */}
      <main className="flex-1 min-w-0 overflow-y-auto">
        <Outlet />
      </main>
    </div>
  )
}
