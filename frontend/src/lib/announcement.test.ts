import { describe, expect, it } from 'vitest'
import { activeByCounter, areaOf, buildAnnouncement, callFromEvent, isExpired, spell, type DisplayCall } from '@/lib/announcement'

const call = (over: Partial<DisplayCall> = {}): DisplayCall => ({ event_id: 'e1', queue_id: 'q1', queue_number: 'CS-012', customer_name: 'Budi Santoso', queue_status: 'CALLED', service_name: 'Customer Service', counter_code: 'CS-01', counter_name: 'Loket Customer Service 1', counter_type: 'CS', call_type: 'INITIAL', called_at: '2026-10-31T10:00:00Z', ...over })

describe('pengumuman suara', () => {
  it('mengeja nomor antrian per angka', () => {
    expect(spell('CS-012')).toBe('C S nol satu dua')
    expect(spell('TL-100')).toBe('T L satu nol nol')
  })

  it('menyusun kalimat dengan nama customer dan loket', () => {
    expect(buildAnnouncement(call())).toBe('Nomor antrian C S nol satu dua, Budi Santoso, silakan menuju Loket Customer Service satu.')
  })

  it('memakai fallback bila data loket/nama kosong', () => {
    expect(buildAnnouncement(call({ customer_name: '', counter_name: '', counter_code: '', service_name: '' }))).toBe('Nomor antrian C S nol satu dua, pelanggan, silakan menuju loket pelayanan.')
  })

  it('menandai panggilan kedaluwarsa', () => {
    const now = Date.parse('2026-10-31T10:01:30Z')
    expect(isExpired('2026-10-31T10:00:00Z', now, 60)).toBe(true)
    expect(isExpired('2026-10-31T10:01:00Z', now, 60)).toBe(false)
    expect(isExpired('bukan-tanggal', now, 60)).toBe(true)
  })

  it('memisahkan area CS dan Teller', () => {
    expect(areaOf(call())).toBe('cs')
    expect(areaOf(call({ counter_type: 'TL', counter_code: 'TL-01', service_name: 'Layanan Kas' }))).toBe('teller')
    expect(areaOf(call({ counter_type: 'Teller', service_name: 'Teller' }))).toBe('teller')
  })

  it('hanya menampilkan panggilan terbaru per loket yang masih CALLED', () => {
    const calls = [call({ event_id: 'a', counter_code: 'CS-02', queue_number: 'CS-003' }), call({ event_id: 'b', counter_code: 'CS-01', queue_status: 'COMPLETED' }), call({ event_id: 'c', counter_code: 'CS-01', queue_number: 'CS-001' })]
    expect(activeByCounter(calls).map((item) => item.event_id)).toEqual(['a'])
  })

  it('mengubah event realtime menjadi panggilan dan membedakan recall', () => {
    const event = { event_id: 'x', occurred_at: '2026-10-31T10:00:00Z', branch_id: 'b', queue_id: 'q', queue_number: 'CS-001', customer_name: 'Sari', status: 'CALLED', called_at: '2026-10-31T10:00:00Z', counter_code: 'CS-01', counter_name: 'Loket 1' }
    expect(callFromEvent({ ...event, event_name: 'queue.recalled' })?.call_type).toBe('RECALL')
    expect(callFromEvent({ ...event, event_name: 'queue.called' })?.call_type).toBe('INITIAL')
    expect(callFromEvent({ ...event, called_at: null })).toBeNull()
  })
})
