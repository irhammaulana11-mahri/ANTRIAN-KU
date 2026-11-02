import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { BarChart3, Building2, ClipboardList, LayoutDashboard, ListChecks, LogOut, Menu, Monitor, Settings2, SlidersHorizontal, Ticket, UserRound, Users, WalletCards, X } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { cn } from '@/lib/utils'
import { useBranchRealtime } from '@/hooks/useBranchRealtime'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { can, isOperator } from '@/lib/access'
import type { User } from '@/types/api'
import { BrandLogo } from '@/components/BrandLogo'

const navigation = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true, show: (u: User | null) => !isOperator(u) },
  { to: '/operator', label: 'Dashboard Petugas', icon: UserRound, show: (u: User | null) => can(u, 'queues.call') && Boolean(u?.employee) },
  { to: '/supervisor', label: 'Kendali Antrian', icon: ListChecks, show: (u: User | null) => can(u, 'queues.assign') },
  { to: '/queues', label: 'Monitoring Antrian', icon: ClipboardList, show: (u: User | null) => can(u, 'queues.view') && !isOperator(u) },
  { to: '/register', label: 'Ambil Antrian', icon: Ticket, show: (u: User | null) => can(u, 'queues.register') },
  { to: '/branches', label: 'Cabang', icon: Building2, show: (u: User | null) => can(u, 'branches.manage') },
  { to: '/employees', label: 'Petugas', icon: Users, show: (u: User | null) => can(u, 'employees.view') },
  { to: '/counters', label: 'Loket', icon: WalletCards, show: (u: User | null) => can(u, 'counters.view') },
  { to: '/services', label: 'Layanan', icon: Settings2, show: (u: User | null) => can(u, 'services.view') },
  { to: '/queue-settings', label: 'Pengaturan Antrian', icon: SlidersHorizontal, show: (u: User | null) => can(u, 'settings.manage') },
  { to: '/display-devices', label: 'Perangkat Display/Kiosk', icon: Monitor, show: (u: User | null) => can(u, 'displays.manage') },
  { to: '/reports', label: 'Laporan', icon: BarChart3, show: (u: User | null) => can(u, 'reports.view') },
]

function Brand() { return <BrandLogo className="h-16 w-52 px-2" /> }

function NavItems({ user }: { user: User | null }) {
  return <nav aria-label="Menu utama" className="space-y-1">{navigation.filter((item) => item.show(user)).map((item) => {
    const Icon = item.icon
    return <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => cn('flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition', isActive ? 'bg-teal-50 text-brand' : 'text-slate-600 hover:bg-slate-50')}><Icon size={18} />{item.label}</NavLink>
  })}</nav>
}

export function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [menuOpen, setMenuOpen] = useState(false)
  useBranchRealtime(user?.branch_id)

  // Laci menu (layar < lg): tertutup otomatis saat pindah halaman, Esc menutup, dan halaman di belakang tidak ikut bergulir.
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

  return <div className="min-h-screen bg-surface text-ink">
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 overflow-y-auto border-r border-slate-200 bg-white px-4 py-5 lg:block"><div className="mb-8"><Brand /></div><NavItems user={user} /></aside>

    {menuOpen && <div className="fixed inset-0 z-40 lg:hidden" onMouseDown={(event) => { if (event.target === event.currentTarget) setMenuOpen(false) }}>
      <div className="absolute inset-0 bg-slate-900/50" aria-hidden="true" onClick={() => setMenuOpen(false)} />
      <aside id="mobile-menu" role="dialog" aria-modal="true" aria-label="Menu navigasi" className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col bg-white px-4 py-5 shadow-2xl">
        <div className="mb-6 flex items-center justify-between"><Brand /><button type="button" onClick={() => setMenuOpen(false)} aria-label="Tutup menu" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"><X size={20} /></button></div>
        <div className="flex-1 overflow-y-auto"><NavItems user={user} /></div>
        <div className="mt-4 border-t border-slate-100 pt-4"><p className="truncate text-sm font-semibold">{user?.name}</p><p className="truncate text-xs text-slate-500">{user?.roles?.join(', ') ?? 'Pengguna'}</p>
          <button type="button" onClick={() => void signOut()} className="mt-3 flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50"><LogOut size={18} />Keluar</button></div>
      </aside>
    </div>}

    <div className="lg:pl-64">
      <header className="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-5 lg:px-8">
        <div className="flex min-w-0 items-center gap-3">
          <button type="button" onClick={() => setMenuOpen(true)} aria-label="Buka menu" aria-expanded={menuOpen} aria-controls="mobile-menu" className="-ml-1 rounded-xl p-2 text-slate-600 hover:bg-slate-100 lg:hidden"><Menu size={22} /></button>
          <div className="min-w-0"><p className="hidden text-sm font-semibold text-slate-500 sm:block">Ruang kerja</p><p className="truncate font-bold">{user?.branch ? user.branch.branch_name : user?.branch_id ? 'Operasional Cabang' : 'Konsol Global (semua cabang)'}</p></div>
        </div>
        <div className="flex items-center gap-3"><div className="hidden text-right sm:block"><p className="text-sm font-semibold">{user?.name}</p><p className="text-xs text-slate-500">{user?.roles?.join(', ') ?? 'Pengguna'}</p></div>
          <button onClick={() => void signOut()} className="rounded-xl p-2 text-slate-500 hover:bg-slate-100" title="Keluar" aria-label="Keluar"><LogOut size={18} /></button></div>
      </header>
      <main className="mx-auto max-w-[1600px] p-4 sm:p-5 lg:p-8"><ErrorBoundary resetKey={location.pathname}><Outlet /></ErrorBoundary></main>
    </div>
  </div>
}
