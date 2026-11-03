import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

const STYLES = {
  teal:  { bg: 'bg-teal-50',   icon: 'text-teal-600',  bar: 'bg-teal-500',  val: 'text-teal-700' },
  amber: { bg: 'bg-amber-50',  icon: 'text-amber-600', bar: 'bg-amber-500', val: 'text-amber-700' },
  blue:  { bg: 'bg-blue-50',   icon: 'text-blue-600',  bar: 'bg-blue-500',  val: 'text-blue-700' },
  red:   { bg: 'bg-red-50',    icon: 'text-red-600',   bar: 'bg-red-500',   val: 'text-red-700' },
  slate: { bg: 'bg-slate-100', icon: 'text-slate-500', bar: 'bg-slate-400', val: 'text-slate-700' },
}

export function StatCard({ label, value, icon, tone = 'slate', sub }: { label: string; value: string | number; icon: ReactNode; tone?: keyof typeof STYLES; sub?: string }) {
  const s = STYLES[tone]
  return <div className="rounded-2xl bg-white p-5 shadow-soft ring-1 ring-slate-100/80 relative overflow-hidden">
    <div className={cn('absolute left-0 top-4 bottom-4 w-1 rounded-r-full', s.bar)} />
    <div className="flex items-start justify-between pl-3">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</p>
        <p className={cn('mt-2 text-3xl font-black tabular-nums leading-none', s.val)}>{value}</p>
        {sub && <p className="mt-1.5 text-xs text-slate-400">{sub}</p>}
      </div>
      <div className={cn('rounded-xl p-3 shrink-0', s.bg, s.icon)}>{icon}</div>
    </div>
  </div>
}
