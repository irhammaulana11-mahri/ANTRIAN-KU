import { useQuery } from '@tanstack/react-query'
import { Activity, CheckCircle2, Clock3, Users2, XCircle } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { PageHeader } from '@/components/PageHeader'
import { StatCard } from '@/components/StatCard'
import { EmptyState, ErrorState, Loading } from '@/components/Feedback'
import { Badge } from '@/components/ui/Badge'
import { getData } from '@/lib/api'
import type { Paginated, Queue } from '@/types/api'
import { formatDate } from '@/lib/utils'
import { useAuth } from '@/context/AuthContext'

const STATUS_LABEL: Record<string, string> = {
  WAITING: 'Menunggu', ASSIGNED: 'Ter-assign', CALLED: 'Dipanggil',
  SERVING: 'Dilayani', COMPLETED: 'Selesai', CANCELLED: 'Batal', SKIPPED: 'Dilewati',
}
const STATUS_TONE: Record<string, 'green' | 'amber' | 'blue' | 'red' | 'slate'> = {
  WAITING: 'amber', ASSIGNED: 'blue', CALLED: 'blue', SERVING: 'blue',
  COMPLETED: 'green', CANCELLED: 'red', SKIPPED: 'slate',
}

export function DashboardPage() {
  const { user } = useAuth()
  const today = new Date().toLocaleDateString('en-CA')
  const role = user?.roles?.[0] ?? 'Pengguna'
  const query = useQuery({
    queryKey: ['dashboard-queues', user?.branch_id, today],
    queryFn: () => getData<Queue[]>('/queues/history', { per_page: 500, queue_date: today, branch_id: user?.branch_id ?? undefined }),
    refetchInterval: 15000,
  })

  if (query.isLoading) return <Loading />
  if (query.isError) return <ErrorState message="Dashboard membutuhkan akses queue history dari API." />

  const queues = (query.data?.data ?? []) as Queue[]
  const waiting = queues.filter((q) => ['WAITING', 'ASSIGNED', 'CALLED'].includes(q.status)).length
  const serving  = queues.filter((q) => q.status === 'SERVING').length
  const done     = queues.filter((q) => q.status === 'COMPLETED').length
  const dropped  = queues.filter((q) => ['CANCELLED', 'SKIPPED'].includes(q.status)).length

  const titleMap: Record<string, string> = { 'Super Admin': 'Dashboard Super Admin', 'Admin Cabang': 'Dashboard Admin Cabang' }
  const pageTitle = titleMap[role] ?? 'Dashboard Operasional'
  const branchLabel = user?.branch ? ` · ${user.branch.branch_name}` : user?.unit ? ` · ${user.unit.unit_name}` : ''

  return <>
    <PageHeader title={pageTitle} description={`Ringkasan antrian hari ini${branchLabel}.`} />

    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard label="Total hari ini" value={queues.length} icon={<Activity size={18} />} tone="slate" />
      <StatCard label="Dalam antrean"  value={waiting}       icon={<Clock3    size={18} />} tone="amber" />
      <StatCard label="Sedang dilayani" value={serving}      icon={<Users2    size={18} />} tone="blue"  />
      <StatCard label="Selesai"         value={done}         icon={<CheckCircle2 size={18} />} tone="teal" />
    </div>

    <div className="mt-6 grid gap-6 xl:grid-cols-[1fr_auto]">
      <Card>
        <CardHeader title="Aktivitas terbaru" description="Polling otomatis setiap 15 detik." />
        {queues.length === 0
          ? <EmptyState message="Belum ada aktivitas antrian hari ini." />
          : <div className="divide-y divide-slate-100">
              {queues.slice(0, 10).map((queue) => (
                <div key={queue.id} className="flex items-center justify-between gap-4 py-3.5">
                  <div className="flex min-w-0 items-center gap-3">
                    <span className="w-16 shrink-0 font-black text-brand tabular-nums">{queue.queue_number}</span>
                    <div className="min-w-0">
                      <p className="truncate font-semibold text-ink">{queue.customer_name}</p>
                      <p className="truncate text-xs text-slate-400">{(queue as any).service_name ?? 'Layanan'} · {formatDate(queue.created_at)}</p>
                    </div>
                  </div>
                  <Badge tone={STATUS_TONE[queue.status] ?? 'slate'} dot>{STATUS_LABEL[queue.status] ?? queue.status}</Badge>
                </div>
              ))}
            </div>
        }
      </Card>

      {dropped > 0 && <Card className="h-fit xl:w-52">
        <CardHeader title="Tidak selesai" />
        <div className="flex items-center gap-3">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 text-red-500"><XCircle size={22} /></div>
          <div>
            <p className="text-3xl font-black tabular-nums text-red-600">{dropped}</p>
            <p className="text-xs text-slate-400">Batal / Dilewati</p>
          </div>
        </div>
      </Card>}
    </div>
  </>
}
