import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { RefreshCw } from 'lucide-react'
import { getData } from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Select } from '@/components/ui/Field'
import { EmptyState, ErrorState, Loading } from '@/components/Feedback'
import type { Queue } from '@/types/api'

const STATUS_LABEL: Record<string, string> = {
  WAITING: 'Menunggu', ASSIGNED: 'Ter-assign', CALLED: 'Dipanggil',
  SERVING: 'Dilayani', COMPLETED: 'Selesai', CANCELLED: 'Dibatalkan',
  NO_SHOW: 'Tidak hadir', SKIPPED: 'Dilewati',
}
const STATUS_TONE: Record<string, 'green' | 'red' | 'blue' | 'amber' | 'slate'> = {
  COMPLETED: 'green', CANCELLED: 'red', NO_SHOW: 'red', SKIPPED: 'slate',
  SERVING: 'blue', CALLED: 'blue', ASSIGNED: 'blue', WAITING: 'amber',
}
const CHANNEL_LABEL: Record<string, string> = { WALK_IN: 'Walk-in', ONLINE: 'Online' }

const todayLabel = () => new Date().toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
const timeLabel = (iso: string) => new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })

export function QueueMonitoringPage() {
  const { user } = useAuth()
  const [status, setStatus] = useState('')

  const scopeLabel = user?.unit ? user.unit.unit_name : user?.branch ? user.branch.branch_name : 'Semua cabang'

  const query = useQuery({
    queryKey: ['queue-history', status],
    queryFn: () => getData<Queue[]>('/queues/history', { per_page: 200, status: status || undefined }),
    refetchInterval: 10_000,
  })

  const queues = query.data?.data ?? []
  const counts = (s: string) => queues.filter((q) => q.status === s).length
  const active = queues.filter((q) => ['WAITING', 'ASSIGNED', 'CALLED', 'SERVING'].includes(q.status)).length

  return <>
    <PageHeader
      title="Monitoring Antrian"
      description={`${scopeLabel} · ${todayLabel()}`}
      action={
        <div className="flex items-center gap-2">
          <Select className="w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">Semua status</option>
            {Object.entries(STATUS_LABEL).map(([val, lbl]) => <option key={val} value={val}>{lbl}</option>)}
          </Select>
          <button type="button" onClick={() => void query.refetch()} disabled={query.isFetching}
            className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-50">
            <RefreshCw size={14} className={query.isFetching ? 'animate-spin' : ''} />Muat ulang
          </button>
        </div>
      }
    />

    {!query.isLoading && !query.isError && !status && (
      <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          { label: 'Total hari ini', value: queues.length,                                               bar: 'bg-slate-400',   bg: 'bg-slate-50',   val: 'text-slate-700' },
          { label: 'Masih aktif',    value: active,                                                      bar: 'bg-amber-500',   bg: 'bg-amber-50',   val: 'text-amber-700' },
          { label: 'Selesai',        value: counts('COMPLETED'),                                         bar: 'bg-emerald-500', bg: 'bg-emerald-50', val: 'text-emerald-700' },
          { label: 'Batal/Dilewati', value: counts('CANCELLED') + counts('NO_SHOW') + counts('SKIPPED'), bar: 'bg-red-500',     bg: 'bg-red-50',     val: 'text-red-600' },
        ].map((tile) => (
          <div key={tile.label} className={`relative overflow-hidden rounded-2xl p-4 ${tile.bg}`}>
            <div className={`absolute left-0 top-3 bottom-3 w-1 rounded-r-full ${tile.bar}`} />
            <p className={`pl-3 text-3xl font-black tabular-nums ${tile.val}`}>{tile.value}</p>
            <p className="mt-1 pl-3 text-xs font-medium text-slate-400">{tile.label}</p>
          </div>
        ))}
      </div>
    )}

    <Card>
      <CardHeader
        title="Daftar antrian"
        description={`Diperbarui otomatis setiap 10 detik${query.dataUpdatedAt ? ` · terakhir ${timeLabel(new Date(query.dataUpdatedAt).toISOString())}` : ''}`}
      />
      {query.isLoading ? <Loading /> : query.isError ? <ErrorState message="Data antrian tidak dapat dimuat." /> : queues.length === 0
        ? <EmptyState message="Belum ada antrian hari ini." />
        : <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-left text-sm">
              <thead className="border-b border-slate-100">
                <tr className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-3">Nomor</th>
                  <th className="px-3 py-3">Nama pelanggan</th>
                  <th className="px-3 py-3">Layanan</th>
                  <th className="px-3 py-3">Status</th>
                  <th className="px-3 py-3 text-right">Waktu</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {queues.map((queue) => (
                  <tr key={queue.id} className="hover:bg-slate-50/60 transition-colors">
                    <td className="px-3 py-3.5 font-black text-brand">{queue.queue_number}</td>
                    <td className="px-3 py-3.5 font-semibold text-ink">{queue.customer_name}</td>
                    <td className="px-3 py-3.5 text-slate-400">{queue.service_name ?? '—'}</td>
                    <td className="px-3 py-3.5"><Badge tone={STATUS_TONE[queue.status] ?? 'slate'} dot>{STATUS_LABEL[queue.status] ?? queue.status}</Badge></td>
                    <td className="px-3 py-3.5 text-right text-slate-400 tabular-nums">{timeLabel(queue.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
      }
    </Card>
  </>
}
