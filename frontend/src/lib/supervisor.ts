import type { BoardEmployee, BoardQueue, SupervisorBoard } from '@/types/api'

/** Petugas yang berkompeten pada layanan antrian tersebut (apa pun statusnya). */
export function competentEmployees(board: Pick<SupervisorBoard, 'employees'>, queue: Pick<BoardQueue, 'service_type_id'>): BoardEmployee[] {
  return board.employees.filter((employee) => employee.service_type_ids.includes(queue.service_type_id))
}

/** Petugas yang boleh dipilih untuk assign manual: kompeten, tersedia (heartbeat segar), dan tidak sedang menangani antrian. */
export function eligibleEmployees(board: Pick<SupervisorBoard, 'employees'>, queue: Pick<BoardQueue, 'service_type_id'>): BoardEmployee[] {
  return competentEmployees(board, queue).filter((employee) => employee.is_available && !employee.active_queue_number)
}

/** Loket yang bebas dipakai (tidak sedang melayani antrian lain). */
export function freeCounters<T extends { in_use_by: string | null }>(counters: T[]): T[] { return counters.filter((counter) => !counter.in_use_by) }

/** Loket awal yang disarankan: loket milik petugas bila bebas. */
export function suggestedCounterId(employee: Pick<BoardEmployee, 'counter_ids'> | undefined, counters: { id: string; in_use_by: string | null }[]): string {
  return freeCounters(counters).find((counter) => employee?.counter_ids.includes(counter.id))?.id ?? ''
}

export function waited(iso: string, now: number): string {
  const minutes = Math.max(0, Math.floor((now - Date.parse(iso)) / 60000))
  return minutes < 1 ? 'baru' : minutes < 60 ? `${minutes} mnt` : `${Math.floor(minutes / 60)} j ${minutes % 60} mnt`
}
