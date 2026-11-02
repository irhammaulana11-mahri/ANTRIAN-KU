import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowRightLeft, BadgeCheck, UserPlus } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field, Select } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { EmptyState, ErrorState, Loading } from '@/components/Feedback'
import { competentEmployees, eligibleEmployees, freeCounters, suggestedCounterId, waited } from '@/lib/supervisor'
import type { Branch, BoardEmployee, BoardQueue, EmployeeStatus, QueueStatus, SupervisorBoard } from '@/types/api'

type Dialog = { kind: 'assign' | 'transfer' | 'priority'; queue: BoardQueue } | null

const STATUS_ORDER: QueueStatus[] = ['WAITING', 'ASSIGNED', 'CALLED', 'SERVING']
const QUEUE_LABEL: Partial<Record<QueueStatus, string>> = { WAITING: 'Menunggu', ASSIGNED: 'Ter-assign', CALLED: 'Dipanggil', SERVING: 'Dilayani' }
const QUEUE_TONE: Partial<Record<QueueStatus, 'amber' | 'blue' | 'green'>> = { WAITING: 'amber', ASSIGNED: 'blue', CALLED: 'blue', SERVING: 'green' }
const EMPLOYEE_LABEL: Record<EmployeeStatus, string> = { AVAILABLE: 'Tersedia', BUSY: 'Melayani', BREAK: 'Istirahat', OFFLINE: 'Offline', CLOSED: 'Selesai tugas' }
const EMPLOYEE_TONE: Record<EmployeeStatus, 'green' | 'blue' | 'amber' | 'slate'> = { AVAILABLE: 'green', BUSY: 'blue', BREAK: 'amber', OFFLINE: 'slate', CLOSED: 'slate' }
const EMPLOYEE_ORDER: EmployeeStatus[] = ['AVAILABLE', 'BUSY', 'BREAK', 'OFFLINE', 'CLOSED']

const reason = z.string().trim().min(5, 'Alasan minimal 5 karakter').max(500, 'Maksimal 500 karakter')

function AssignDialog({ board, queue, onClose, onDone }: { board: SupervisorBoard; queue: BoardQueue; onClose: () => void; onDone: (message: string) => void }) {
  const competent = competentEmployees(board, queue)
  const eligible = eligibleEmployees(board, queue)
  const unavailable = competent.filter((employee) => !eligible.includes(employee))
  const counters = freeCounters(board.counters)
  const { register, handleSubmit, setValue, watch, formState: { errors } } = useForm({ resolver: zodResolver(z.object({ employee_id: z.string().uuid('Pilih petugas'), counter_id: z.string().uuid('Pilih loket'), reason })), defaultValues: { employee_id: '', counter_id: '', reason: '' } })
  const chosen = eligible.find((employee) => employee.id === watch('employee_id'))
  const client = useQueryClient()
  const save = useMutation({
    mutationFn: async (values: { employee_id: string; counter_id: string; reason: string }) => api.post(`/queues/${queue.id}/assign`, values),
    onSuccess: () => { void client.invalidateQueries({ queryKey: ['supervisor-board'] }); onDone(`${queue.queue_number} di-assign ke petugas yang dipilih.`) },
  })
  const pick = (employeeId: string) => {
    setValue('employee_id', employeeId, { shouldValidate: true })
    setValue('counter_id', suggestedCounterId(eligible.find((item) => item.id === employeeId), board.counters)) // loket milik petugas bila bebas
  }
  return <Modal title={`Assign manual — ${queue.queue_number}`} onClose={onClose}>
    <form className="space-y-4" noValidate onSubmit={handleSubmit((values) => save.mutate(values))}>
      <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600"><b>{queue.customer_name}</b> · {queue.service_name}{queue.priority && queue.priority.code !== 'NORMAL' ? ` · ${queue.priority.name}` : ''}</p>
      {eligible.length === 0 ? <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Tidak ada petugas yang tersedia untuk layanan ini saat ini.</p> : <>
        <Field label="Petugas" error={errors.employee_id?.message}>
          <Select {...register('employee_id', { onChange: (event) => pick(event.target.value) })}><option value="">Pilih petugas tersedia…</option>{eligible.map((employee) => <option key={employee.id} value={employee.id}>{employee.employee_name} ({employee.employee_code})</option>)}</Select>
        </Field>
        <Field label="Loket" error={errors.counter_id?.message}>
          <Select {...register('counter_id')}><option value="">Pilih loket bebas…</option>{counters.map((counter) => <option key={counter.id} value={counter.id}>{counter.counter_code} — {counter.counter_name}{chosen?.counter_ids.includes(counter.id) ? ' (loket petugas)' : ''}</option>)}</Select>
        </Field>
        <Field label="Alasan (tercatat di audit)" error={errors.reason?.message}>
          <textarea {...register('reason')} rows={3} placeholder="Contoh: pelanggan lansia perlu segera dilayani" className="w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm ring-1 ring-slate-200 focus:bg-white focus:ring-2 focus:ring-brand" />
        </Field>
      </>}
      {unavailable.length > 0 && <p className="text-xs text-slate-500">Tidak dapat dipilih: {unavailable.map((employee) => `${employee.employee_name} (${employee.active_queue_number ? `melayani ${employee.active_queue_number}` : EMPLOYEE_LABEL[employee.status].toLowerCase()})`).join(', ')}.</p>}
      {save.isError && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{errorMessage(save.error)}</p>}
      <div className="flex justify-end gap-3"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button disabled={save.isPending || eligible.length === 0}>{save.isPending ? 'Menyimpan…' : 'Assign'}</Button></div>
    </form>
  </Modal>
}

function TransferDialog({ board, queue, onClose, onDone }: { board: SupervisorBoard; queue: BoardQueue; onClose: () => void; onDone: (message: string) => void }) {
  const targets = board.services.filter((service) => service.id !== queue.service_type_id)
  const { register, handleSubmit, formState: { errors } } = useForm({ resolver: zodResolver(z.object({ target_service_type_id: z.string().uuid('Pilih layanan tujuan'), reason })), defaultValues: { target_service_type_id: '', reason: '' } })
  const client = useQueryClient()
  const save = useMutation({
    mutationFn: async (values: { target_service_type_id: string; reason: string }) => (await api.post<{ data: { queue_number: string } }>(`/queues/${queue.id}/transfer`, values)).data.data,
    onSuccess: (result) => { void client.invalidateQueries({ queryKey: ['supervisor-board'] }); onDone(`${queue.queue_number} dipindahkan dan kini bernomor ${result.queue_number}.`) },
  })
  const handled = queue.employee !== null
  return <Modal title={`Pindahkan antrian — ${queue.queue_number}`} onClose={onClose}>
    <form className="space-y-4" noValidate onSubmit={handleSubmit((values) => save.mutate(values))}>
      <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600"><b>{queue.customer_name}</b> · {queue.service_name} · {QUEUE_LABEL[queue.status]}</p>
      <Field label="Layanan tujuan" error={errors.target_service_type_id?.message}><Select {...register('target_service_type_id')}><option value="">Pilih layanan…</option>{targets.map((service) => <option key={service.id} value={service.id}>{service.service_name} ({service.queue_prefix})</option>)}</Select></Field>
      <Field label="Alasan (tercatat di audit)" error={errors.reason?.message}><textarea {...register('reason')} rows={3} placeholder="Contoh: kebutuhan pelanggan ternyata transaksi di Teller" className="w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm ring-1 ring-slate-200 focus:bg-white focus:ring-2 focus:ring-brand" /></Field>
      <div className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Nomor antrian akan <b>berganti</b> mengikuti layanan tujuan dan antrian kembali menunggu di urutan layanan tersebut.{handled && <> <b>{queue.employee?.name}</b> akan dibebaskan{queue.status === 'SERVING' ? ' dan sesi layanannya ditutup' : ''}.</>}</div>
      {save.isError && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{errorMessage(save.error)}</p>}
      <div className="flex justify-end gap-3"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button disabled={save.isPending || targets.length === 0}>{save.isPending ? 'Memindahkan…' : 'Pindahkan'}</Button></div>
    </form>
  </Modal>
}

function PriorityDialog({ board, queue, onClose, onDone }: { board: SupervisorBoard; queue: BoardQueue; onClose: () => void; onDone: (message: string) => void }) {
  const options = board.priorities.filter((priority) => priority.id !== queue.priority?.id)
  const { register, handleSubmit, formState: { errors } } = useForm({ resolver: zodResolver(z.object({ priority_type_id: z.string().uuid('Pilih kategori'), reason: z.string().trim().max(500).optional() })), defaultValues: { priority_type_id: '', reason: '' } })
  const client = useQueryClient()
  const save = useMutation({
    mutationFn: async (values: { priority_type_id: string; reason?: string }) => api.post(`/queues/${queue.id}/verify-priority`, { ...values, reason: values.reason || undefined }),
    onSuccess: () => { void client.invalidateQueries({ queryKey: ['supervisor-board'] }); onDone(`Prioritas ${queue.queue_number} diverifikasi.`) },
  })
  return <Modal title={`Verifikasi prioritas — ${queue.queue_number}`} onClose={onClose}>
    <form className="space-y-4" noValidate onSubmit={handleSubmit((values) => save.mutate(values))}>
      <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600"><b>{queue.customer_name}</b> · saat ini: {queue.priority?.name ?? 'Normal'}{queue.priority_verified ? ' (terverifikasi)' : ''}</p>
      <Field label="Kategori prioritas" error={errors.priority_type_id?.message}><Select {...register('priority_type_id')}><option value="">Pilih kategori…</option>{options.map((priority) => <option key={priority.id} value={priority.id}>{priority.priority_name}</option>)}</Select></Field>
      <Field label="Dasar verifikasi (opsional, tercatat)"><textarea {...register('reason')} rows={2} placeholder="Contoh: KTP menunjukkan usia 70 tahun" className="w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm ring-1 ring-slate-200 focus:bg-white focus:ring-2 focus:ring-brand" /></Field>
      {save.isError && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{errorMessage(save.error)}</p>}
      <div className="flex justify-end gap-3"><Button type="button" variant="secondary" onClick={onClose}>Batal</Button><Button disabled={save.isPending}>{save.isPending ? 'Menyimpan…' : 'Verifikasi'}</Button></div>
    </form>
  </Modal>
}

export function SupervisorPage() {
  const { user } = useAuth()
  const isGlobal = user?.branch_id == null
  const [branchId, setBranchId] = useState(user?.branch_id ?? '')
  const [dialog, setDialog] = useState<Dialog>(null)
  const [notice, setNotice] = useState('')
  const [filter, setFilter] = useState<QueueStatus | ''>('')
  const [now, setNow] = useState(Date.now())
  useEffect(() => { const timer = window.setInterval(() => setNow(Date.now()), 15000); return () => window.clearInterval(timer) }, [])

  const branches = useQuery({ queryKey: ['branches-for-form'], enabled: isGlobal, queryFn: async () => (await api.get<{ data: Branch[] }>('/branches', { params: { per_page: 100 } })).data.data })
  useEffect(() => { if (isGlobal && !branchId && branches.data?.length) setBranchId(branches.data[0].id) }, [isGlobal, branchId, branches.data])
  const board = useQuery({ queryKey: ['supervisor-board', branchId], enabled: Boolean(branchId), refetchInterval: 6000, queryFn: async () => (await api.get<{ data: SupervisorBoard }>('/supervisor/board', { params: { branch_id: branchId || undefined } })).data.data })

  const data = board.data
  const queues = useMemo(() => [...(data?.queues ?? [])].sort((a, b) => STATUS_ORDER.indexOf(a.status) - STATUS_ORDER.indexOf(b.status) || Date.parse(a.registered_at) - Date.parse(b.registered_at)), [data])
  const shown = filter ? queues.filter((queue) => queue.status === filter) : queues
  const counts = (status: QueueStatus) => queues.filter((queue) => queue.status === status).length
  const employees = useMemo(() => [...(data?.employees ?? [])].sort((a, b) => EMPLOYEE_ORDER.indexOf(a.status) - EMPLOYEE_ORDER.indexOf(b.status) || a.employee_name.localeCompare(b.employee_name)), [data])
  const done = (message: string) => { setNotice(message); setDialog(null) }

  return <>
    <PageHeader title="Kendali Antrian" description="Pantau antrian dan petugas secara langsung; assign manual, pindahkan antrian, dan verifikasi prioritas dengan alasan yang tercatat di audit."
      action={isGlobal ? <Select className="w-64" value={branchId} onChange={(event) => setBranchId(event.target.value)} aria-label="Pilih cabang">{(branches.data ?? []).map((branch) => <option key={branch.id} value={branch.id}>{branch.branch_code} — {branch.branch_name}</option>)}</Select> : undefined} />
    {notice && <div role="status" className="mb-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</div>}
    {board.isLoading || !branchId ? <Loading /> : board.isError || !data ? <ErrorState message={errorMessage(board.error, 'Papan supervisor tidak dapat dimuat.')} /> : <div className="grid gap-6 xl:grid-cols-[1.75fr_.75fr]">
      <Card className="min-w-0">
        <CardHeader title="Antrian aktif hari ini" description="Diperbarui otomatis. Aksi tersedia sesuai status antrian." action={
          <div className="flex flex-wrap gap-1.5">{(['', ...STATUS_ORDER] as const).map((status) => <button key={status || 'all'} onClick={() => setFilter(status)} className={`rounded-full px-3 py-1 text-xs font-semibold ${filter === status ? 'bg-brand text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>{status ? `${QUEUE_LABEL[status]} ${counts(status)}` : `Semua ${queues.length}`}</button>)}</div>} />
        {shown.length === 0 ? <EmptyState message="Tidak ada antrian aktif." /> : <div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm">
          <thead className="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400"><tr><th className="px-3 py-3">Nomor</th><th className="px-3 py-3">Customer</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Menunggu</th><th className="px-3 py-3">Ditangani</th><th className="px-3 py-3" /></tr></thead>
          <tbody className="divide-y divide-slate-100">{shown.map((queue) => <tr key={queue.id}>
            <td className="px-3 py-3 align-top"><p className="font-black text-brand">{queue.queue_number}</p><p className="text-xs text-slate-500">{queue.registration_channel === 'ONLINE' ? 'Online' : 'Walk-in'}</p></td>
            <td className="px-3 py-3 align-top"><p className="font-semibold">{queue.customer_name}</p><p className="text-xs text-slate-500">{queue.service_name}{queue.priority && queue.priority.code !== 'NORMAL' && <span className="ml-1 font-semibold text-amber-600">· {queue.priority.name}{queue.priority_verified ? ' ✓' : ''}</span>}</p></td>
            <td className="px-3 py-3 align-top"><Badge tone={QUEUE_TONE[queue.status] ?? 'slate'}>{QUEUE_LABEL[queue.status] ?? queue.status}</Badge></td>
            <td className="whitespace-nowrap px-3 py-3 align-top text-slate-600">{waited(queue.registered_at, now)}</td>
            <td className="px-3 py-3 align-top text-slate-600">{queue.employee ? <><p>{queue.employee.name}</p><p className="text-xs text-slate-500">{queue.counter?.code}</p></> : '—'}</td>
            <td className="px-3 py-3 align-top"><div className="flex justify-end gap-1.5">
              {queue.status === 'WAITING' && <Button variant="secondary" className="whitespace-nowrap px-3 py-1.5 text-xs" onClick={() => { setNotice(''); setDialog({ kind: 'assign', queue }) }}><UserPlus size={14} className="mr-1.5" />Assign</Button>}
              <Button variant="secondary" className="whitespace-nowrap px-3 py-1.5 text-xs" onClick={() => { setNotice(''); setDialog({ kind: 'transfer', queue }) }}><ArrowRightLeft size={14} className="mr-1.5" />Pindahkan</Button>
              <Button variant="ghost" className="whitespace-nowrap px-3 py-1.5 text-xs" title="Verifikasi prioritas" aria-label={`Verifikasi prioritas ${queue.queue_number}`} onClick={() => { setNotice(''); setDialog({ kind: 'priority', queue }) }}><BadgeCheck size={15} /></Button>
            </div></td></tr>)}</tbody></table></div>}
      </Card>
      <Card className="h-fit min-w-0">
        <CardHeader title="Petugas" description={`${employees.filter((employee) => employee.is_available).length} tersedia · ${employees.filter((employee) => employee.status === 'BUSY').length} melayani`} />
        {employees.length === 0 ? <EmptyState message="Belum ada petugas aktif." /> : <ul className="divide-y divide-slate-100">{employees.map((employee: BoardEmployee) => <li key={employee.id} className="flex items-center justify-between gap-3 py-3">
          <div className="min-w-0"><p className="truncate font-semibold">{employee.employee_name}</p><p className="text-xs text-slate-500">{employee.employee_code}{employee.active_queue_number ? ` · menangani ${employee.active_queue_number}` : ''}{employee.service_type_ids.length === 0 || employee.counter_ids.length === 0 ? ' · belum lengkap (layanan/loket)' : ''}</p></div>
          <Badge tone={EMPLOYEE_TONE[employee.status]}>{EMPLOYEE_LABEL[employee.status]}</Badge></li>)}</ul>}
      </Card>
    </div>}
    {data && dialog?.kind === 'assign' && <AssignDialog board={data} queue={dialog.queue} onClose={() => setDialog(null)} onDone={done} />}
    {data && dialog?.kind === 'transfer' && <TransferDialog board={data} queue={dialog.queue} onClose={() => setDialog(null)} onDone={done} />}
    {data && dialog?.kind === 'priority' && <PriorityDialog board={data} queue={dialog.queue} onClose={() => setDialog(null)} onDone={done} />}
  </>
}
