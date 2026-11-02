import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'
export function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) { return <label className="block space-y-1.5"><span className="text-sm font-medium text-slate-700">{label}</span>{children}{error && <span className="block text-xs text-red-600">{error}</span>}</label> }
export function Input(props: InputHTMLAttributes<HTMLInputElement>) { return <input {...props} className={cn('w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm text-ink ring-1 ring-slate-200 placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-brand', props.className)} /> }
export function Select(props: SelectHTMLAttributes<HTMLSelectElement>) { return <select {...props} className={cn('w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm text-ink ring-1 ring-slate-200 focus:bg-white focus:ring-2 focus:ring-brand', props.className)} /> }
