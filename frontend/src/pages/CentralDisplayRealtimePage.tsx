import { useEffect, useMemo, useRef, useState } from 'react'
import { Radio, Settings, Volume2, VolumeX, WifiOff } from 'lucide-react'
import { activeByCounter, areaOf, buildAnnouncement, type DisplayArea, type DisplayCall } from '@/lib/announcement'
import { useDisplayFeed } from '@/hooks/useDisplayFeed'
import { DevicePairingScreen } from '@/components/DevicePairingScreen'
import { MediaPanel } from '@/components/display/MediaPanel'
import { currentVoiceStatus, VoiceAnnouncer, type VoiceSettings, type VoiceStatus } from '@/lib/voiceAnnouncement'

const VOICE_KEY = 'sqms_display_voice'
const AREAS: { key: DisplayArea; title: string }[] = [{ key: 'cs', title: 'Customer Service' }, { key: 'teller', title: 'Teller' }]
const clock = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' })
const dateFormat = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' })

function loadVoiceOverride(): VoiceSettings | null {
  try { const raw = localStorage.getItem(VOICE_KEY); return raw ? (JSON.parse(raw) as VoiceSettings) : null } catch { return null }
}

export function CentralDisplayRealtimePage() {
  const voice = useRef(new VoiceAnnouncer())
  const [audioOn, setAudioOn] = useState(false)
  const [now, setNow] = useState(new Date())
  const [showSettings, setShowSettings] = useState(false)
  const [override, setOverride] = useState<VoiceSettings | null>(loadVoiceOverride)
  const [flashKey, setFlashKey] = useState('')
  const [voiceStatus, setVoiceStatus] = useState<VoiceStatus>(() => currentVoiceStatus())

  const { phase, error, snapshot, calls, connected, pair, unpair } = useDisplayFeed((call, speakable) => {
    setFlashKey(call.event_id)
    if (speakable) voice.current.enqueue(buildAnnouncement(call)) // panggilan kedaluwarsa hanya tampil, tidak diucapkan
  })

  // Kunci pairing bisa dibuka lewat tautan /display#key=... lalu langsung dihapus dari URL.
  useEffect(() => {
    const key = new URLSearchParams(window.location.hash.slice(1)).get('key')
    if (key) { window.history.replaceState(null, '', window.location.pathname); void pair(key) }
  }, [pair])
  useEffect(() => { const timer = window.setInterval(() => setNow(new Date()), 1000); return () => window.clearInterval(timer) }, [])
  useEffect(() => { const announcer = voice.current; return () => announcer.clear() }, [])
  // Daftar suara dimuat asinkron; perbarui status saat berubah dan beberapa kali di awal.
  useEffect(() => {
    if (!('speechSynthesis' in window)) return
    const refresh = () => setVoiceStatus(currentVoiceStatus())
    window.speechSynthesis.addEventListener('voiceschanged', refresh)
    const timers = [500, 1500, 4000].map((delay) => window.setTimeout(refresh, delay))
    return () => { window.speechSynthesis.removeEventListener('voiceschanged', refresh); timers.forEach(window.clearTimeout) }
  }, [])
  useEffect(() => { if (snapshot) voice.current.configure(override ?? { rate: snapshot.settings.voice_rate, volume: snapshot.settings.voice_volume }) }, [snapshot, override])
  useEffect(() => { if (!flashKey) return; const timer = window.setTimeout(() => setFlashKey(''), 8000); return () => window.clearTimeout(timer) }, [flashKey])

  const latest = calls[0]
  const media = snapshot?.settings.media ?? []
  const active = useMemo(() => activeByCounter(calls), [calls])
  // Riwayat: satu baris per antrian (panggilan ulang tidak digandakan) dan tanpa antrian yang sedang ditampilkan besar.
  const history = useMemo(() => {
    const seen = new Set<string>(latest ? [latest.queue_id] : [])
    return calls.filter((call) => { if (seen.has(call.queue_id)) return false; seen.add(call.queue_id); return true }).slice(0, media.length > 0 ? 4 : 6)
  }, [calls, latest, media.length])
  const voiceSettings = override ?? { rate: snapshot?.settings.voice_rate ?? 1, volume: snapshot?.settings.voice_volume ?? 1 }
  const mediaVolume = snapshot?.settings.media_volume ?? 0.35

  if (phase === 'unpaired') return <DevicePairingScreen title="Aktivasi Central Display" hint="Masukkan kunci pairing perangkat Central Display yang dibuat Admin Cabang di menu Perangkat." error={error} onPair={pair} />
  if (phase === 'checking' || !snapshot) return <main className="grid min-h-screen place-items-center bg-slate-950 text-slate-300">Menghubungkan display…</main>

  const changeVoice = (next: VoiceSettings) => { setOverride(next); try { localStorage.setItem(VOICE_KEY, JSON.stringify(next)) } catch { /* penyimpanan tidak tersedia */ } }
  const activateAudio = () => setAudioOn(voice.current.activate())

  return <main className="flex min-h-screen flex-col bg-slate-950 p-4 text-white sm:p-6 lg:h-screen lg:overflow-hidden">
    <header className="flex flex-wrap items-center justify-between gap-4">
      <div>
        <p className="text-sm font-semibold uppercase tracking-[0.3em] text-teal-300">Informasi Antrian</p>
        <h1 className="mt-1 text-3xl font-black sm:text-5xl">{snapshot.branch.branch_name}</h1>
      </div>
      <div className="flex items-center gap-4">
        <div className="text-right"><p className="text-4xl font-black tabular-nums sm:text-6xl">{clock.format(now)}</p><p className="text-sm text-slate-400 sm:text-base">{dateFormat.format(now)}</p></div>
        <div className="relative flex flex-col items-end gap-2">
          <span className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold ${connected ? 'bg-emerald-500/15 text-emerald-300' : 'bg-red-500/20 text-red-200'}`}>{connected ? <Radio size={16} /> : <WifiOff size={16} />}{connected ? 'Live' : 'Terputus'}</span>
          <button title="Pengaturan suara" className="rounded-full bg-white/10 p-2.5 hover:bg-white/20" onClick={() => setShowSettings(!showSettings)}><Settings size={18} /></button>
          {showSettings && <div className="absolute right-0 top-full z-10 mt-2 w-72 space-y-4 rounded-2xl bg-slate-800 p-5 text-sm shadow-2xl">
            <label className="block">Volume ({Math.round(voiceSettings.volume * 100)}%)<input type="range" min={0} max={1} step={0.05} value={voiceSettings.volume} onChange={(event) => changeVoice({ ...voiceSettings, volume: Number(event.target.value) })} className="mt-2 w-full" /></label>
            <label className="block">Kecepatan ({voiceSettings.rate.toFixed(2)}x)<input type="range" min={0.5} max={1.5} step={0.05} value={voiceSettings.rate} onChange={(event) => changeVoice({ ...voiceSettings, rate: Number(event.target.value) })} className="mt-2 w-full" /></label>
            <p role="status" className={`rounded-xl p-3 text-xs ${voiceStatus.level === 'indonesian' ? 'bg-emerald-500/15 text-emerald-200' : 'bg-amber-500/15 text-amber-200'}`}>{voiceStatus.message}</p>
            <div className="flex gap-2"><button disabled={!audioOn} className="flex-1 rounded-xl bg-teal-500 px-3 py-2 font-bold text-slate-950 disabled:opacity-40" onClick={() => void voice.current.test()}>Tes suara</button><button className="rounded-xl bg-white/10 px-3 py-2" onClick={() => window.confirm('Lepas perangkat ini dari cabang? Kunci pairing diperlukan untuk mengaktifkan lagi.') && unpair()}>Lepas</button></div>
          </div>}
        </div>
      </div>
    </header>

    {!voice.current.supported && <div className="mt-4 rounded-2xl bg-amber-500/15 p-3 text-amber-200">Peramban ini tidak mendukung suara. Panggilan hanya ditampilkan secara visual.</div>}
    {voice.current.supported && !audioOn && <button onClick={activateAudio} className="mt-4 flex items-center justify-center gap-3 rounded-2xl bg-amber-400 p-4 text-lg font-black text-slate-950"><VolumeX />Ketuk untuk mengaktifkan suara pengumuman</button>}
    {audioOn && <p className="mt-3 flex items-center gap-2 text-sm text-emerald-300"><Volume2 size={16} />Suara pengumuman aktif</p>}

    <section className={`mt-4 grid gap-4 lg:min-h-0 lg:flex-1 ${media.length > 0 ? 'lg:grid-cols-[1.4fr_.6fr]' : 'lg:grid-cols-[1.5fr_.5fr]'}`}>
      <div key={latest?.event_id} className={`flex flex-col justify-center rounded-[2rem] bg-gradient-to-br from-teal-500 to-cyan-700 p-8 shadow-2xl sm:p-12 ${flashKey && flashKey === latest?.event_id ? 'animate-pop ring-8 ring-amber-300' : ''}`}>
        <p className="text-lg font-bold uppercase tracking-[0.25em] text-teal-50">{latest?.call_type === 'RECALL' ? 'Panggilan ulang' : 'Nomor dipanggil'}</p>
        <p className="mt-4 text-[clamp(4.5rem,min(16vw,26vh),12rem)] font-black leading-none tracking-tight">{latest?.queue_number ?? '—'}</p>
        <p className="mt-4 truncate pb-1 text-3xl font-semibold leading-normal sm:text-5xl">{latest?.customer_name ?? 'Menunggu panggilan berikutnya'}</p>
        {latest && <p className="mt-4 text-2xl font-bold text-amber-200 sm:text-4xl">→ {latest.counter_name || latest.counter_code}<span className="ml-3 text-lg font-medium text-teal-50 sm:text-2xl">{latest.service_name}</span></p>}
      </div>
      <div className="flex flex-col gap-4 lg:min-h-0">
      {media.length > 0 && <MediaPanel sources={media} volume={mediaVolume} />}
      <div className="rounded-[2rem] bg-white/10 p-5 lg:min-h-0 lg:flex-1 lg:overflow-hidden">
        <h2 className="text-xl font-bold">Riwayat panggilan</h2>
        <ul className="mt-4 space-y-2">{history.map((call) => <li key={call.event_id} className="rounded-2xl bg-white/10 px-4 py-3"><div className="flex items-center justify-between"><span className="text-2xl font-black text-teal-200">{call.queue_number}</span><span className="text-sm text-slate-300">{call.counter_code}</span></div><p className="truncate text-sm text-slate-400">{call.customer_name}</p></li>)}{history.length === 0 && <li className="text-slate-400">Belum ada riwayat.</li>}</ul>
      </div>
      </div>
    </section>

    <section className="mt-4 grid gap-4 md:grid-cols-2">
      {AREAS.map((area) => {
        const items = active.filter((call: DisplayCall) => areaOf(call) === area.key)
        return <div key={area.key} className="rounded-[2rem] bg-white/10 p-4">
          <h2 className="text-lg font-bold text-teal-200">{area.title}</h2>
          <div className="mt-3 grid gap-2 sm:grid-cols-2">{items.map((call) => <div key={call.counter_code} className="rounded-2xl bg-slate-900 p-3"><p className="text-sm text-slate-400">{call.counter_name || call.counter_code}</p><p className="text-3xl font-black">{call.queue_number}</p><p className="truncate text-sm text-slate-300">{call.customer_name}</p></div>)}{items.length === 0 && <p className="text-slate-400 sm:col-span-2">Belum ada panggilan aktif.</p>}</div>
        </div>
      })}
    </section>

    {snapshot.waiting.length > 0 && <p className="mt-4 text-center text-slate-300">{snapshot.waiting.map((item) => `${item.service_name}: ${item.total} menunggu`).join('  •  ')}</p>}
    <footer className="mt-4 overflow-hidden rounded-2xl bg-white/10 py-3">
      <p className="animate-marquee whitespace-nowrap text-lg text-amber-100">{snapshot.settings.announcement || `Selamat datang di ${snapshot.branch.branch_name}. Mohon menunggu nomor antrian Anda dipanggil.`}</p>
    </footer>
  </main>
}
