import { useEffect, type ReactNode } from 'react'
import { AlertTriangle, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'

export function Modal({ title, onClose, children, wide = false }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = prev }
  }, [onClose])

  return <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 p-0 backdrop-blur-[2px] sm:items-center sm:p-4"
    onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
    <div role="dialog" aria-modal="true" aria-label={title || undefined}
      className={`max-h-[92vh] w-full overflow-y-auto rounded-t-3xl bg-white shadow-2xl ring-1 ring-slate-100 sm:rounded-3xl ${wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'}`}>
      {title && (
        <div className="flex items-center justify-between gap-4 border-b border-slate-100 px-6 py-4">
          <h2 className="text-base font-bold text-ink">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Tutup" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 transition-colors"><X size={18} /></button>
        </div>
      )}
      {!title && (
        <div className="flex justify-end px-4 pt-4">
          <button type="button" onClick={onClose} aria-label="Tutup" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 transition-colors"><X size={18} /></button>
        </div>
      )}
      <div className="px-6 py-5">{children}</div>
    </div>
  </div>
}

export function ConfirmDialog({ title, message, confirmLabel, danger = false, busy, error, onConfirm, onCancel }: {
  title: string; message: ReactNode; confirmLabel: string; danger?: boolean; busy: boolean; error?: string; onConfirm: () => void; onCancel: () => void
}) {
  return <Modal title={title} onClose={onCancel}>
    {danger && (
      <div className="mb-4 flex items-center gap-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-100">
        <AlertTriangle size={16} className="shrink-0" />
        <span>{message}</span>
      </div>
    )}
    {!danger && <p className="mb-4 text-sm leading-relaxed text-slate-600">{message}</p>}
    {error && <p className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700 ring-1 ring-red-100">{error}</p>}
    <div className="flex justify-end gap-3">
      <Button variant="secondary" onClick={onCancel} disabled={busy}>Batal</Button>
      <Button variant={danger ? 'danger' : 'primary'} onClick={onConfirm} disabled={busy}>{busy ? 'Memproses…' : confirmLabel}</Button>
    </div>
  </Modal>
}
