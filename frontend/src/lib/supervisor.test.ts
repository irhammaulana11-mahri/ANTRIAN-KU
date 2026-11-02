import { describe, expect, it } from 'vitest'
import { competentEmployees, eligibleEmployees, suggestedCounterId, waited } from '@/lib/supervisor'
import type { BoardEmployee } from '@/types/api'

const employee = (over: Partial<BoardEmployee>): BoardEmployee => ({ id: 'e1', employee_code: 'E1', employee_name: 'Budi', status: 'AVAILABLE', is_available: true, service_type_ids: ['cs'], counter_ids: ['c1'], active_queue_number: null, ...over })
const board = { employees: [
  employee({ id: 'ok' }),
  employee({ id: 'busy', status: 'BUSY', is_available: false, active_queue_number: 'CS-001' }),
  employee({ id: 'break', status: 'BREAK', is_available: false }),
  employee({ id: 'stale', is_available: false }), // status AVAILABLE tetapi heartbeat basi
  employee({ id: 'teller', service_type_ids: ['tl'] }),
  employee({ id: 'serving-but-flagged', is_available: true, active_queue_number: 'CS-009' }),
] }

describe('pemilihan petugas untuk assign manual', () => {
  it('hanya petugas kompeten, tersedia, dan tanpa antrian aktif yang boleh dipilih', () => {
    expect(eligibleEmployees(board, { service_type_id: 'cs' }).map((item) => item.id)).toEqual(['ok'])
    expect(eligibleEmployees(board, { service_type_id: 'tl' }).map((item) => item.id)).toEqual(['teller'])
  })
  it('petugas kompeten yang tidak boleh dipilih tetap dikenali agar alasannya ditampilkan', () => {
    const competent = competentEmployees(board, { service_type_id: 'cs' }).map((item) => item.id)
    expect(competent).toEqual(['ok', 'busy', 'break', 'stale', 'serving-but-flagged'])
  })
  it('menyarankan loket milik petugas hanya bila loket itu bebas', () => {
    const counters = [{ id: 'c1', in_use_by: null }, { id: 'c2', in_use_by: null }]
    expect(suggestedCounterId(employee({}), counters)).toBe('c1')
    expect(suggestedCounterId(employee({}), [{ id: 'c1', in_use_by: 'Siti' }, { id: 'c2', in_use_by: null }])).toBe('')
    expect(suggestedCounterId(undefined, counters)).toBe('')
  })
  it('menyajikan lama menunggu dalam bahasa Indonesia', () => {
    const now = Date.parse('2026-11-01T10:00:00Z')
    expect(waited('2026-11-01T09:59:40Z', now)).toBe('baru')
    expect(waited('2026-11-01T09:45:00Z', now)).toBe('15 mnt')
    expect(waited('2026-11-01T08:30:00Z', now)).toBe('1 j 30 mnt')
  })
})
