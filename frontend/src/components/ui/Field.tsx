import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

export function Field({ label, error, children, hint }: { label: string; error?: string; children: ReactNode; hint?: string }) {
  return <label className="block space-y-1.5">
    <span className="text-sm font-semibold text-slate-600">{label}</span>
    {hint && <span className="block text-xs text-slate-400 -mt-1">{hint}</span>}
    {children}
    {error && <span className="flex items-center gap-1 text-xs text-red-600"><span>⚠</span>{error}</span>}
  </label>
}

export function Input(props: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={cn('w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm text-ink ring-1 ring-slate-200 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand transition-shadow', props.className)} />
}

export function Select(props: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={cn('w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm text-ink ring-1 ring-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand transition-shadow', props.className)} />
}
