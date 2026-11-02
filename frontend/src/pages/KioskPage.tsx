import { useCallback, useEffect, useState } from 'react'
import { ArrowLeft, CheckCircle2, Clock, Printer, RotateCcw } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { DevicePairingScreen } from '@/components/DevicePairingScreen'
import { errorMessage } from '@/lib/api'
import { activateKiosk, clearKioskSession, fetchKioskConfig, issueKioskTicket, kioskToken, refreshKioskToken, type KioskConfig, type KioskTicket } from '@/lib/kioskApi'
import { formatWait } from '@/lib/ticket'

type Step = 'service' | 'form' | 'ticket'
type Service = KioskConfig['services'][number]
const TICKET_SECONDS = 25
const IDLE_SECONDS = 90
const AUTOPRINT_KEY = 'sqms_kiosk_autoprint'
const clock = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' })
const dateFormat = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' })

/** Layar pengambilan nomor walk-in untuk tablet/kiosk. Tombol besar, alur 3 langkah, kembali ke awal otomatis. */
export function KioskPage() {
  const [phase, setPhase] = useState<'checking' | 'unpaired' | 'ready'>(kioskToken() ? 'checking' : 'unpaired')
  const [pairError, setPairError] = useState('')
  const [config, setConfig] = useState<KioskConfig | null>(null)
  const [step, setStep] = useState<Step>('service')
  const [service, setService] = useState<Service | null>(null)
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [ticket, setTicket] = useState<KioskTicket | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [secondsLeft, setSecondsLeft] = useState(TICKET_SECONDS)
  const [now, setNow] = useState(new Date())
  const [touched, setTouched] = useState(0)
  const [autoPrint, setAutoPrint] = useState(() => { try { return localStorage.getItem(AUTOPRINT_KEY) === '1' } catch { return false } })

  const reset = useCallback(() => { setStep('service'); setService(null); setName(''); setPhone(''); setTicket(null); setError('') }, [])
  const load = useCallback(async () => {
    try { setConfig(await fetchKioskConfig()); setPhase('ready') } catch (failure) {
      const status = (failure as { response?: { status?: number } }).response?.status
      if (status === 401 || status === 403) { clearKioskSession(); setConfig(null); setPhase('unpaired'); setPairError('Sesi perangkat berakhir atau perangkat dinonaktifkan. Masukkan kunci pairing baru.') }
      else setPhase((current) => (current === 'checking' ? 'ready' : current))
    }
  }, [])
  const pair = useCallback(async (credential: string) => {
    setPairError('')
    try { await activateKiosk(credential); setPhase('checking'); await load() } catch (failure) {
      setPairError((failure as { response?: { data?: { message?: string } } }).response?.data?.message ?? (failure as Error).message ?? 'Aktivasi gagal. Periksa kunci pairing dan koneksi.')
    }
  }, [load])

  useEffect(() => {
    const key = new URLSearchParams(window.location.hash.slice(1)).get('key')
    if (key) { window.history.replaceState(null, '', window.location.pathname); void pair(key) }
  }, [pair])
  useEffect(() => { if (!kioskToken()) return; void load(); const timer = window.setInterval(() => { void load() }, 60_000); return () => window.clearInterval(timer) }, [load, phase === 'unpaired'])  // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (phase !== 'ready') return; const timer = window.setInterval(() => { refreshKioskToken().catch(() => undefined) }, 12 * 60 * 60 * 1000); return () => window.clearInterval(timer) }, [phase])
  useEffect(() => { const timer = window.setInterval(() => setNow(new Date()), 1000); return () => window.clearInterval(timer) }, [])

  // Kembali ke awal: setelah tiket (hitung mundur) dan bila layar diam saat mengisi form.
  useEffect(() => {
    if (step !== 'ticket') return
    setSecondsLeft(TICKET_SECONDS)
    const timer = window.setInterval(() => setSecondsLeft((value) => value - 1), 1000)
    return () => window.clearInterval(timer)
  }, [step, ticket])
  useEffect(() => { if (step === 'ticket' && secondsLeft <= 0) reset() }, [secondsLeft, step, reset])
  useEffect(() => {
    if (step !== 'form') return
    const timer = window.setTimeout(reset, IDLE_SECONDS * 1000)
    return () => window.clearTimeout(timer)
  }, [step, touched, reset])

  if (phase === 'unpaired') return <DevicePairingScreen title="Aktivasi Kiosk Antrian" hint="Masukkan kunci pairing perangkat kiosk yang dibuat Admin Cabang di menu Perangkat." error={pairError} onPair={pair} />
  if (phase === 'checking' || !config) return <main className="grid min-h-screen place-items-center bg-slate-950 text-slate-300">Menghubungkan kiosk…</main>

  const submit = async () => {
    if (!service) return
    setBusy(true); setError('')
    try {
      const result = await issueKioskTicket({ service_type_id: service.id, customer_name: name.trim(), customer_phone: phone.trim() || undefined })
      setTicket(result); setStep('ticket')
      if (autoPrint) window.setTimeout(() => window.print(), 300)
    } catch (failure) {
      const status = (failure as { response?: { status?: number } }).response?.status
      if (status === 401 || status === 403) { clearKioskSession(); setPhase('unpaired'); setPairError('Sesi perangkat berakhir. Masukkan kunci pairing baru.') } else setError(errorMessage(failure, 'Nomor antrian gagal dibuat. Silakan coba lagi atau hubungi petugas.'))
    } finally { setBusy(false) }
  }
  const toggleAutoPrint = (value: boolean) => { setAutoPrint(value); try { localStorage.setItem(AUTOPRINT_KEY, value ? '1' : '0') } catch { /* penyimpanan tidak tersedia */ } }
  const trackUrl = ticket ? `${window.location.origin}/tracking#token=${ticket.token}` : ''
  const ready = config.services.length > 0

  return <main className="flex min-h-screen flex-col bg-gradient-to-br from-slate-50 to-teal-50 text-ink" onPointerDown={() => setTouched((value) => value + 1)}>
    <header className="no-print flex items-center justify-between gap-4 bg-brand px-6 py-4 text-white sm:px-10">
      <div><p className="text-xs font-semibold uppercase tracking-[0.3em] text-teal-100">Selamat datang</p><h1 className="text-2xl font-black sm:text-4xl">{config.branch.branch_name}</h1></div>
      <div className="text-right"><p className="text-3xl font-black tabular-nums sm:text-5xl">{clock.format(now)}</p><p className="text-xs text-teal-100 sm:text-sm">{dateFormat.format(now)}</p></div>
    </header>

    {!config.availability.is_open && step !== 'ticket' && <div role="status" className="no-print flex items-center gap-3 bg-amber-100 px-6 py-3 text-amber-900 sm:px-10"><Clock size={20} />{config.availability.message ?? 'Saat ini di luar jam operasional.'} Anda tetap dapat mengambil nomor, namun pelayanan dapat tertunda.</div>}

    <section className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 py-5 sm:px-10">
      {step === 'service' && <>
        <h2 className="mb-6 text-center text-3xl font-black sm:text-4xl">Pilih layanan</h2>
        {ready ? <div className={`mx-auto grid w-full gap-4 ${config.services.length > 1 ? 'max-w-4xl sm:grid-cols-2' : 'max-w-xl'}`}>{config.services.map((item) => <button key={item.id} onClick={() => { setService(item); setStep('form') }} className="rounded-3xl bg-white p-8 text-left shadow-soft ring-1 ring-slate-200 transition active:scale-[.98] hover:ring-4 hover:ring-brand/40">
          <span className="block text-5xl font-black text-brand">{item.queue_prefix}</span><span className="mt-3 block text-2xl font-bold">{item.service_name}</span></button>)}</div>
          : <p className="rounded-2xl bg-white p-8 text-center text-xl text-slate-500">Belum ada layanan yang tersedia. Silakan hubungi petugas.</p>}
        <p className="mt-6 text-center text-slate-500">{config.availability.waiting_count} antrian sedang menunggu</p>
      </>}

      {step === 'form' && service && <div className="mx-auto w-full max-w-2xl rounded-3xl bg-white p-8 shadow-soft">
        <button onClick={reset} className="mb-4 inline-flex items-center gap-2 text-slate-500 hover:text-ink"><ArrowLeft size={20} />Ganti layanan</button>
        <p className="text-sm font-semibold uppercase tracking-wider text-slate-400">Layanan</p><p className="mb-6 text-3xl font-black text-brand">{service.service_name}</p>
        <label className="block"><span className="text-lg font-semibold">Nama Anda</span>
          <input value={name} onChange={(event) => setName(event.target.value)} autoFocus maxLength={150} autoComplete="off" placeholder="Ketik nama" className="mt-2 w-full rounded-2xl border-0 bg-slate-50 px-5 py-4 text-2xl ring-1 ring-slate-200 focus:bg-white focus:ring-4 focus:ring-brand/50" /></label>
        <label className="mt-5 block"><span className="text-lg font-semibold">Nomor telepon <span className="font-normal text-slate-400">(opsional)</span></span>
          <input value={phone} onChange={(event) => setPhone(event.target.value)} type="tel" inputMode="tel" maxLength={40} autoComplete="off" placeholder="08xxxxxxxxxx" className="mt-2 w-full rounded-2xl border-0 bg-slate-50 px-5 py-4 text-2xl ring-1 ring-slate-200 focus:bg-white focus:ring-4 focus:ring-brand/50" /></label>
        {error && <p role="alert" className="mt-4 rounded-2xl bg-red-50 p-4 text-lg text-red-700">{error}</p>}
        <button disabled={busy || name.trim().length < 2} onClick={() => void submit()} className="mt-8 w-full rounded-2xl bg-brand py-5 text-2xl font-black text-white shadow-lg transition active:scale-[.98] disabled:opacity-40">{busy ? 'Memproses…' : 'Ambil nomor antrian'}</button>
      </div>}

      {step === 'ticket' && ticket && <div className="mx-auto w-full max-w-2xl">
        <div className="print-ticket rounded-3xl bg-white p-6 text-center shadow-soft">
          <p className="no-print mb-2 inline-flex items-center gap-2 text-lg font-bold text-emerald-600"><CheckCircle2 />Nomor antrian Anda</p>
          <p className="text-lg font-semibold">{ticket.branch_name}</p><p className="text-slate-500">{ticket.queue.service_name}</p>
          <p className="ticket-number my-2 text-[5.5rem] font-black leading-none text-brand sm:text-[7rem]">{ticket.queue.queue_number}</p>
          <p className="ticket-name text-2xl font-bold">{ticket.queue.customer_name}</p>
          <p className="ticket-wrap mt-3 text-lg text-slate-600">{ticket.position ? `Urutan ke-${ticket.position} · ` : ''}Estimasi tunggu: {formatWait(ticket.estimated_wait_seconds)}</p>
          <div className="mt-3 flex flex-col items-center gap-1"><QRCodeSVG value={trackUrl} size={104} marginSize={2} /><p className="text-xs text-slate-400">Pindai untuk memantau antrian dari ponsel</p></div>
          <p className="print-only ticket-wrap mt-4 text-xs">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'full', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(ticket.queue.created_at))} · Mohon menunggu nomor Anda dipanggil.</p>
        </div>
        <div className="no-print mt-4 flex flex-wrap items-center justify-center gap-3">
          <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-2xl bg-white px-6 py-4 text-xl font-bold shadow-soft ring-1 ring-slate-200"><Printer />Cetak tiket</button>
          <button onClick={reset} className="inline-flex items-center gap-2 rounded-2xl bg-brand px-6 py-4 text-xl font-bold text-white shadow-soft"><RotateCcw />Selesai ({Math.max(0, secondsLeft)})</button>
        </div>
      </div>}
    </section>

    <footer className="no-print flex items-center justify-between px-6 py-3 text-xs text-slate-400 sm:px-10">
      <label className="flex items-center gap-2"><input type="checkbox" checked={autoPrint} onChange={(event) => toggleAutoPrint(event.target.checked)} />Cetak tiket otomatis</label>
      <button onClick={() => window.confirm('Lepas perangkat kiosk ini dari cabang? Kunci pairing diperlukan untuk mengaktifkan lagi.') && (clearKioskSession(), setConfig(null), setPhase('unpaired'))} className="hover:text-slate-600">Lepas perangkat</button>
    </footer>
  </main>
}
