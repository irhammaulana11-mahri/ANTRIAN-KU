import { useEffect, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/Button'

/** Dialog modal sederhana: Esc menutup, klik latar menutup, fokus awal pada dialog. */
export function Modal({ title, onClose, children, wide = false }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])
  return <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
    <div role="dialog" aria-modal="true" aria-label={title} className={`max-h-[92vh] w-full overflow-y-auto rounded-t-3xl bg-white p-6 shadow-2xl sm:rounded-3xl ${wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'}`}>
      <div className="mb-5 flex items-start justify-between gap-4">
        <h2 className="text-lg font-black">{title}</h2>
        <button type="button" onClick={onClose} aria-label="Tutup" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100"><X size={18} /></button>
      </div>
      {children}
    </div>
  </div>
}

/** Konfirmasi untuk tindakan kritis (nonaktifkan/hapus). Galat dari server ditampilkan di dalam dialog. */
export function ConfirmDialog({ title, message, confirmLabel, danger = false, busy, error, onConfirm, onCancel }: {
  title: string; message: ReactNode; confirmLabel: string; danger?: boolean; busy: boolean; error?: string; onConfirm: () => void; onCancel: () => void
}) {
  return <Modal title={title} onClose={onCancel}>
    <div className="text-sm text-slate-600">{message}</div>
    {error && <p className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
    <div className="mt-6 flex justify-end gap-3">
      <Button variant="secondary" onClick={onCancel} disabled={busy}>Batal</Button>
      <Button variant={danger ? 'danger' : 'primary'} onClick={onConfirm} disabled={busy}>{busy ? 'Memproses…' : confirmLabel}</Button>
    </div>
  </Modal>
}
