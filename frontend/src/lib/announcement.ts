import type { RealtimeEvent } from '@/types/realtime'

export interface DisplayCall {
  event_id: string
  queue_id: string
  queue_number: string
  customer_name: string
  queue_status: string
  service_name: string
  counter_code: string
  counter_name: string
  counter_type: string | null
  call_type: 'INITIAL' | 'RECALL'
  called_at: string
}

const DIGITS = ['nol', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan']

/** Angka dibaca satu per satu ("012" → "nol satu dua"); huruf prefiks dieja agar jelas diucapkan. */
export function spell(value = ''): string {
  return value.replace(/-/g, ' ').split('').map((char) => (/\d/.test(char) ? DIGITS[Number(char)] : char)).join(' ').replace(/\s+/g, ' ').trim()
}

/** Teks biasa dengan angka diubah ke kata ("Loket 1" → "Loket satu"); huruf tidak dieja. */
export function speakDigits(value = ''): string {
  return value.replace(/\d/g, (digit) => ` ${DIGITS[Number(digit)]} `).replace(/\s+/g, ' ').trim()
}

/** Teks pengumuman, mis. "Nomor antrian C S nol nol dua, Budi, silakan menuju Loket Customer Service satu." */
export function buildAnnouncement(call: Pick<DisplayCall, 'queue_number' | 'customer_name' | 'counter_name' | 'counter_code' | 'service_name'>): string {
  const destination = speakDigits(call.counter_name || call.counter_code || call.service_name || 'loket pelayanan')
  return `Nomor antrian ${spell(call.queue_number)}, ${call.customer_name || 'pelanggan'}, silakan menuju ${destination}.`
}

/** Panggilan yang terlalu lama tidak boleh diumumkan lagi (mis. TV baru menyala/reconnect). */
export function isExpired(calledAt: string, nowMs: number, maxAgeSeconds: number): boolean {
  const time = Date.parse(calledAt)
  return !Number.isFinite(time) || nowMs - time > maxAgeSeconds * 1000
}

export type DisplayArea = 'cs' | 'teller'
export function areaOf(call: Pick<DisplayCall, 'counter_type' | 'service_name' | 'counter_code'>): DisplayArea {
  const text = `${call.counter_type ?? ''} ${call.service_name ?? ''} ${call.counter_code ?? ''}`.toUpperCase()
  return /TELLER|\bTL\b|^TL|\bTL-/.test(text) ? 'teller' : 'cs'
}

export function callFromEvent(event: RealtimeEvent & { counter_type?: string | null }): DisplayCall | null {
  if (!event.queue_id || !event.queue_number || !event.called_at) return null
  return {
    event_id: event.event_id, queue_id: event.queue_id, queue_number: event.queue_number, customer_name: event.customer_name ?? '',
    queue_status: event.status ?? 'CALLED', service_name: event.service_name ?? '', counter_code: event.counter_code ?? '', counter_name: event.counter_name ?? '',
    counter_type: event.counter_type ?? null, call_type: event.event_name === 'queue.recalled' ? 'RECALL' : 'INITIAL', called_at: event.called_at,
  }
}

/** Satu kartu per loket: panggilan terbaru milik loket tersebut yang masih berstatus CALLED. */
export function activeByCounter(calls: DisplayCall[]): DisplayCall[] {
  const latest = new Map<string, DisplayCall>()
  for (const call of calls) if (!latest.has(call.counter_code)) latest.set(call.counter_code, call)
  return [...latest.values()].filter((call) => call.queue_status === 'CALLED').sort((a, b) => a.counter_code.localeCompare(b.counter_code, 'id', { numeric: true }))
}
