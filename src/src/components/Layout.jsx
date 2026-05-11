import { NavLink, Outlet } from 'react-router-dom'
import { LayoutDashboard, Users, Briefcase, Camera, Lock, Kanban, BarChart2, UserCheck, Package, Store, Settings, CalendarDays } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { apiFetch } from '../api/client'

const navItems = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/customers', label: 'Customers',  icon: Users },
  { to: '/services',  label: 'Services',   icon: Briefcase },
  { to: '/works',     label: 'Works',      icon: Camera },
]

const proNavItems = [
  { to: '/kanban',        label: 'Kanban',        icon: Kanban,       feature: 'kanban' },
  { to: '/analytics',     label: 'Analytics',     icon: BarChart2,    feature: 'analytics' },
  { to: '/calendar',      label: 'Calendar',      icon: CalendarDays, feature: 'calendar' },
  { to: '/collaborators', label: 'Collaborators', icon: UserCheck,    feature: 'collaborators' },
  { to: '/products',      label: 'Products',      icon: Package,      feature: 'products' },
  { to: '/suppliers',     label: 'Suppliers',     icon: Store,        feature: 'suppliers' },
]

const features = window.FotonicApp?.features ?? {}

export default function Layout() {
  const queryClient = useQueryClient()

  const handleLock = async () => {
    try {
      await apiFetch('vault/lock', { method: 'POST' })
    } catch {
      // best-effort — lock even if request fails
    }
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
                <span className="text-xs font-semibold uppercase tracking-wider text-gray-400">Pro</span>
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
            Settings
          </NavLink>
          <button
            onClick={handleLock}
            className="flex items-center gap-2 w-full px-3 py-2 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition-colors"
          >
            <Lock size={14} />
            Lock Vault
          </button>
          <p className="px-3 text-xs text-gray-400">Fotonic v1.0</p>
        </div>
      </aside>

      {/* Main content */}
      <main className="flex-1 overflow-auto">
        <Outlet />
      </main>
    </div>
  )
}
