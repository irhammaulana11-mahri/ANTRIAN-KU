import { describe, expect, it } from 'vitest'
import { DisplayEventGuard } from '@/lib/displayEventGuard'

const event = (id: string, occurredAt: string) => ({ event_id: id, occurred_at: occurredAt, branch_id: 'branch-1', status: 'CALLED', queue_number: 'A-01' })

describe('DisplayEventGuard', () => {
  it('menolak event yang sama dua kali', () => {
    const guard = new DisplayEventGuard()
    expect(guard.accept(event('event-1', '2026-10-30T10:00:00Z'))).toBe(true)
    expect(guard.accept(event('event-1', '2026-10-30T10:00:00Z'))).toBe(false)
  })

  it('menolak event lama setelah snapshot recovery', () => {
    const guard = new DisplayEventGuard()
    guard.markSnapshot('2026-10-30T10:05:00Z')
    expect(guard.accept(event('old-event', '2026-10-30T10:04:59Z'))).toBe(false)
    expect(guard.accept(event('new-event', '2026-10-30T10:05:01Z'))).toBe(true)
  })
})
