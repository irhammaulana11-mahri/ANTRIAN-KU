import type { ReactNode } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { cn } from '@/lib/utils'
import { BrandLogo } from '@/components/BrandLogo'

const links = [{ to: '/register', label: 'Ambil Antrian' }, { to: '/tracking', label: 'Lacak Antrian' }]

/** Kerangka halaman publik (pelanggan/kiosk): ringan, ramah ponsel, tanpa sidebar admin. */
export function PublicShell({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  return <div className="min-h-screen bg-surface text-ink">
    <header className="no-print border-b border-slate-200 bg-white">
      <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3">
        <Link to="/register" aria-label="ANTRIAN-KU" className="flex items-center"><BrandLogo compact /></Link>
        <nav className="flex items-center gap-0.5 text-sm sm:gap-1">
          {links.map((link) => <NavLink key={link.to} to={link.to} className={({ isActive }) => cn('whitespace-nowrap rounded-lg px-2.5 py-2 text-[13px] font-medium sm:px-3 sm:text-sm', isActive ? 'bg-teal-50 text-brand' : 'text-slate-600 hover:bg-slate-50')}>{link.label}</NavLink>)}
          {user ? <Link to="/" className="ml-1 whitespace-nowrap rounded-lg bg-slate-100 px-2.5 py-2 text-[13px] font-medium text-slate-700 hover:bg-slate-200 sm:px-3 sm:text-sm">Kembali ke aplikasi</Link> : <Link to="/login" className="ml-1 whitespace-nowrap rounded-lg px-2.5 py-2 text-[13px] text-slate-400 hover:bg-slate-50 sm:px-3 sm:text-sm">Petugas</Link>}
        </nav>
      </div>
    </header>
    <main className="mx-auto max-w-3xl px-4 py-6 sm:py-10">{children}</main>
  </div>
}
