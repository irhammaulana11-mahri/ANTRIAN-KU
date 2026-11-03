import { AlertCircle, Inbox, Loader2 } from 'lucide-react'

export function Loading({ label = 'Memuat data…' }: { label?: string }) {
  return <div className="flex min-h-32 flex-col items-center justify-center gap-3 text-sm text-slate-400">
    <Loader2 size={24} className="animate-spin text-brand/50" />
    <span>{label}</span>
  </div>
}

export function ErrorState({ message = 'Data tidak dapat dimuat.' }: { message?: string }) {
  return <div className="flex items-start gap-3 rounded-2xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-100">
    <AlertCircle size={17} className="mt-0.5 shrink-0" />
    <span>{message}</span>
  </div>
}

export function EmptyState({ message = 'Belum ada data.' }: { message?: string }) {
  return <div className="flex flex-col items-center justify-center gap-2 py-12 text-sm text-slate-400">
    <Inbox size={28} className="text-slate-300" />
    <span>{message}</span>
  </div>
}
