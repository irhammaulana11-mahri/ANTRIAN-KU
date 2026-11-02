import { Component, type ErrorInfo, type ReactNode } from 'react'

interface Props { children: ReactNode; /** Mengganti tampilan darurat (mis. untuk halaman layar penuh). */ fallback?: (reset: () => void) => ReactNode; resetKey?: string }
interface State { error: Error | null }

/**
 * Tanpa error boundary, satu galat saat render melepas SELURUH pohon React dan layar menjadi putih kosong.
 * Boundary ini menahan galat pada satu halaman, menampilkan pesan yang bisa ditindaklanjuti, dan pulih otomatis
 * saat pengguna pindah halaman (resetKey berubah).
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State { return { error } }

  componentDidCatch(error: Error, info: ErrorInfo) { console.error('Galat tampilan:', error, info.componentStack) }

  componentDidUpdate(previous: Props) { if (this.state.error && previous.resetKey !== this.props.resetKey) this.setState({ error: null }) }

  private reset = () => this.setState({ error: null })

  render() {
    if (!this.state.error) return this.props.children
    if (this.props.fallback) return this.props.fallback(this.reset)
    return <div role="alert" className="mx-auto mt-10 max-w-lg rounded-2xl bg-white p-6 text-center shadow-soft ring-1 ring-slate-100">
      <h2 className="text-lg font-black">Halaman ini mengalami gangguan</h2>
      <p className="mt-2 text-sm text-slate-500">Terjadi galat saat menampilkan halaman. Data Anda aman. Coba muat ulang; bila berulang, hubungi administrator dan sebutkan pesan di bawah.</p>
      <p className="mt-3 break-words rounded-xl bg-slate-50 p-3 text-left font-mono text-xs text-slate-600">{this.state.error.message}</p>
      <div className="mt-5 flex justify-center gap-3">
        <button type="button" onClick={this.reset} className="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-ink ring-1 ring-slate-200 hover:bg-slate-50">Coba lagi</button>
        <button type="button" onClick={() => window.location.reload()} className="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">Muat ulang halaman</button>
      </div>
    </div>
  }
}
