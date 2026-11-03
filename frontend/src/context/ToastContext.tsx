import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react'
import { CheckCircle2, Info, TriangleAlert, X, XCircle } from 'lucide-react'

type ToastType = 'success' | 'error' | 'warning' | 'info'
interface Toast { id: number; message: string; type: ToastType }
interface ToastContextValue { toast: (message: string, type?: ToastType) => void }

const ToastContext = createContext<ToastContextValue | null>(null)

const ICONS = {
  success: CheckCircle2,
  error: XCircle,
  warning: TriangleAlert,
  info: Info,
}
const STYLES = {
  success: 'bg-emerald-600 text-white',
  error: 'bg-red-600 text-white',
  warning: 'bg-amber-500 text-white',
  info: 'bg-slate-800 text-white',
}

let nextId = 1

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  // keep track of timers so we can clear on manual close
  const timers = useRef<Map<number, ReturnType<typeof setTimeout>>>(new Map())

  const remove = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id))
    const timer = timers.current.get(id)
    if (timer) { clearTimeout(timer); timers.current.delete(id) }
  }, [])

  const toast = useCallback((message: string, type: ToastType = 'success') => {
    const id = nextId++
    setToasts((prev) => [...prev.slice(-4), { id, message, type }]) // max 5 visible
    const timer = setTimeout(() => remove(id), 4000)
    timers.current.set(id, timer)
  }, [remove])

  return (
    <ToastContext.Provider value={{ toast }}>
      {children}
      {/* Portal: fixed top-right stack */}
      <div
        aria-live="polite"
        aria-label="Notifikasi"
        className="pointer-events-none fixed right-4 top-4 z-[9999] flex flex-col gap-2 sm:right-6 sm:top-5"
      >
        {toasts.map((t) => {
          const Icon = ICONS[t.type]
          return (
            <div
              key={t.id}
              role="alert"
              className={`pointer-events-auto flex w-80 max-w-[calc(100vw-2rem)] items-start gap-3 rounded-2xl px-4 py-3.5 shadow-lg ${STYLES[t.type]} animate-slide-in`}
            >
              <Icon size={18} className="mt-0.5 shrink-0 opacity-90" />
              <p className="flex-1 text-sm font-medium leading-snug">{t.message}</p>
              <button
                type="button"
                onClick={() => remove(t.id)}
                aria-label="Tutup notifikasi"
                className="shrink-0 rounded-lg p-0.5 opacity-70 hover:opacity-100 transition-opacity"
              >
                <X size={15} />
              </button>
            </div>
          )
        })}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error('useToast harus digunakan di dalam ToastProvider')
  return ctx.toast
}
