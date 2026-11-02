import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Clock, Copy, Printer, Ticket } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { can } from '@/lib/access'
import { PublicShell } from '@/components/layout/PublicShell'
import { TrackingStatus } from '@/components/TrackingStatus'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field, Input, Select } from '@/components/ui/Field'
import { ErrorState, Loading } from '@/components/Feedback'
import { saveTicket } from '@/lib/ticket'
import type { PriorityOption, PublicBranch, Queue, ServiceType } from '@/types/api'

const schema = z.object({
  branch_id: z.string().uuid('Pilih cabang'),
  service_type_id: z.string().uuid('Pilih jenis layanan'),
  customer_name: z.string().trim().min(2, 'Nama minimal 2 karakter').max(150, 'Nama terlalu panjang'),
  customer_phone: z.string().trim().max(40).regex(/^$|^[0-9+\-\s()]{8,20}$/, 'Nomor telepon tidak valid').optional(),
  service_notes: z.string().max(2000).optional(),
  priority_type_id: z.string().optional(),
})
type FormValues = z.infer<typeof schema>
interface Ticket { queue: Pick<Queue, 'queue_number' | 'customer_name'> & { created_at: string }; token: string; branch: string; service: string; mode: 'ONLINE' | 'WALK_IN' }

function branchHint(branch: PublicBranch, walkIn: boolean) {
  const a = branch.availability
  if (walkIn) return a.is_open ? 'Buka' : 'Di luar jam operasional'
  return a.online_available ? 'Buka' : a.online_reason === 'QUOTA_FULL' || a.online_reason === 'WAITING_FULL' ? 'Penuh' : 'Tutup'
}

export function CustomerRegistrationPage() {
  const { user } = useAuth()
  const staff = can(user, 'queues.register')
  const verifier = can(user, 'queues.assign')
  // Mode dipilih manual bila pengguna menekan toggle; selama belum, mengikuti status staf (user dimuat asinkron).
  const [manualMode, setManualMode] = useState<boolean | null>(null)
  const walkIn = manualMode ?? staff
  const setWalkIn = (value: boolean) => setManualMode(value)
  const [ticket, setTicket] = useState<Ticket | null>(null)
  const [copied, setCopied] = useState(false)
  const { register, watch, setValue, handleSubmit, reset, setError, formState: { errors } } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { branch_id: user?.branch_id ?? '' } })
  const branchId = watch('branch_id')

  const branches = useQuery({ queryKey: ['public-branches'], refetchInterval: 60000, queryFn: async () => (await api.get<{ data: PublicBranch[] }>('/catalog/branches')).data.data })
  const services = useQuery({ queryKey: ['public-services', branchId], enabled: Boolean(branchId), queryFn: async () => (await api.get<{ data: ServiceType[] }>('/catalog/service-types', { params: { branch_id: branchId } })).data.data })
  const priorities = useQuery({ queryKey: ['priority-types', branchId], enabled: walkIn && verifier && Boolean(branchId), queryFn: async () => (await api.get<{ data: PriorityOption[] }>('/priority-types', { params: { branch_id: branchId } })).data.data })

  const branch = useMemo(() => branches.data?.find((item) => item.id === branchId), [branches.data, branchId])
  const blocked = branch ? (walkIn ? false : !branch.availability.online_available) : false
  const blockMessage = branch && !walkIn ? branch.availability.online_message : null
  useEffect(() => { setValue('service_type_id', '') }, [branchId, setValue])

  const submit = useMutation({
    mutationFn: async (values: FormValues) => {
      const payload = { ...values, customer_phone: values.customer_phone || undefined, priority_type_id: walkIn && verifier && values.priority_type_id ? values.priority_type_id : undefined }
      const { data } = await api.post<{ data: { queue: Queue; token: string } }>(walkIn ? '/queues/walk-in' : '/queues/online', payload)
      return { data: data.data, values }
    },
    onSuccess: ({ data, values }) => {
      const branchName = branch?.branch_name ?? ''
      setTicket({ queue: data.queue, token: data.token, branch: branchName, service: services.data?.find((item) => item.id === values.service_type_id)?.service_name ?? '', mode: walkIn ? 'WALK_IN' : 'ONLINE' })
      if (!walkIn) saveTicket({ token: data.token, queue_number: data.queue.queue_number, branch_name: branchName, saved_at: new Date().toISOString() })
    },
    onError: (error) => {
      const fields = (error as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors ?? {}
      Object.entries(fields).forEach(([name, messages]) => { if (name in schema.shape) setError(name as keyof FormValues, { message: messages[0] }) })
    },
  })

  const trackUrl = ticket ? `${window.location.origin}/tracking#token=${ticket.token}` : ''
  const copy = async () => { await navigator.clipboard?.writeText(trackUrl); setCopied(true); window.setTimeout(() => setCopied(false), 2000) }

  if (ticket) return <PublicShell>
    <Card className="print-ticket text-center">
      <p className="text-sm font-semibold text-slate-500">{ticket.branch}</p>
      <p className="text-sm text-slate-500">{ticket.service}</p>
      <p className="ticket-number my-4 text-7xl font-black text-brand sm:text-8xl">{ticket.queue.queue_number}</p>
      <p className="ticket-name font-bold">{ticket.queue.customer_name}</p>
      <p className="mt-1 text-xs text-slate-400">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'full', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(ticket.queue.created_at))}</p>
      <div className="no-print mt-6 border-t border-slate-100 pt-6"><TrackingStatus token={ticket.token} compact /></div>
      <p className="print-only ticket-wrap mt-6 text-xs">Mohon menunggu nomor Anda dipanggil.</p>
    </Card>
    <div className="no-print mt-4 space-y-3">
      <div className="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">{ticket.mode === 'ONLINE' ? 'Simpan tautan pelacakan ini. Token hanya ditampilkan sekali dan dipakai untuk memantau atau membatalkan antrian.' : 'Serahkan tiket kepada pelanggan. Tautan pelacakan dapat dibagikan bila diperlukan.'}</div>
      <code className="block break-all rounded-xl bg-white p-3 text-xs text-slate-500 ring-1 ring-slate-100">{trackUrl}</code>
      <div className="flex flex-wrap gap-2">
        <Button variant="secondary" onClick={() => void copy()}><Copy size={16} className="mr-2" />{copied ? 'Tersalin' : 'Salin tautan'}</Button>
        <Button variant="secondary" onClick={() => window.print()}><Printer size={16} className="mr-2" />Cetak tiket</Button>
        {ticket.mode === 'ONLINE' && <Link to={`/tracking#token=${ticket.token}`}><Button>Lacak antrian</Button></Link>}
        <Button variant="ghost" onClick={() => { setTicket(null); reset({ branch_id: branchId }) }}>{ticket.mode === 'WALK_IN' ? 'Pelanggan berikutnya' : 'Daftar lagi'}</Button>
      </div>
    </div>
  </PublicShell>

  return <PublicShell>
    <h1 className="flex items-center gap-3 text-2xl font-black sm:text-3xl"><Ticket className="text-brand" />{walkIn ? 'Ambil Nomor Antrian' : 'Daftar Antrian Online'}</h1>
    <p className="mt-1 mb-6 text-sm text-slate-500">{walkIn ? 'Mode loket/kiosk: pendaftaran langsung di kantor.' : 'Daftar dari rumah, lalu datang saat giliran Anda mendekat.'}</p>
    {staff && <div className="mb-4 inline-flex rounded-xl bg-white p-1 ring-1 ring-slate-200">
      <button type="button" onClick={() => setWalkIn(true)} className={`rounded-lg px-4 py-2 text-sm font-semibold ${walkIn ? 'bg-brand text-white' : 'text-slate-600'}`}>Walk-in (kiosk)</button>
      <button type="button" onClick={() => setWalkIn(false)} className={`rounded-lg px-4 py-2 text-sm font-semibold ${!walkIn ? 'bg-brand text-white' : 'text-slate-600'}`}>Online</button>
    </div>}
    <Card>
      {branches.isLoading ? <Loading /> : branches.isError ? <ErrorState message="Daftar cabang tidak dapat dimuat. Periksa koneksi Anda." /> :
        <form className="space-y-5" noValidate onSubmit={handleSubmit((values) => submit.mutate(values))}>
          <Field label="Cabang" error={errors.branch_id?.message}>
            <Select {...register('branch_id')} disabled={Boolean(user?.branch_id)}>
              <option value="">Pilih cabang</option>
              {(branches.data ?? []).map((item) => <option key={item.id} value={item.id}>{item.branch_name} — {branchHint(item, walkIn)}</option>)}
            </Select>
          </Field>
          {branch && <div className={`flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl p-3 text-sm ${blocked ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-800'}`}>
            <Clock size={16} />
            {blocked ? <span>{blockMessage}</span> : <span>{branch.availability.opens_at ? `Jam operasional ${branch.availability.opens_at}–${branch.availability.closes_at} WIB` : 'Pendaftaran dibuka'}{!walkIn && branch.availability.online_quota.remaining != null && ` · sisa kuota online ${branch.availability.online_quota.remaining}`}</span>}
            {walkIn && !branch.availability.is_open && <Badge tone="amber">{branch.availability.message ?? 'Di luar jam operasional'}</Badge>}
          </div>}
          <Field label="Jenis layanan" error={errors.service_type_id?.message}>
            <Select {...register('service_type_id')} disabled={!branchId || services.isLoading}>
              <option value="">{branchId ? 'Pilih layanan' : 'Pilih cabang terlebih dahulu'}</option>
              {(services.data ?? []).map((service) => <option key={service.id} value={service.id}>{service.service_name}</option>)}
            </Select>
          </Field>
          <Field label="Nama" error={errors.customer_name?.message}><Input {...register('customer_name')} autoComplete="name" placeholder="Nama lengkap" /></Field>
          <Field label="Nomor telepon (opsional)" error={errors.customer_phone?.message}><Input {...register('customer_phone')} type="tel" inputMode="tel" autoComplete="tel" placeholder="08xxxxxxxxxx" /></Field>
          <Field label="Keperluan (opsional)" error={errors.service_notes?.message}><textarea {...register('service_notes')} className="min-h-24 w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm ring-1 ring-slate-200 focus:bg-white focus:ring-2 focus:ring-brand" placeholder="Ceritakan singkat keperluan Anda" /></Field>
          {walkIn && verifier && <Field label="Prioritas (diverifikasi petugas)" error={errors.priority_type_id?.message}>
            <Select {...register('priority_type_id')}><option value="">Normal</option>{(priorities.data ?? []).filter((item) => item.priority_code !== 'NORMAL').map((item) => <option key={item.id} value={item.id}>{item.priority_name}</option>)}</Select>
          </Field>}
          {submit.isError && !Object.keys((submit.error as { response?: { data?: { errors?: object } } }).response?.data?.errors ?? {}).length && <p className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{errorMessage(submit.error)}</p>}
          <Button className="w-full py-3 text-base" disabled={submit.isPending || blocked}>{submit.isPending ? 'Memproses…' : walkIn ? 'Cetak nomor antrian' : 'Ambil nomor antrian'}</Button>
        </form>}
    </Card>
  </PublicShell>
}
