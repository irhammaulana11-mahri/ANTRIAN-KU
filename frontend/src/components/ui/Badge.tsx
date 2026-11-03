import { cn } from '@/lib/utils'
const TONE = {
  slate:  'bg-slate-100 text-slate-600 ring-slate-200/60',
  green:  'bg-emerald-50 text-emerald-700 ring-emerald-200/60',
  amber:  'bg-amber-50 text-amber-700 ring-amber-200/60',
  blue:   'bg-blue-50 text-blue-700 ring-blue-200/60',
  red:    'bg-red-50 text-red-700 ring-red-200/60',
  teal:   'bg-teal-50 text-teal-700 ring-teal-200/60',
  purple: 'bg-purple-50 text-purple-700 ring-purple-200/60',
}
const DOT = { slate: 'bg-slate-400', green: 'bg-emerald-500', amber: 'bg-amber-500', blue: 'bg-blue-500', red: 'bg-red-500', teal: 'bg-teal-600', purple: 'bg-purple-500' }
export function Badge({ children, tone = 'slate', dot = false }: { children: string; tone?: keyof typeof TONE; dot?: boolean }) {
  return <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1', TONE[tone])}>
    {dot && <span className={cn('h-1.5 w-1.5 rounded-full', DOT[tone])} />}
    {children}
  </span>
}
