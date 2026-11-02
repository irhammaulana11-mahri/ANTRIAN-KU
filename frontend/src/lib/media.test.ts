import { describe, expect, it } from 'vitest'
import { describeFailure, higherPriorityIndexes, isSoftFailure, MAX_PLAYLIST_SKIPS, MAX_SOFT_RETRIES, youtubeStuckAction, isHlsUrl, nextAfterFailure, parseYouTubeMessage, sourceTitle, youtubeEmbedUrl } from '@/lib/media'

describe('sumber video display', () => {
  it('mengenali stream HLS dari URL (termasuk query string)', () => {
    expect(isHlsUrl('http://192.168.1.10/iptv/live.m3u8')).toBe(true)
    expect(isHlsUrl('https://cdn.example.test/live.M3U8?token=abc')).toBe(true)
    expect(isHlsUrl('https://cdn.example.test/video.mp4')).toBe(false)
  })

  it('membangun embed YouTube video: tanpa suara, berulang, dan memantau status', () => {
    const url = new URL(youtubeEmbedUrl({ youtube_kind: 'video', youtube_id: 'dQw4w9WgXcQ' }, 'https://sqms.example.com'))
    expect(url.origin + url.pathname).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
    expect(url.searchParams.get('mute')).toBe('1')
    expect(url.searchParams.get('autoplay')).toBe('1')
    expect(url.searchParams.get('controls')).toBe('0')
    expect(url.searchParams.get('enablejsapi')).toBe('1')
    expect(url.searchParams.get('origin')).toBe('https://sqms.example.com')
    expect(url.searchParams.get('loop')).toBe('1')
    expect(url.searchParams.get('playlist')).toBe('dQw4w9WgXcQ') // syarat loop pada video tunggal
  })

  it('membangun embed YouTube playlist', () => {
    const url = new URL(youtubeEmbedUrl({ youtube_kind: 'playlist', youtube_id: 'PLabcdefghij' }, 'http://localhost:5173'))
    expect(url.pathname).toBe('/embed/videoseries')
    expect(url.searchParams.get('list')).toBe('PLabcdefghij')
  })

  it('tidak memasukkan karakter berbahaya dari id ke path embed', () => {
    const url = youtubeEmbedUrl({ youtube_kind: 'video', youtube_id: '../x"><' }, 'https://a.test')
    expect(url).not.toContain('"><')
    expect(url.startsWith('https://www.youtube-nocookie.com/embed/')).toBe(true)
  })

  it('membaca pesan status dari iframe YouTube', () => {
    expect(parseYouTubeMessage('{"event":"onStateChange","info":1}')).toEqual({ state: 1 })
    expect(parseYouTubeMessage({ event: 'infoDelivery', info: { playerState: 3 } })).toEqual({ state: 3 })
    expect(parseYouTubeMessage('{"event":"onError","info":150}')).toEqual({ error: 150 })
    expect(parseYouTubeMessage('{"event":"onReady"}')).toEqual({})
    expect(parseYouTubeMessage('bukan json')).toEqual({})
    expect(parseYouTubeMessage(null)).toEqual({})
    expect(parseYouTubeMessage(42)).toEqual({})
  })

  it('failover: lanjut ke sumber berikutnya, null bila semua gagal', () => {
    expect(nextAfterFailure(0, 3)).toBe(1)
    expect(nextAfterFailure(1, 3)).toBe(2)
    expect(nextAfterFailure(2, 3)).toBeNull()
    expect(nextAfterFailure(0, 1)).toBeNull()
  })

  it('sumber lebih prioritas yang dicoba ulang hanyalah yang di atas sumber aktif', () => {
    expect(higherPriorityIndexes(0)).toEqual([])
    expect(higherPriorityIndexes(2)).toEqual([0, 1])
  })

  it('judul sumber: label bila ada, jika tidak jenisnya', () => {
    expect(sourceTitle({ type: 'STREAM', label: 'IPTV Lobi', url: 'x', youtube_kind: null, youtube_id: null })).toBe('IPTV Lobi')
    expect(sourceTitle({ type: 'STREAM', label: null, url: 'x', youtube_kind: null, youtube_id: null })).toBe('IPTV')
    expect(sourceTitle({ type: 'YOUTUBE', label: '  ', url: 'x', youtube_kind: 'video', youtube_id: 'a' })).toBe('YouTube')
  })

  it('menjelaskan penyebab kegagalan dalam bahasa yang dipahami operator', () => {
    expect(describeFailure('youtube:150')).toContain('tidak mengizinkan')
    expect(describeFailure('youtube:101')).toContain('tidak mengizinkan')
    expect(describeFailure('youtube:100')).toContain('tidak ditemukan')
    expect(describeFailure('timeout')).toContain('batas waktu')
    expect(describeFailure('hls:networkError')).toContain('CORS')
    expect(describeFailure('apa-saja')).toBe('Sumber tidak dapat diputar.')
  })

  it('playlist: video yang tidak tersedia dilompati sampai batas; video tunggal langsung gagal', () => {
    expect(youtubeStuckAction('playlist', 0)).toBe('skip')
    expect(youtubeStuckAction('playlist', MAX_PLAYLIST_SKIPS - 1)).toBe('skip')
    expect(youtubeStuckAction('playlist', MAX_PLAYLIST_SKIPS)).toBe('fail')
    expect(youtubeStuckAction('video', 0)).toBe('fail')
    expect(youtubeStuckAction(null, 0)).toBe('fail')
  })

  it('pemutar macet dimuat ulang diam-diam beberapa kali sebelum dianggap gagal', () => {
    expect(isSoftFailure('stuck', 0)).toBe(true)
    expect(isSoftFailure('stuck', MAX_SOFT_RETRIES - 1)).toBe(true)
    expect(isSoftFailure('stuck', MAX_SOFT_RETRIES)).toBe(false)
    expect(isSoftFailure('timeout', 0)).toBe(false) // tidak ada respons sama sekali → bukan kasus muat-ulang cepat
    expect(isSoftFailure('youtube:150', 0)).toBe(false)
    expect(describeFailure('stuck')).toContain('memuat ulang')
  })
})
