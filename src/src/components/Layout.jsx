import { NavLink, Outlet } from 'react-router-dom'
import { LayoutDashboard, Users, Briefcase, Camera, Lock, Kanban, BarChart2, UserCheck, Package, Store, Settings, CalendarDays } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useVault } from '../context/VaultContext'
import { __ } from '../utils/i18n'

const features = window.FotonicApp?.features ?? {}

export default function Layout() {
  const queryClient = useQueryClient()
  const { isUnlocked, lock } = useVault()

  const navItems = [
    { to: '/dashboard', label: __('Dashboard'), icon: LayoutDashboard },
    { to: '/customers', label: __('Customers'),  icon: Users },
    { to: '/services',  label: __('Services'),   icon: Briefcase },
    { to: '/works',     label: __('Works'),      icon: Camera },
  ]

  const proNavItems = [
    { to: '/kanban',        label: __('Kanban'),        icon: Kanban,       feature: 'kanban' },
    { to: '/analytics',     label: __('Analytics'),     icon: BarChart2,    feature: 'analytics' },
    { to: '/calendar',      label: __('Calendar'),      icon: CalendarDays, feature: 'calendar' },
    { to: '/collaborators', label: __('Collaborators'), icon: UserCheck,    feature: 'collaborators' },
    { to: '/products',      label: __('Products'),      icon: Package,      feature: 'products' },
    { to: '/suppliers',     label: __('Suppliers'),     icon: Store,        feature: 'suppliers' },
  ]

  const handleLock = async () => {
    await lock()
    queryClient.invalidateQueries({ queryKey: ['vault-status'] })
  }

  return (
    <div className="flex min-h-screen bg-gray-50">
      {/* Sidebar */}
      <aside className="w-56 shrink-0 bg-white border-r border-gray-200 flex flex-col">
        {/* Logo */}
        <div className="px-5 py-5 border-b border-gray-100">
          <span className="text-lg font-bold tracking-tight text-gray-900">
            Fotonic
          </span>
        </div>

        {/* Nav */}
        <nav className="flex-1 px-3 py-4 space-y-1">
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
              <div className="pt-3 pb-1 px-3">
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

        <div className="px-3 py-3 border-t border-gray-100 space-y-1">
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
      <main className="flex-1 overflow-auto">
        <Outlet />
      </main>
    </div>
  )
}
