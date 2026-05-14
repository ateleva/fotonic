import { useEffect, useRef } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { LayoutDashboard, Users, Briefcase, Camera, Lock, Kanban, BarChart2, UserCheck, Package, Store, Settings, CalendarDays, Tag } from 'lucide-react'
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
    { to: '/dashboard', label: __('Dashboard'), icon: LayoutDashboard },
    { to: '/customers', label: __('Customers'),  icon: Users },
    { to: '/services',  label: __('Services'),   icon: Briefcase },
    { to: '/works',     label: __('Works'),      icon: Camera },
  ]

  const proNavItems = [
    { to: '/kanban',                 label: __('Kanban'),                        icon: Kanban,       feature: 'kanban' },
    { to: '/analytics',              label: __('Analytics'),                     icon: BarChart2,    feature: 'analytics' },
    { to: '/calendar',               label: __('Calendar'),                      icon: CalendarDays, feature: 'calendar' },
    { to: '/collaborators',          label: __('Collaborators'),                 icon: UserCheck,    feature: 'collaborators' },
    { to: '/collaborator-services',  label: __('Collaborator Services', 'fotonic'), icon: Tag,       feature: 'collaborators' },
    { to: '/products',               label: __('Products'),                      icon: Package,      feature: 'products' },
    { to: '/suppliers',              label: __('Suppliers'),                     icon: Store,        feature: 'suppliers' },
  ]

  const handleLock = async () => {
    await lock()
    queryClient.invalidateQueries({ queryKey: ['vault-status'] })
  }

  return (
    <div
      ref={shellRef}
      className="flex bg-gray-50 overflow-hidden"
      style={{ position: 'sticky' }}
    >
      {/* Sidebar */}
      <aside className="w-[20%] min-w-[200px] max-w-[260px] shrink-0 bg-white border-r border-gray-200 flex flex-col overflow-y-auto overflow-x-hidden">
        {/* Logo */}
        <div className="px-4 py-3 border-b border-gray-100">
          <span className="text-lg font-bold tracking-tight text-gray-900">
            Fotonic
          </span>
        </div>

        {/* Nav */}
        <nav className="flex-1 px-3 py-2 space-y-0.5">
          {navItems.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) =>
                [
                  'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-indigo-50 text-indigo-700'
                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                ].join(' ')
              }
            >
              <Icon size={16} />
              {label}
            </NavLink>
          ))}

          {proNavItems.some(({ feature }) => features[feature]) && (
            <>
              <div className="pt-2 pb-1 px-3">
                <span className="text-xs font-semibold uppercase tracking-wider text-gray-400">{__('Pro')}</span>
              </div>
              {proNavItems.filter(({ feature }) => features[feature]).map(({ to, label, icon: Icon }) => (
                <NavLink
                  key={to}
                  to={to}
                  className={({ isActive }) =>
                    [
                      'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors',
                      isActive
                        ? 'bg-indigo-50 text-indigo-700'
                        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
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

        <div className="px-3 py-2 border-t border-gray-100 space-y-1">
          <NavLink
            to="/settings"
            className={({ isActive }) =>
              [
                'flex items-center gap-2 w-full px-3 py-2 rounded-md text-sm font-medium transition-colors',
                isActive
                  ? 'bg-indigo-50 text-indigo-700'
                  : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700',
              ].join(' ')
            }
          >
            <Settings size={14} />
            {__('Settings')}
          </NavLink>
          {isUnlocked && (
            <button
              onClick={handleLock}
              className="flex items-center gap-2 w-full px-3 py-2 rounded-md text-sm font-medium text-amber-600 hover:bg-amber-50 hover:text-amber-700 transition-colors cursor-pointer"
            >
              <Lock size={14} />
              {__('Lock Vault')}
            </button>
          )}
          <div className="px-3 space-y-0.5">
            <p className="text-xs text-gray-400">Fotonic v{window.FotonicApp?.version ?? '–'}</p>
            {window.FotonicApp?.proVersion && (
              <p className="text-xs text-indigo-400">Fotonic Pro v{window.FotonicApp.proVersion}</p>
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
