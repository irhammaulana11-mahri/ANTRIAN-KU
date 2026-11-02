import type { RealtimeEvent } from '@/types/realtime'

export class DisplayEventGuard {
  private readonly seen = new Set<string>()
  private lastOccurredAt = 0

  accept(event: RealtimeEvent): boolean {
    if (!event.event_id || this.seen.has(event.event_id)) return false
    const timestamp = Date.parse(event.occurred_at)
    if (Number.isFinite(timestamp) && timestamp < this.lastOccurredAt) return false
    this.seen.add(event.event_id)
    if (Number.isFinite(timestamp)) this.lastOccurredAt = Math.max(this.lastOccurredAt, timestamp)
    if (this.seen.size > 500) this.seen.delete(this.seen.values().next().value as string)
    return true
  }

  markSnapshot(occurredAt?: string | null) {
    if (!occurredAt) return
    const timestamp = Date.parse(occurredAt)
    if (Number.isFinite(timestamp)) this.lastOccurredAt = Math.max(this.lastOccurredAt, timestamp)
  }
}
