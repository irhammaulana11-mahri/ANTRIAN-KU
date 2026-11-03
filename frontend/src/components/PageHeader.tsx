import type { ReactNode } from 'react'
export function PageHeader({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return <div className="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
      <p className="mb-1.5 text-[10px] font-extrabold uppercase tracking-[0.18em] text-brand/70">ANTRIAN-KU</p>
      <h1 className="text-2xl font-black tracking-tight text-ink lg:text-[1.75rem]">{title}</h1>
      {description && <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-slate-400">{description}</p>}
    </div>
    {action && <div className="shrink-0">{action}</div>}
  </div>
}
