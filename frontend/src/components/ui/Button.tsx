import { forwardRef, type ButtonHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

export const Button = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'ghost' | 'danger' }>(function Button({ className, variant = 'primary', ...props }, ref) {
  return <button ref={ref} className={cn(
    'inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50',
    {
      'bg-brand text-white shadow-sm hover:bg-teal-800 active:scale-[0.98] focus-visible:ring-brand': variant === 'primary',
      'bg-white text-ink ring-1 ring-slate-200 hover:bg-slate-50 active:bg-slate-100 focus-visible:ring-brand': variant === 'secondary',
      'text-slate-600 hover:bg-slate-100 active:bg-slate-200 focus-visible:ring-brand': variant === 'ghost',
      'bg-red-600 text-white shadow-sm hover:bg-red-700 active:scale-[0.98] focus-visible:ring-red-500': variant === 'danger',
    },
    className
  )} {...props} />
})
