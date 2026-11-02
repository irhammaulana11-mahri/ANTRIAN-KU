import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Coffee, LogOut, Megaphone, Play, Power, RotateCcw, SkipForward, CheckCircle2, UserX } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { EmptyState, ErrorState, Loading } from '@/components/Feedback'
import type { EmployeeStatus, Workspace } from '@/types/api'

const WORKSPACE_KEY = ['operator-workspace']
const HEARTBEAT_MS = 20_000
const STATUS_LABEL: Record<EmployeeStatus, string> = { AVAILABLE: 'Tersedia', BUSY: 'Melayani', BREAK: 'Istirahat', OFFLINE: 'Offline', CLOSED: 'Selesai bertugas' }
const STATUS_TONE: Record<EmployeeStatus, 'green' | 'blue' | 'amber' | 'slate' | 'red'> = { AVAILABLE: 'green', BUSY: 'blue', BREAK: 'amber', OFFLINE: 'slate', CLOSED: 'slate' }
const QUEUE_LABEL = { ASSIGNED: 'Menunggu dipanggil', CALLED: 'Sudah dipanggil', SERVING: 'Sedang dilayani' } as const

function duration(from?: string | null, now = Date.now()) {
  if (!from) return '—'
  const seconds = Math.max(0, Math.floor((now - Date.parse(from)) / 1000))
  return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`
}

export function OperatorPage() {
  const client = useQueryClient()
  const [now, setNow] = useState(Date.now())
  const [notice, setNotice] = useState<{ tone: 'error' | 'success'; text: string } | null>(null)
  const workspace = useQuery({ queryKey: WORKSPACE_KEY, queryFn: async () => (await api.get<{ data: Workspace }>('/employee/workspace')).data.data, refetchInterval: 8000 })

  const apply = { onSuccess: (data: Workspace) => { client.setQueryData(WORKSPACE_KEY, data); setNotice(null) }, onError: (error: unknown) => { setNotice({ tone: 'error', text: errorMessage(error) }); void client.invalidateQueries({ queryKey: WORKSPACE_KEY }) } }
  const setStatus = useMutation({ mutationFn: async (status: EmployeeStatus) => (await api.post<{ data: Workspace }>('/employee/status', { status })).data.data, ...apply })
  const act = useMutation({ mutationFn: async (input: { id: string; action: string }) => (await api.post<{ data: Workspace }>(`/service/${input.id}/${input.action}`)).data.data, ...apply })

  const status = workspace.data?.status
  useEffect(() => { const timer = window.setInterval(() => setNow(Date.now()), 1000); return () => window.clearInterval(timer) }, [])
  // Heartbeat menjaga status petugas tetap valid; berhenti bila petugas offline/selesai bertugas.
  useEffect(() => {
    if (!status || status === 'OFFLINE' || status === 'CLOSED') return
    const timer = window.setInterval(() => { api.post<{ data: Workspace }>('/employee/heartbeat').then((res) => client.setQueryData(WORKSPACE_KEY, res.data.data)).catch(() => undefined) }, HEARTBEAT_MS)
    return () => window.clearInterval(timer)
  }, [status, client])

  if (workspace.isLoading) return <Loading />
  if (workspace.isError || !workspace.data) return <><PageHeader title="Dashboard Petugas" /><ErrorState message={errorMessage(workspace.error, 'Ruang kerja petugas tidak dapat dimuat.')} /></>

  const data = workspace.data
  const active = data.active
  const busy = setStatus.isPending || act.isPending
  const confirmAct = (id: string, action: string, message: string) => { if (window.confirm(message)) act.mutate({ id, action }) }
  const counterLabel = data.employee.counters.map((counter) => counter.counter_name || counter.counter_code).join(', ') || 'Belum ada loket'

  return <>
    <PageHeader title="Dashboard Petugas" description={`${data.employee.employee_name} · ${counterLabel}`} action={
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={STATUS_TONE[data.status]}>{STATUS_LABEL[data.status]}</Badge>
        {(data.status === 'OFFLINE' || data.status === 'CLOSED') && <Button disabled={busy || !data.ready} onClick={() => setStatus.mutate('AVAILABLE')}><Power size={16} className="mr-2" />Mulai bertugas</Button>}
        {data.status === 'BREAK' && <Button disabled={busy} onClick={() => setStatus.mutate('AVAILABLE')}><RotateCcw size={16} className="mr-2" />Kembali bertugas</Button>}
        {data.status === 'AVAILABLE' && <Button variant="secondary" disabled={busy} onClick={() => setStatus.mutate('BREAK')}><Coffee size={16} className="mr-2" />Istirahat</Button>}
        {(data.status === 'AVAILABLE' || data.status === 'BREAK') && <Button variant="ghost" disabled={busy} onClick={() => window.confirm('Akhiri tugas dan keluar dari distribusi antrian?') && setStatus.mutate('CLOSED')}><LogOut size={16} className="mr-2" />Akhiri tugas</Button>}
      </div>} />

    {!data.ready && <div className="mb-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Akun Anda belum memiliki {data.employee.service_types.length === 0 && 'layanan'}{data.employee.service_types.length === 0 && data.employee.counters.length === 0 && ' dan '}{data.employee.counters.length === 0 && 'loket'}. Minta Admin Cabang mengaturnya di menu Petugas sebelum mulai bertugas.</div>}
    {notice && <div className={`mb-5 rounded-xl p-4 text-sm ${notice.tone === 'error' ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'}`}>{notice.text}</div>}

    <div className="grid gap-5 lg:grid-cols-[1.4fr_.6fr]">
      <Card>
        <CardHeader title="Antrian aktif" description={active ? QUEUE_LABEL[active.status] : data.status === 'AVAILABLE' ? 'Menunggu antrian berikutnya dialokasikan…' : 'Tidak ada antrian aktif.'} />
        {!active ? <EmptyState message={data.status === 'AVAILABLE' ? `Anda siap menerima antrian. ${data.waiting_count} antrian menunggu.` : data.status === 'BREAK' ? 'Anda sedang istirahat. Tekan "Kembali bertugas" untuk menerima antrian.' : 'Tekan "Mulai bertugas" untuk menerima antrian.'} /> : <div>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p className="text-6xl font-black tracking-tight text-brand sm:text-7xl">{active.queue_number}</p>
              <p className="mt-3 text-xl font-bold">{active.customer_name}</p>
              <p className="text-sm text-slate-500">{active.service_name}{active.priority_name && active.priority_name !== 'Normal' ? ` · ${active.priority_name}` : ''}</p>
              {active.service_notes && <p className="mt-2 max-w-md rounded-xl bg-slate-50 p-3 text-sm text-slate-600"><b>Keperluan:</b> {active.service_notes}</p>}
            </div>
            <dl className="grid grid-cols-3 gap-4 text-center sm:text-left">
              <div><dt className="text-xs text-slate-500">Loket</dt><dd className="mt-1 text-lg font-bold">{active.counter.counter_code ?? '—'}</dd></div>
              <div><dt className="text-xs text-slate-500">Waktu tunggu</dt><dd className="mt-1 text-lg font-bold tabular-nums">{duration(active.registered_at, active.called_at ? Date.parse(active.called_at) : now)}</dd></div>
              <div><dt className="text-xs text-slate-500">Durasi layanan</dt><dd className="mt-1 text-lg font-bold tabular-nums">{duration(active.service_started_at, now)}</dd></div>
            </dl>
          </div>
          <div className="mt-6 flex flex-wrap gap-3">
            {active.status === 'ASSIGNED' && <Button className="px-6 py-3 text-base" disabled={busy} onClick={() => act.mutate({ id: active.queue_id, action: 'call' })}><Megaphone size={18} className="mr-2" />Panggil</Button>}
            {active.status === 'CALLED' && <>
              <Button className="px-6 py-3 text-base" disabled={busy} onClick={() => act.mutate({ id: active.queue_id, action: 'start' })}><Play size={18} className="mr-2" />Mulai pelayanan</Button>
              <Button variant="secondary" className="py-3" disabled={busy} onClick={() => act.mutate({ id: active.queue_id, action: 'recall' })}><Megaphone size={18} className="mr-2" />Panggil ulang</Button>
            </>}
            {active.status === 'SERVING' && <Button className="px-6 py-3 text-base" disabled={busy} onClick={() => act.mutate({ id: active.queue_id, action: 'complete' })}><CheckCircle2 size={18} className="mr-2" />Selesaikan pelayanan</Button>}
            {(active.status === 'ASSIGNED' || active.status === 'CALLED') && <Button variant="ghost" className="py-3" disabled={busy} onClick={() => confirmAct(active.queue_id, 'skip', `Lewati antrian ${active.queue_number}?`)}><SkipForward size={18} className="mr-2" />Lewati</Button>}
            {active.status === 'CALLED' && <Button variant="ghost" className="py-3 text-red-600" disabled={busy} onClick={() => confirmAct(active.queue_id, 'no-show', `Tandai ${active.queue_number} tidak hadir?`)}><UserX size={18} className="mr-2" />Tidak hadir</Button>}
          </div>
        </div>}
      </Card>

      <Card>
        <CardHeader title="Antrian berikutnya" description={`${data.waiting_count} menunggu untuk layanan Anda`} />
        {data.next.length === 0 ? <EmptyState message="Tidak ada antrian menunggu." /> : <ul className="divide-y divide-slate-100">{data.next.map((queue) => <li key={queue.queue_id} className="flex items-center justify-between gap-3 py-3"><div><p className="font-black text-brand">{queue.queue_number}</p><p className="text-sm text-slate-600">{queue.customer_name}</p></div><span className="text-xs text-slate-400">{queue.service_name}</span></li>)}</ul>}
        <p className="mt-4 text-xs text-slate-400">Layanan Anda: {data.employee.service_types.map((service) => service.service_name).join(', ') || '—'}</p>
      </Card>
    </div>
  </>
}
