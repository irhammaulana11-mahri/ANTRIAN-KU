import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Tv, WifiOff } from 'lucide-react'
import { describeFailure, higherPriorityIndexes, isSoftFailure, youtubeStuckAction, isHlsUrl, nextAfterFailure, parseYouTubeMessage, sourceTitle, YT_CUED, YT_PLAYING, YT_UNSTARTED, youtubeEmbedUrl } from '@/lib/media'
import type { DisplayMediaSource } from '@/types/api'

const CONNECT_TIMEOUT_MS = 12_000
const YOUTUBE_CONNECT_TIMEOUT_MS = 30_000 // pemuatan awal pemutar YouTube di TV/jaringan lambat bisa memakan waktu; juga memberi ruang untuk melompati video playlist yang tidak tersedia
const YOUTUBE_NUDGE_AFTER_MS = 8_000 // pemutar sudah menjawab tetapi belum memutar → dorong sekali (playVideo)
const YOUTUBE_STUCK_AFTER_MS = 13_000 // masih belum memutar → dianggap macet; panel memuat ulang pemutar yang sama
const STALL_TIMEOUT_MS = 20_000
// Dapat diubah saat build (VITE_MEDIA_PROBE_SECONDS / VITE_MEDIA_RETRY_SECONDS); bawaan: cek sumber prioritas tiap 3 menit, ulang semua tiap 30 detik.
const seconds = (value: unknown, fallback: number) => (Number.isFinite(Number(value)) && Number(value) > 0 ? Number(value) : fallback) * 1000
const PROBE_INTERVAL_MS = seconds(import.meta.env.VITE_MEDIA_PROBE_SECONDS, 180)
const RETRY_ALL_MS = seconds(import.meta.env.VITE_MEDIA_RETRY_SECONDS, 30)

interface PlayerProps { source: DisplayMediaSource; hidden?: boolean; paused?: boolean; volume?: number; onPlaying: () => void; onFail: (reason: string) => void }

function useLatest<T>(value: T) { const ref = useRef(value); ref.current = value; return ref }

function StreamPlayer({ source, hidden, paused = false, volume = 0.35, onPlaying, onFail }: PlayerProps) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const playing = useLatest(onPlaying); const failed = useLatest(onFail)
  useEffect(() => {
    const video = videoRef.current
    if (!video) return
    let settled = false
    let hls: { destroy: () => void } | undefined
    let stall: number | undefined
    const fail = (reason: string) => { if (settled) return; settled = true; window.clearTimeout(connect); window.clearTimeout(stall); failed.current(reason) }
    const connect = window.setTimeout(() => fail('timeout'), CONNECT_TIMEOUT_MS)
    const onPlay = () => { window.clearTimeout(stall); if (!settled) { settled = true; window.clearTimeout(connect); playing.current() } }
    const onWaiting = () => { if (settled) { window.clearTimeout(stall); stall = window.setTimeout(() => { settled = false; fail('stalled') }, STALL_TIMEOUT_MS) } }
    video.volume = volume
    video.muted = paused || volume === 0
    video.addEventListener('playing', onPlay)
    video.addEventListener('waiting', onWaiting)
    video.addEventListener('error', () => fail('error'))
    if (isHlsUrl(source.url) && !video.canPlayType('application/vnd.apple.mpegurl')) {
      void import('hls.js').then(({ default: Hls }) => {
        if (settled) return
        if (!Hls.isSupported()) return fail('unsupported')
        const player = new Hls({ enableWorker: false, manifestLoadingMaxRetry: 1, levelLoadingMaxRetry: 2, fragLoadingMaxRetry: 2 }) // tanpa worker: CSP tidak perlu worker-src blob:
        hls = player
        player.on(Hls.Events.MANIFEST_PARSED, () => { void video.play().catch(() => undefined) })
        player.on(Hls.Events.ERROR, (_event, data) => { if (data.fatal) fail(`hls:${data.type}`) })
        player.loadSource(source.url)
        player.attachMedia(video)
      }).catch(() => fail('hls-load'))
    } else {
      video.src = source.url
      void video.play().catch(() => undefined)
    }
    return () => { settled = true; window.clearTimeout(connect); window.clearTimeout(stall); video.removeEventListener('playing', onPlay); video.removeEventListener('waiting', onWaiting); hls?.destroy(); video.pause(); video.removeAttribute('src'); video.load() }
  }, [source.url, playing, failed])
  useEffect(() => {
    const video = videoRef.current
    if (!video) return
    video.volume = volume
    video.muted = paused || volume === 0
    if (paused) video.pause()
    else void video.play().catch(() => undefined)
  }, [paused, volume])
  return <video ref={videoRef} autoPlay playsInline loop={!isHlsUrl(source.url)} aria-hidden={hidden} className="h-full w-full bg-black object-cover" />
}

function YouTubePlayer({ source, paused = false, volume = 0.35, onPlaying, onFail }: PlayerProps) {
  const frameRef = useRef<HTMLIFrameElement>(null)
  const playing = useLatest(onPlaying); const failed = useLatest(onFail)
  const src = useMemo(() => youtubeEmbedUrl(source, window.location.origin), [source])
  const send = (payload: object) => frameRef.current?.contentWindow?.postMessage(JSON.stringify(payload), '*')
  useEffect(() => {
    let settled = false
    let answered = false
    let skips = 0
    const stop = () => { window.clearTimeout(connect); window.clearInterval(handshake); window.clearInterval(watchdog) }
    const fail = (reason: string) => { if (settled) return; settled = true; stop(); failed.current(reason) }
    const skipOrFail = (reason: string) => {
      if (youtubeStuckAction(source.youtube_kind, skips) === 'fail') return fail(reason)
      skips += 1
      send({ event: 'command', func: 'nextVideo', args: [] })
    }
    const connect = window.setTimeout(() => fail('timeout'), YOUTUBE_CONNECT_TIMEOUT_MS)
    // Pesan "listening" hilang bila pemutar YouTube belum siap saat iframe selesai dimuat (balapan). Seperti IFrame API resmi,
    // kirim berulang sampai pemutar menjawab; tanpa ini pemutar tidak pernah melaporkan status dan kita salah mengira gagal.
    const handshake = window.setInterval(() => { if (!answered) send({ event: 'listening', id: 1, channel: 'widget' }) }, 400)
    // Pemutar sudah menjawab tetapi tak kunjung memutar. Kasus nyata: playlist gagal dimuat sesaat (status -1, video_id "videoseries")
    // atau "Video unavailable" menunggu klik "Skip". Dorong sekali; bila tetap macet, lapor 'stuck' agar panel memuat ulang pemutar.
    let nudged = false
    const startedAt = Date.now()
    const watchdog = window.setInterval(() => {
      if (settled || !answered) return
      const waited = Date.now() - startedAt
      if (!nudged && waited >= YOUTUBE_NUDGE_AFTER_MS) { nudged = true; send({ event: 'command', func: 'playVideo', args: [] }) }
      if (waited >= YOUTUBE_STUCK_AFTER_MS) fail('stuck')
    }, 1000)
    const onMessage = (event: MessageEvent) => {
      if (event.source !== frameRef.current?.contentWindow) return // hanya dari iframe ini
      answered = true
      const signal = parseYouTubeMessage(event.data)
      if (signal.error !== undefined) return skipOrFail(`youtube:${signal.error}`)
      if (signal.state === YT_PLAYING && !settled) {
        settled = true
        stop()
        send({ event: 'command', func: 'setVolume', args: [Math.round(volume * 100)] })
        send({ event: 'command', func: volume === 0 || paused ? 'mute' : 'unMute', args: [] })
        playing.current()
      }
      if (signal.state === YT_CUED || signal.state === YT_UNSTARTED) send({ event: 'command', func: 'playVideo', args: [] })
    }
    window.addEventListener('message', onMessage)
    return () => { settled = true; stop(); window.removeEventListener('message', onMessage) }
  }, [src, playing, failed])
  useEffect(() => {
    send({ event: 'command', func: paused ? 'pauseVideo' : 'playVideo', args: [] })
    send({ event: 'command', func: 'setVolume', args: [Math.round(volume * 100)] })
    send({ event: 'command', func: volume === 0 || paused ? 'mute' : 'unMute', args: [] })
  }, [paused, volume])
  return <iframe ref={frameRef} src={src} title={sourceTitle(source)} allow="autoplay; encrypted-media" referrerPolicy="strict-origin-when-cross-origin" className="h-full w-full border-0" />
}

function SourcePlayer(props: PlayerProps) {
  return props.source.type === 'YOUTUBE' ? <YouTubePlayer {...props} /> : <StreamPlayer {...props} />
}

/**
 * Panel video TV. Sumber diurutkan menurut prioritas: dipakai yang pertama tersambung; bila gagal/macet pindah ke berikutnya.
 * Selagi memakai sumber cadangan, sumber yang lebih prioritas dicoba lagi diam-diam tiap beberapa menit (pemutar tersembunyi, tanpa suara)
 * dan dipakai begitu tersambung. Volume media dapat diatur dari pengaturan display.
 */
/**
 * `fill` — gunakan h-full w-full (isi tinggi container) alih-alih aspect-video.
 * Dipakai di landscape agar video tidak memaksa tinggi tetap 16:9 yang bisa melampaui layar.
 * Parent harus memiliki tinggi yang terdefinisi (mis. flex-1 dalam flex container).
 */
export function MediaPanel({ sources, paused = false, volume = 0.35, fill = false }: { sources: DisplayMediaSource[]; paused?: boolean; volume?: number; fill?: boolean }) {
  const signature = useMemo(() => JSON.stringify(sources.map((source) => [source.type, source.url, source.youtube_id])), [sources])
  const [active, setActive] = useState(0)
  const [status, setStatus] = useState<'connecting' | 'playing' | 'offline'>('connecting')
  const [generation, setGeneration] = useState(0)
  const [reason, setReason] = useState('')
  const [probe, setProbe] = useState<number | null>(null)
  const probeCursor = useRef(0)
  const softRetries = useRef(0)

  useEffect(() => { softRetries.current = 0; setActive(0); setStatus('connecting'); setProbe(null); setGeneration((value) => value + 1) }, [signature])

  const handleFail = useCallback((failure: string) => {
    if (isSoftFailure(failure, softRetries.current)) { softRetries.current += 1; setGeneration((value) => value + 1); return } // muat ulang pemutar yang sama, tetap "menghubungkan"
    setReason(failure)
    const next = nextAfterFailure(active, sources.length)
    if (next !== null) { setActive(next); setStatus('connecting'); setGeneration((value) => value + 1) } else setStatus('offline')
  }, [active, sources.length])
  useEffect(() => { // semua sumber gagal → ulangi dari yang paling prioritas setelah jeda
    if (status !== 'offline') return
    const timer = window.setTimeout(() => { setActive(0); setStatus('connecting'); setGeneration((value) => value + 1) }, RETRY_ALL_MS)
    return () => window.clearTimeout(timer)
  }, [status])
  useEffect(() => { // coba sumber lebih prioritas secara berkala
    if (status !== 'playing' || active === 0) return
    const timer = window.setInterval(() => { const candidates = higherPriorityIndexes(active); setProbe(candidates[probeCursor.current++ % candidates.length]) }, PROBE_INTERVAL_MS)
    return () => window.clearInterval(timer)
  }, [status, active])

  const source = sources[active]
  if (!source) return null
  return <div className={`relative overflow-hidden rounded-[1.5rem] bg-black shadow-xl ${fill ? 'h-full w-full' : 'aspect-video w-full'}`} data-media-status={status} data-media-active={active} data-media-reason={reason}>
    {status !== 'offline' && <SourcePlayer key={`${signature}-${active}-${generation}`} source={source} paused={paused} volume={volume} onPlaying={() => { softRetries.current = 0; setStatus('playing') }} onFail={handleFail} />}
    {probe !== null && sources[probe] && <div className="pointer-events-none fixed -left-[9999px] top-0 h-[180px] w-[320px] opacity-0" aria-hidden="true">
      <SourcePlayer key={`probe-${probe}`} hidden source={sources[probe]} onFail={() => setProbe(null)} onPlaying={() => { setActive(probe); setStatus('playing'); setGeneration((value) => value + 1); setProbe(null) }} />
    </div>}
    {status === 'connecting' && <div className="absolute inset-0 grid place-items-center bg-slate-900/80 text-slate-300"><span className="flex items-center gap-2 text-sm"><Tv size={18} />Menghubungkan {sourceTitle(source)}…</span></div>}
    {status === 'offline' && <div className="absolute inset-0 grid place-items-center bg-slate-900 text-slate-400"><span className="flex flex-col items-center gap-2 text-center text-sm"><WifiOff size={24} />Video tidak tersambung<br /><span className="max-w-[90%] text-xs text-slate-500">{reason ? describeFailure(reason) : null}</span><span className="text-xs text-slate-500">Mencoba kembali otomatis…</span></span></div>}
    {status === 'playing' && <span className="absolute bottom-2 left-3 rounded-full bg-black/55 px-2.5 py-0.5 text-[11px] font-medium text-white/80">{sourceTitle(source)}{active > 0 ? ' · sumber cadangan' : ''}</span>}
  </div>
}
