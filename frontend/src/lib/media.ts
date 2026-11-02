import type { DisplayMediaSource } from '@/types/api'

export const isHlsUrl = (url: string) => /\.m3u8(\?|#|$)/i.test(url)

/** Embed YouTube tanpa suara, tanpa kontrol, berulang; `enablejsapi` agar status pemutaran dapat dipantau lewat postMessage. */
export function youtubeEmbedUrl(source: Pick<DisplayMediaSource, 'youtube_kind' | 'youtube_id'>, origin: string): string {
  const id = source.youtube_id ?? ''
  const params = new URLSearchParams({ autoplay: '1', mute: '1', controls: '0', disablekb: '1', fs: '0', modestbranding: '1', rel: '0', iv_load_policy: '3', playsinline: '1', loop: '1', enablejsapi: '1', origin })
  if (source.youtube_kind === 'playlist') { params.set('list', id); return `https://www.youtube-nocookie.com/embed/videoseries?${params}` }
  params.set('playlist', id) // loop pada video tunggal mensyaratkan parameter playlist = id video
  return `https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?${params}`
}

export interface YouTubeSignal { state?: number; error?: number }

/** Membaca pesan dari iframe YouTube (onStateChange / infoDelivery / onError). Pesan lain → objek kosong. */
export function parseYouTubeMessage(data: unknown): YouTubeSignal {
  let message: unknown = data
  if (typeof data === 'string') { try { message = JSON.parse(data) } catch { return {} } }
  if (typeof message !== 'object' || message === null) return {}
  const { event, info } = message as { event?: string; info?: unknown }
  if (event === 'onError' && typeof info === 'number') return { error: info }
  if (event === 'onStateChange' && typeof info === 'number') return { state: info }
  if (event === 'infoDelivery' && typeof info === 'object' && info !== null && typeof (info as { playerState?: unknown }).playerState === 'number') return { state: (info as { playerState: number }).playerState }
  return {}
}
export const YT_PLAYING = 1
export const YT_CUED = 5
export const YT_UNSTARTED = -1

/** Sumber lebih prioritas yang layak dicoba ulang di latar belakang selagi sumber cadangan sedang tayang. */
export const higherPriorityIndexes = (active: number): number[] => Array.from({ length: Math.max(0, active) }, (_, index) => index)

/** Setelah sumber `current` gagal: indeks berikutnya, atau null bila semua sumber sudah dicoba (lalu mulai ulang dari awal setelah jeda). */
export const nextAfterFailure = (current: number, count: number): number | null => (current + 1 < count ? current + 1 : null)

export const sourceTitle = (source: DisplayMediaSource): string => source.label?.trim() || (source.type === 'YOUTUBE' ? 'YouTube' : 'IPTV')

/** Alasan kegagalan (kode internal dari pemutar) → kalimat singkat untuk operator yang memasang TV. */
export function describeFailure(reason: string): string {
  const code = /^youtube:(-?\d+)$/.exec(reason)?.[1]
  if (code !== undefined) {
    if (['101', '150', '153'].includes(code)) return 'Pemilik video tidak mengizinkan video diputar di situs lain (kode ' + code + ').'
    if (code === '100') return 'Video tidak ditemukan atau bersifat pribadi (kode 100).'
    if (code === '2') return 'Parameter pemutar YouTube tidak valid (kode 2).'
    return 'YouTube melaporkan galat pemutaran (kode ' + code + ').'
  }
  if (reason === 'stuck') return 'Pemutar YouTube tidak mulai memutar (memuat ulang otomatis).'
  if (reason === 'timeout') return 'Tidak ada respons dari sumber dalam batas waktu.'
  if (reason === 'stalled') return 'Siaran berhenti/macet terlalu lama.'
  if (reason.startsWith('hls')) return 'Stream tidak dapat dimuat (periksa alamat, jaringan, dan header CORS server stream).'
  if (reason === 'unsupported') return 'Peramban ini tidak mendukung format stream tersebut.'
  return 'Sumber tidak dapat diputar.'
}

/** Batas lompat ke video berikutnya pada playlist sebelum sumber dianggap gagal. */
export const MAX_PLAYLIST_SKIPS = 6

/**
 * Keputusan saat pemutar YouTube macet atau melaporkan galat. Pada playlist, satu video yang tidak dapat diputar
 * (privat, dihapus, penyematan dimatikan, belum tayang) tidak boleh mematikan seluruh sumber — pemutar YouTube
 * menunggu klik "Skip video" yang tak mungkin dilakukan di TV, jadi kita yang melompat. Video tunggal langsung gagal.
 */
export function youtubeStuckAction(kind: DisplayMediaSource['youtube_kind'], skipsSoFar: number): 'skip' | 'fail' {
  return kind === 'playlist' && skipsSoFar < MAX_PLAYLIST_SKIPS ? 'skip' : 'fail'
}

/** Berapa kali pemutar yang macet dimuat ulang diam-diam (tanpa pindah sumber/menampilkan "tidak tersambung") sebelum dianggap gagal. */
export const MAX_SOFT_RETRIES = 3

/** Kegagalan yang layak diatasi dengan memuat ulang sumber yang sama (pemuatan YouTube kadang macet sesaat). */
export const isSoftFailure = (reason: string, retriesSoFar: number): boolean => reason === 'stuck' && retriesSoFar < MAX_SOFT_RETRIES
