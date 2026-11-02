import { useState, type FormEvent } from 'react'
import { MonitorSmartphone } from 'lucide-react'

/** Layar aktivasi perangkat (TV display / kiosk) dengan kunci pairing dari Admin Cabang. */
export function DevicePairingScreen({ title, hint, error, onPair }: { title: string; hint: string; error: string; onPair: (credential: string) => Promise<void> }) {
  const [value, setValue] = useState('')
  const [busy, setBusy] = useState(false)
  const submit = async (event: FormEvent) => { event.preventDefault(); setBusy(true); await onPair(value); setBusy(false) }
  return <main className="grid min-h-screen place-items-center bg-slate-950 p-6 text-white">
    <form onSubmit={submit} className="w-full max-w-lg rounded-3xl bg-white/10 p-8">
      <MonitorSmartphone className="text-teal-300" size={40} />
      <h1 className="mt-4 text-3xl font-black">{title}</h1>
      <p className="mt-2 text-slate-300">{hint}</p>
      <input value={value} onChange={(event) => setValue(event.target.value)} autoFocus placeholder="Kunci pairing" aria-label="Kunci pairing" className="mt-6 w-full rounded-xl border-0 bg-white/10 px-4 py-3 text-white ring-1 ring-white/20 placeholder:text-slate-500 focus:ring-2 focus:ring-teal-400" />
      {error && <p role="alert" className="mt-3 rounded-xl bg-red-500/20 p-3 text-sm text-red-200">{error}</p>}
      <button disabled={busy || value.trim().length < 20} className="mt-6 w-full rounded-xl bg-teal-500 px-4 py-3 font-bold text-slate-950 disabled:opacity-40">{busy ? 'Memproses…' : 'Aktifkan perangkat'}</button>
    </form>
  </main>
}
