import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
export function Card({ children, className }: { children: ReactNode; className?: string }) { return <section className={cn('rounded-2xl bg-white p-5 shadow-soft ring-1 ring-slate-100/80', className)}>{children}</section> }
export function CardHeader({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return <div className="mb-5 flex items-start justify-between gap-4">
    <div className="min-w-0">
      <h2 className="text-base font-bold text-ink">{title}</h2>
      {description && <p className="mt-0.5 text-sm text-slate-400">{description}</p>}
    </div>
    {action && <div className="shrink-0">{action}</div>}
  </div>
}
