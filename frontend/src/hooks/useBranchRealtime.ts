import { useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { getEcho } from '@/lib/echo'
import { getData } from '@/lib/api'
import { DisplayEventGuard } from '@/lib/displayEventGuard'
import type { Queue } from '@/types/api'
import type { RealtimeEvent } from '@/types/realtime'

const EVENTS = ['queue.created', 'queue.assigned', 'queue.called', 'queue.recalled', 'queue.started', 'queue.completed', 'queue.transferred', 'queue.status-changed', 'employee.status-changed', 'display.updated']
const QUERY_KEYS = ['queue-history', 'operator-workspace', 'dashboard-queues', 'display-queues', 'supervisor-board']

type Options = { display?: boolean; onEvent?: (event: RealtimeEvent) => void; onSnapshot?: (queues: Queue[]) => void }
type PusherConnection = { bind: (event: string, handler: () => void) => void; unbind: (event: string, handler: () => void) => void }

export function useBranchRealtime(branchId: string | null | undefined, options: Options = {}) {
  const client = useQueryClient()
  const guard = useRef(new DisplayEventGuard())
  const onEvent = useRef(options.onEvent)
  const onSnapshot = useRef(options.onSnapshot)
  onEvent.current = options.onEvent
  onSnapshot.current = options.onSnapshot

  useEffect(() => {
    if (!branchId || !localStorage.getItem('sqms_token')) return
    const echo = getEcho()
    const channel = echo.private(`branch.${branchId}`)
    const refresh = () => QUERY_KEYS.forEach((key) => client.invalidateQueries({ queryKey: [key] }))
    EVENTS.forEach((name) => channel.listen(`.${name}`, (event: RealtimeEvent) => {
      refresh()
      if (guard.current.accept(event)) onEvent.current?.({ ...event, event_name: name })
    }))
    // Setelah (re)connect, ambil snapshot terbaru agar event yang terlewat tidak hilang.
    const recover = async () => {
      try {
        const snapshot = await getData<Queue[]>('/queues/history', { branch_id: branchId, status: 'CALLED', per_page: 50 })
        const queues = snapshot.data ?? []
        guard.current.markSnapshot(queues.find((queue) => queue.called_at)?.called_at)
        onSnapshot.current?.(queues)
        refresh()
      } catch { /* dicoba lagi pada koneksi berikutnya */ }
    }
    void recover()
    const connection = (echo.connector as unknown as { pusher?: { connection?: PusherConnection } }).pusher?.connection
    const onConnected = () => { void recover() }
    connection?.bind('connected', onConnected)
    return () => { connection?.unbind('connected', onConnected); echo.leave(`branch.${branchId}`) }
  }, [branchId, client])
}
