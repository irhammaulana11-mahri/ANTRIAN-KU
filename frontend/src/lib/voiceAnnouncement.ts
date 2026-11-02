export interface VoiceSettings { rate: number; volume: number }

/**
 * Antrian suara berbahasa Indonesia: bunyi chime lalu kalimat pengumuman, satu per satu.
 * Harus di-activate() dari gestur pengguna (kebijakan autoplay browser).
 */
export class VoiceAnnouncer {
  readonly supported = typeof window !== 'undefined' && 'speechSynthesis' in window
  private enabled = false
  private speaking = false
  private pending: { text: string; done?: () => void }[] = []
  private context: AudioContext | null = null
  private voice: SpeechSynthesisVoice | null = null
  private settings: VoiceSettings = { rate: 1, volume: 1 }
  private static readonly MAX_PENDING = 5

  get isEnabled() { return this.enabled }

  activate() {
    if (!this.supported) return false
    this.context ??= new (window.AudioContext ?? (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext)()
    void this.context.resume()
    window.speechSynthesis.cancel()
    this.pickVoice()
    window.speechSynthesis.onvoiceschanged = () => this.pickVoice()
    this.enabled = true
    return true
  }

  deactivate() { this.enabled = false; this.clear() }
  configure(settings: VoiceSettings) { this.settings = settings }
  clear() { this.pending = []; if (this.supported) window.speechSynthesis.cancel(); this.speaking = false }

  /** Mengembalikan false bila pengumuman tidak dapat diputar (suara belum diaktifkan/tidak didukung). */
  enqueue(text: string, done?: () => void): boolean {
    if (!this.supported || !this.enabled) return false
    this.pending.push({ text, done })
    while (this.pending.length > VoiceAnnouncer.MAX_PENDING) this.pending.shift() // buang yang paling lama agar tidak menumpuk
    void this.drain()
    return true
  }

  /** Bunyi tes agar operator display bisa memeriksa volume. */
  async test(text = 'Pengumuman suara aktif.') { if (this.enabled) { await this.chime(); await this.speak(text) } }

  private pickVoice() {
    const voices = window.speechSynthesis.getVoices()
    this.voice = voices.find((voice) => voice.lang.toLowerCase() === 'id-id') ?? voices.find((voice) => voice.lang.toLowerCase().startsWith('id')) ?? null
  }

  private async drain() {
    if (this.speaking) return
    this.speaking = true
    while (this.pending.length && this.enabled) {
      const item = this.pending.shift()!
      await this.chime()
      await this.speak(item.text)
      item.done?.()
    }
    this.speaking = false
  }

  private chime(): Promise<void> {
    const context = this.context
    if (!context) return Promise.resolve()
    const start = context.currentTime
    ;[[880, 0], [660, 0.22]].forEach(([frequency, offset]) => {
      const oscillator = context.createOscillator(); const gain = context.createGain()
      oscillator.type = 'sine'; oscillator.frequency.value = frequency
      gain.gain.setValueAtTime(0.0001, start + offset)
      gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, 0.35 * this.settings.volume), start + offset + 0.03)
      gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.35)
      oscillator.connect(gain).connect(context.destination)
      oscillator.start(start + offset); oscillator.stop(start + offset + 0.4)
    })
    return new Promise((resolve) => window.setTimeout(resolve, 700))
  }

  private speak(text: string): Promise<void> {
    return new Promise((resolve) => {
      const utterance = new SpeechSynthesisUtterance(text)
      utterance.lang = 'id-ID'
      if (this.voice) utterance.voice = this.voice
      utterance.rate = this.settings.rate
      utterance.volume = this.settings.volume
      const guard = window.setTimeout(resolve, 6000 + text.length * 120) // jaga-jaga bila onend tidak pernah terpanggil
      const done = () => { window.clearTimeout(guard); resolve() }
      utterance.onend = done
      utterance.onerror = done
      window.speechSynthesis.speak(utterance)
    })
  }
}

export interface VoiceStatus {
  level: 'unsupported' | 'none' | 'other' | 'indonesian'
  message: string
  /** Nama suara Indonesia yang akan dipakai (bila ada). */
  name: string | null
}

/**
 * Menilai suara yang tersedia di perangkat. Pengumuman selalu meminta `id-ID`; bila perangkat tidak punya suara Indonesia,
 * browser memakai suara bawaan sehingga pengucapan bisa janggal — operator perlu tahu sebelum TV dipasang.
 */
export function classifyVoices(voices: { name: string; lang: string }[], supported = true): VoiceStatus {
  if (!supported) return { level: 'unsupported', name: null, message: 'Peramban ini tidak mendukung suara. Panggilan hanya ditampilkan secara visual.' }
  const indonesian = voices.find((voice) => voice.lang.toLowerCase().replace('_', '-') === 'id-id') ?? voices.find((voice) => voice.lang.toLowerCase().startsWith('id'))
  if (indonesian) return { level: 'indonesian', name: indonesian.name, message: `Suara Bahasa Indonesia terdeteksi: ${indonesian.name} (${indonesian.lang}).` }
  if (voices.length === 0) return { level: 'none', name: null, message: 'Perangkat belum menyediakan suara apa pun (atau suara masih dimuat). Pasang/aktifkan suara Text-to-speech Bahasa Indonesia pada perangkat, lalu muat ulang halaman.' }
  return { level: 'other', name: null, message: `Tidak ada suara Bahasa Indonesia di perangkat ini (tersedia ${voices.length} suara lain). Pengumuman memakai suara bawaan sehingga pengucapan dapat tidak tepat — pasang suara "Bahasa Indonesia" di pengaturan Text-to-speech perangkat.` }
}

/** Membaca suara perangkat saat ini (daftar dapat kosong beberapa saat sebelum event `voiceschanged`). */
export function currentVoiceStatus(): VoiceStatus {
  const supported = typeof window !== 'undefined' && 'speechSynthesis' in window
  return classifyVoices(supported ? window.speechSynthesis.getVoices() : [], supported)
}
