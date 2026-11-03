import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { BarChart3, Building2, ClipboardList, Landmark, LayoutDashboard, ListChecks, LogOut, Menu, Monitor, Settings2, SlidersHorizontal, Ticket, UserRound, Users, WalletCards, X } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { cn } from '@/lib/utils'
import { useBranchRealtime } from '@/hooks/useBranchRealtime'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { can, isOperator } from '@/lib/access'
import type { User } from '@/types/api'
import { BrandLogo } from '@/components/BrandLogo'

type NavItem = { to: string; label: string; icon: React.ElementType; end?: boolean; show: (u: User | null) => boolean }
type NavGroup = { heading: string; items: NavItem[] }

const navGroups: NavGroup[] = [
  {
    heading: 'Beranda',
    items: [
      { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true, show: (u) => !isOperator(u) },
      { to: '/operator', label: 'Dashboard Petugas', icon: UserRound, show: (u) => can(u, 'queues.call') && Boolean(u?.employee) },
    ],
  },
  {
    heading: 'Antrian',
    items: [
      { to: '/register', label: 'Ambil Antrian', icon: Ticket, show: (u) => can(u, 'queues.register') },
      { to: '/supervisor', label: 'Kendali Antrian', icon: ListChecks, show: (u) => can(u, 'queues.assign') },
      { to: '/queues', label: 'Monitoring Antrian', icon: ClipboardList, show: (u) => can(u, 'queues.view') && !isOperator(u) },
    ],
  },
  {
    heading: 'Master Data',
    items: [
      { to: '/branches', label: 'Cabang', icon: Building2, show: (u) => can(u, 'branches.manage') },
      { to: '/units', label: 'Unit', icon: Landmark, show: (u) => can(u, 'units.view') },
      { to: '/employees', label: 'Petugas', icon: Users, show: (u) => can(u, 'employees.view') },
      { to: '/counters', label: 'Loket', icon: WalletCards, show: (u) => can(u, 'counters.view') },
      { to: '/services', label: 'Layanan', icon: Settings2, show: (u) => can(u, 'services.view') },
    ],
  },
  {
    heading: 'Pengaturan',
    items: [
      { to: '/queue-settings', label: 'Pengaturan Antrian', icon: SlidersHorizontal, show: (u) => can(u, 'settings.manage') },
      { to: '/display-devices', label: 'Perangkat Display/Kiosk', icon: Monitor, show: (u) => can(u, 'displays.manage') },
    ],
  },
  {
    heading: 'Laporan',
    items: [
      { to: '/reports', label: 'Laporan', icon: BarChart3, show: (u) => can(u, 'reports.view') },
    ],
  },
]

function Brand() { return <BrandLogo className="h-16 w-52 px-2" /> }

function NavItems({ user }: { user: User | null }) {
  return <nav aria-label="Menu utama" className="space-y-6">
    {navGroups.map((group) => {
      const visible = group.items.filter((item) => item.show(user))
      if (visible.length === 0) return null
      return <div key={group.heading}>
        <p className="mb-1.5 px-3 text-[10px] font-extrabold uppercase tracking-[0.14em] text-slate-400/80">{group.heading}</p>
        <div className="space-y-0.5">
          {visible.map((item) => {
            const Icon = item.icon
            return <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => cn('group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all', isActive ? 'bg-teal-50 text-brand font-semibold' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700')}>
              {({ isActive }) => <>
                {isActive && <span className="absolute left-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-r bg-brand" />}
                <Icon size={17} className={cn('shrink-0 transition-colors', isActive ? 'text-brand' : 'text-slate-400 group-hover:text-slate-600')} />
                {item.label}
              </>}
            </NavLink>
          })}
        </div>
      </div>
    })}
  </nav>
}

function Avatar({ name, size = 9 }: { name: string | undefined; size?: number }) {
  const initials = name ? name.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase() : '?'
  const cls = `h-${size} w-${size}`
  return <div className={`${cls} flex shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-teal-700 text-white font-black text-xs select-none shadow-sm`}>
    {initials}
  </div>
}

function UserDropdown({ user, onSignOut }: { user: User | null; onSignOut: () => void }) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const location = useLocation()

  useEffect(() => { setOpen(false) }, [location.pathname])
  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false) }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', onKey) }
  }, [open])

  return <div ref={ref} className="relative">
    <button type="button" onClick={() => setOpen((v) => !v)} aria-label="Menu pengguna" aria-expanded={open}
      className="flex items-center gap-2.5 rounded-xl px-2 py-1.5 hover:bg-slate-100 transition-colors">
      <Avatar name={user?.name} />
      <div className="hidden text-left sm:block">
        <p className="text-sm font-semibold leading-tight text-ink">{user?.name}</p>
        <p className="text-[11px] text-slate-400 leading-tight">{user?.roles?.join(', ') ?? 'Pengguna'}</p>
      </div>
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="hidden text-slate-400 sm:block"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    {open && <div className="absolute right-0 top-full mt-2 w-60 rounded-2xl border border-slate-100 bg-white py-1.5 shadow-2xl z-50 ring-1 ring-black/5">
      <div className="px-4 py-3 border-b border-slate-100">
        <div className="flex items-center gap-3">
          <Avatar name={user?.name} size={9} />
          <div className="min-w-0">
            <p className="text-sm font-bold text-ink truncate">{user?.name}</p>
            <p className="text-xs text-slate-400 truncate">{user?.email}</p>
          </div>
        </div>
      </div>
      <div className="py-1">
        <Link to="/profile" className="flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors">
          <UserRound size={15} className="text-slate-400" />Profil saya
        </Link>
        <button type="button" onClick={onSignOut} className="flex w-full items-center gap-3 px-4 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 transition-colors">
          <LogOut size={15} />Keluar
        </button>
      </div>
    </div>}
  </div>
}

export function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [menuOpen, setMenuOpen] = useState(false)
  useBranchRealtime(user?.branch_id)

  useEffect(() => { setMenuOpen(false) }, [location.pathname])
  useEffect(() => {
    if (!menuOpen) return
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') setMenuOpen(false) }
    document.addEventListener('keydown', onKey)
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = previous }
  }, [menuOpen])

  const signOut = async () => { await logout(); navigate('/login') }
  const workspace = user?.unit ? user.unit.unit_name : user?.branch ? user.branch.branch_name : user?.branch_id ? 'Operasional Cabang' : 'Konsol Global'

  return <div className="min-h-screen bg-surface text-ink">
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 overflow-y-auto border-r border-slate-100 bg-white px-4 py-6 lg:flex lg:flex-col">
      <div className="mb-8 px-1"><Brand /></div>
      <div className="flex-1 overflow-y-auto"><NavItems user={user} /></div>
      <div className="mt-6 rounded-xl bg-slate-50 px-3 py-2.5">
        <p className="text-[10px] font-semibold uppercase tracking-widest text-slate-400">Ruang kerja</p>
        <p className="mt-0.5 text-sm font-bold text-slate-700 truncate">{workspace}</p>
      </div>
    </aside>

    {menuOpen && <div className="fixed inset-0 z-40 lg:hidden" onMouseDown={(event) => { if (event.target === event.currentTarget) setMenuOpen(false) }}>
      <div className="absolute inset-0 bg-slate-900/50" aria-hidden="true" onClick={() => setMenuOpen(false)} />
      <aside id="mobile-menu" role="dialog" aria-modal="true" aria-label="Menu navigasi" className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col bg-white px-4 py-5 shadow-2xl">
        <div className="mb-6 flex items-center justify-between"><Brand /><button type="button" onClick={() => setMenuOpen(false)} aria-label="Tutup menu" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"><X size={20} /></button></div>
        <div className="flex-1 overflow-y-auto"><NavItems user={user} /></div>
      </aside>
    </div>}

    <div className="lg:pl-64">
      <header className="sticky top-0 z-20 flex h-14 items-center justify-between gap-3 border-b border-slate-100 bg-white/95 px-4 backdrop-blur-sm sm:px-5 lg:px-8">
        <div className="flex min-w-0 items-center gap-3">
          <button type="button" onClick={() => setMenuOpen(true)} aria-label="Buka menu" aria-expanded={menuOpen} aria-controls="mobile-menu" className="-ml-1 rounded-xl p-2 text-slate-500 hover:bg-slate-100 lg:hidden"><Menu size={20} /></button>
          <div className="min-w-0 lg:hidden">
            <p className="truncate text-sm font-bold text-slate-700">{workspace}</p>
          </div>
        </div>
        <UserDropdown user={user} onSignOut={() => void signOut()} />
      </header>
      <main className="mx-auto max-w-[1600px] p-4 sm:p-6 lg:p-8"><ErrorBoundary resetKey={location.pathname}><Outlet /></ErrorBoundary></main>
    </div>
  </div>
}
