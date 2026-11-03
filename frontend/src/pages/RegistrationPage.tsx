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
import type { PublicBranch, Queue, ServiceType, Unit } from '@/types/api'
import type { PriorityOption } from '@/types/api'

const schema = z.object({
  branch_id: z.string().uuid('Pilih cabang'),
  unit_id: z.string().optional(),
  service_type_id: z.string().uuid('Pilih jenis layanan'),
  customer_name: z.string().trim().min(2, 'Nama minimal 2 karakter').max(150, 'Nama terlalu panjang'),
  customer_phone: z.string().trim().max(40).regex(/^$|^[0-9+\-\s()]{8,20}$/, 'Nomor telepon tidak valid').optional(),
  service_notes: z.string().max(2000).optional(),
  priority_type_id: z.string().optional(),
})
type FormValues = z.infer<typeof schema>
interface QueueTicket { queue: Pick<Queue, 'queue_number' | 'customer_name'> & { created_at: string }; token: string; branch: string; unit: string; service: string }

export function CustomerRegistrationPage() {
  const { user } = useAuth()
  const staff = can(user, 'queues.register')
  const verifier = can(user, 'queues.assign')

  // Derive branch and unit from the logged-in user
  const userBranchId = user?.branch_id ?? ''
  const userUnitId = user?.unit_id ?? ''
  const userUnitName = user?.unit?.unit_name ?? ''
  // Admin Cabang: has branch but no unit → must pick a unit
  const needsUnitPick = Boolean(userBranchId) && !userUnitId
  // Super Admin: no branch, no unit → pick branch first
  const isGlobal = !userBranchId

  const [ticket, setTicket] = useState<QueueTicket | null>(null)
  const [copied, setCopied] = useState(false)

  const { register, watch, setValue, handleSubmit, reset, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { branch_id: userBranchId, unit_id: userUnitId || undefined },
  })
  const branchId = watch('branch_id')
  const unitId = watch('unit_id')

  // Super Admin: pick branch from public catalog
  const branches = useQuery({
    queryKey: ['public-branches'],
    enabled: isGlobal,
    refetchInterval: 60000,
    queryFn: async () => (await api.get<{ data: PublicBranch[] }>('/catalog/branches')).data.data,
  })

  // Admin Cabang: pick unit within their branch
  const units = useQuery({
    queryKey: ['units-lookup-registration', userBranchId],
    enabled: needsUnitPick,
    staleTime: 60_000,
    queryFn: async () => (await api.get<{ data: Unit[]; meta?: { branch_serves_directly: boolean } }>('/units/dropdown', { params: { branch_id: userBranchId } })).data,
  })

  const services = useQuery({
    queryKey: ['public-services', branchId],
    enabled: Boolean(branchId),
    queryFn: async () => (await api.get<{ data: ServiceType[] }>('/catalog/service-types', { params: { branch_id: branchId } })).data.data,
  })

  const priorities = useQuery({
    queryKey: ['priority-types', branchId],
    enabled: verifier && Boolean(branchId),
    queryFn: async () => (await api.get<{ data: PriorityOption[] }>('/priority-types', { params: { branch_id: branchId } })).data.data,
  })

  // Branch availability (for hours/status display)
  const publicBranches = useQuery({
    queryKey: ['public-branches'],
    refetchInterval: 60000,
    queryFn: async () => (await api.get<{ data: PublicBranch[] }>('/catalog/branches')).data.data,
  })
  const branch = useMemo(() => publicBranches.data?.find((item) => item.id === branchId), [publicBranches.data, branchId])

  useEffect(() => { setValue('service_type_id', '') }, [branchId, setValue])

  const submit = useMutation({
    mutationFn: async (values: FormValues) => {
      const payload = {
        branch_id: values.branch_id,
        unit_id: values.unit_id || userUnitId || undefined,
        service_type_id: values.service_type_id,
        customer_name: values.customer_name,
        customer_phone: values.customer_phone || undefined,
        service_notes: values.service_notes || undefined,
        priority_type_id: verifier && values.priority_type_id ? values.priority_type_id : undefined,
      }
      const { data } = await api.post<{ data: { queue: Queue; token: string } }>('/queues/walk-in', payload)
      return { data: data.data, values }
    },
    onSuccess: ({ data, values }) => {
      const resolvedUnitName = userUnitName || units.data?.data.find((u) => u.id === (values.unit_id || unitId))?.unit_name || ''
      setTicket({
        queue: data.queue,
        token: data.token,
        branch: branch?.branch_name ?? '',
        unit: resolvedUnitName,
        service: services.data?.find((item) => item.id === values.service_type_id)?.service_name ?? '',
      })
      if (!userUnitId) saveTicket({ token: data.token, queue_number: data.queue.queue_number, branch_name: branch?.branch_name ?? '', saved_at: new Date().toISOString() })
    },
    onError: (error) => {
      const fields = (error as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors ?? {}
      Object.entries(fields).forEach(([name, messages]) => { if (name in schema.shape) setError(name as keyof FormValues, { message: messages[0] }) })
    },
  })

  const trackUrl = ticket ? `${window.location.origin}/tracking#token=${ticket.token}` : ''
  const copy = async () => { await navigator.clipboard?.writeText(trackUrl); setCopied(true); window.setTimeout(() => setCopied(false), 2000) }

  // Pendaftaran hanya lewat kiosk di kantor atau petugas; pengunjung tanpa login tidak diberi formulir yang pasti ditolak.
  if (!user) return <PublicShell>
    <h1 className="flex items-center gap-3 text-2xl font-black sm:text-3xl"><Ticket className="text-brand" />Ambil Nomor Antrian</h1>
    <Card className="mt-6 text-center">
      <p className="text-lg font-bold text-ink">Pendaftaran dilakukan di kantor</p>
      <p className="mt-2 text-sm text-slate-500">Silakan ambil nomor antrian melalui tablet kiosk atau petugas loket di kantor. Setelah mendapat nomor, Anda dapat memantau antrian lewat halaman pelacakan.</p>
      <Link to="/tracking" className="mt-5 inline-block rounded-xl bg-brand px-5 py-2.5 text-sm font-bold text-white hover:bg-teal-700">Lacak antrian saya</Link>
    </Card>
  </PublicShell>

  if (ticket) return <PublicShell>
    <Card className="print-ticket text-center">
      <p className="text-sm font-semibold text-slate-500">{ticket.unit || ticket.branch}</p>
      <p className="text-sm text-slate-500">{ticket.service}</p>
      <p className="ticket-number my-4 text-7xl font-black text-brand sm:text-8xl">{ticket.queue.queue_number}</p>
      <p className="ticket-name font-bold">{ticket.queue.customer_name}</p>
      <p className="mt-1 text-xs text-slate-400">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'full', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(ticket.queue.created_at))}</p>
      <div className="no-print mt-6 border-t border-slate-100 pt-6"><TrackingStatus token={ticket.token} compact /></div>
      <p className="print-only ticket-wrap mt-6 text-xs">Mohon menunggu nomor Anda dipanggil.</p>
    </Card>
    <div className="no-print mt-4 space-y-3">
      <div className="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">Serahkan tiket kepada pelanggan. Tautan pelacakan dapat dibagikan bila diperlukan.</div>
      <code className="block break-all rounded-xl bg-white p-3 text-xs text-slate-500 ring-1 ring-slate-100">{trackUrl}</code>
      <div className="flex flex-wrap gap-2">
        <Button variant="secondary" onClick={() => void copy()}><Copy size={16} className="mr-2" />{copied ? 'Tersalin' : 'Salin tautan'}</Button>
        <Button variant="secondary" onClick={() => window.print()}><Printer size={16} className="mr-2" />Cetak tiket</Button>
        <Button variant="ghost" onClick={() => { setTicket(null); reset({ branch_id: branchId, unit_id: unitId }) }}>Pelanggan berikutnya</Button>
      </div>
    </div>
  </PublicShell>

  return <PublicShell>
    <h1 className="flex items-center gap-3 text-2xl font-black sm:text-3xl"><Ticket className="text-brand" />Ambil Nomor Antrian</h1>
    <p className="mt-1 mb-6 text-sm text-slate-500">Mode loket/kiosk: pendaftaran langsung di kantor.</p>
    <Card>
      {isGlobal && branches.isLoading ? <Loading /> : isGlobal && branches.isError ? <ErrorState message="Daftar cabang tidak dapat dimuat." /> :
        <form className="space-y-5" noValidate onSubmit={handleSubmit((values) => submit.mutate(values))}>

          {/* Super Admin: pick branch */}
          {isGlobal && <Field label="Cabang" error={errors.branch_id?.message}>
            <Select {...register('branch_id')} onChange={(e) => { setValue('branch_id', e.target.value); setValue('unit_id', undefined) }}>
              <option value="">Pilih cabang</option>
              {(branches.data ?? []).map((item) => <option key={item.id} value={item.id}>{item.branch_name}</option>)}
            </Select>
          </Field>}

          {/* Admin Unit / CS / Teller: show unit name as label */}
          {userUnitId && <div className="rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm">
            <span className="text-slate-500">Unit · </span>
            <span className="font-semibold text-slate-700">{userUnitName}</span>
          </div>}

          {/* Admin Cabang: pick unit */}
          {needsUnitPick && <Field label="Unit" error={errors.unit_id?.message}>
            {units.isLoading ? <Select disabled><option>Memuat unit…</option></Select>
              : <Select {...register('unit_id')}>
                  <option value="">{units.data?.meta?.branch_serves_directly === false ? 'Pilih unit' : (user?.branch?.branch_name ?? 'Cabang')}</option>
                  {(units.data?.data ?? []).map((unit) => <option key={unit.id} value={unit.id}>{unit.unit_name} ({unit.unit_code})</option>)}
                </Select>}
          </Field>}

          {/* Branch availability status */}
          {branch && <div className={`flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl p-3 text-sm ${!branch.availability.is_open ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-800'}`}>
            <Clock size={16} />
            <span>{branch.availability.opens_at ? `Jam operasional ${branch.availability.opens_at}–${branch.availability.closes_at} WIB` : 'Pendaftaran dibuka'}</span>
            {!branch.availability.is_open && <Badge tone="amber">{branch.availability.message ?? 'Di luar jam operasional'}</Badge>}
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
          {verifier && <Field label="Prioritas (diverifikasi petugas)" error={errors.priority_type_id?.message}>
            <Select {...register('priority_type_id')}><option value="">Normal</option>{(priorities.data ?? []).filter((item) => item.priority_code !== 'NORMAL').map((item) => <option key={item.id} value={item.id}>{item.priority_name}</option>)}</Select>
          </Field>}
          {submit.isError && !Object.keys((submit.error as { response?: { data?: { errors?: object } } }).response?.data?.errors ?? {}).length && <p className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{errorMessage(submit.error)}</p>}
          <Button className="w-full py-3 text-base" disabled={submit.isPending}>{submit.isPending ? 'Memproses…' : 'Cetak nomor antrian'}</Button>
        </form>}
    </Card>

    {/* Staff note */}
    {staff && <p className="mt-3 text-center text-xs text-slate-400">Halaman ini khusus untuk pendaftaran langsung di loket atau kiosk.</p>}
  </PublicShell>
}
