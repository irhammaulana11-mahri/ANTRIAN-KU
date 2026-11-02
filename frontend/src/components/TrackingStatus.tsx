import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ErrorState, Loading } from '@/components/Feedback'
import { formatWait } from '@/lib/ticket'
import type { QueueStatus, QueueTracking } from '@/types/api'

const LABEL: Record<QueueStatus, string> = { WAITING: 'Menunggu', ASSIGNED: 'Segera dipanggil', CALLED: 'Dipanggil', SERVING: 'Sedang dilayani', COMPLETED: 'Selesai', SKIPPED: 'Dilewati', CANCELLED: 'Dibatalkan', NO_SHOW: 'Tidak hadir' }
const TONE: Record<QueueStatus, 'amber' | 'blue' | 'green' | 'red' | 'slate'> = { WAITING: 'amber', ASSIGNED: 'blue', CALLED: 'blue', SERVING: 'blue', COMPLETED: 'green', SKIPPED: 'red', CANCELLED: 'slate', NO_SHOW: 'red' }
const FINAL: QueueStatus[] = ['COMPLETED', 'SKIPPED', 'CANCELLED', 'NO_SHOW']

function message(queue: QueueTracking): { text: string; highlight: boolean } {
  const place = queue.counter ? (queue.counter.counter_name || queue.counter.counter_code) : 'loket pelayanan'
  switch (queue.status) {
    case 'WAITING': return { text: queue.position ? `Anda di urutan ke-${queue.position}${queue.ahead ? ` (${queue.ahead} antrian di depan)` : ''}. Estimasi tunggu: ${formatWait(queue.estimated_wait_seconds)}.` : 'Antrian Anda menunggu giliran.', highlight: false }
    case 'ASSIGNED': return { text: `Giliran Anda hampir tiba. Bersiap menuju ${place}.`, highlight: false }
    case 'CALLED': return { text: `Nomor Anda dipanggil! Silakan menuju ${place}.`, highlight: true }
    case 'SERVING': return { text: `Anda sedang dilayani di ${place}.`, highlight: false }
    case 'COMPLETED': return { text: 'Layanan selesai. Terima kasih.', highlight: false }
    case 'SKIPPED': return { text: 'Antrian Anda dilewati karena tidak hadir saat dipanggil. Silakan ambil nomor baru atau hubungi petugas.', highlight: false }
    case 'NO_SHOW': return { text: 'Anda ditandai tidak hadir. Silakan ambil nomor baru atau hubungi petugas.', highlight: false }
    default: return { text: 'Antrian ini telah dibatalkan.', highlight: false }
  }
}

/** Status antrian pelanggan (polling 8 detik) berdasarkan token; dipakai di halaman tiket dan halaman lacak. */
export function TrackingStatus({ token, compact = false }: { token: string; compact?: boolean }) {
  const client = useQueryClient()
  const key = ['tracking', token]
  const query = useQuery({
    queryKey: key, enabled: token.length >= 32, retry: false,
    queryFn: async () => (await api.get<{ data: QueueTracking }>(`/queues/track/${token}`)).data.data,
    refetchInterval: (state) => (state.state.data && FINAL.includes(state.state.data.status) ? false : 8000),
  })
  const cancel = useMutation({
    mutationFn: async () => (await api.post<{ data: QueueTracking }>('/queues/cancel-by-token', { token })).data.data,
    onSuccess: (data) => client.setQueryData(key, data),
  })

  if (query.isLoading) return <Loading label="Memuat status antrian…" />
  if (query.isError || !query.data) return <ErrorState message="Token tidak ditemukan atau tidak valid. Periksa kembali tiket Anda." />
  const queue = query.data
  const info = message(queue)
  return <div className="text-center">
    {!compact && <><p className="text-sm text-slate-500">{queue.branch_name}</p><p className="text-sm text-slate-500">{queue.service_name}</p></>}
    {!compact && <p className="my-3 text-6xl font-black text-brand sm:text-7xl">{queue.queue_number}</p>}
    <Badge tone={TONE[queue.status]}>{LABEL[queue.status]}</Badge>
    <p className={`mx-auto mt-4 max-w-md rounded-2xl p-4 text-sm sm:text-base ${info.highlight ? 'bg-amber-100 font-bold text-amber-900' : 'bg-slate-50 text-slate-700'}`}>{info.text}</p>
    {!compact && <p className="mt-3 text-xs text-slate-400">Atas nama {queue.customer_name} · diperbarui otomatis</p>}
    {queue.can_cancel && <div className="mt-5">
      <Button variant="ghost" className="text-red-600" disabled={cancel.isPending} onClick={() => window.confirm(`Batalkan antrian ${queue.queue_number}? Tindakan ini tidak dapat dibatalkan.`) && cancel.mutate()}>{cancel.isPending ? 'Membatalkan…' : 'Batalkan antrian'}</Button>
      {cancel.isError && <p className="mt-2 text-sm text-red-600">{errorMessage(cancel.error)}</p>}
    </div>}
  </div>
}
