import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarOff, Plus, Trash2 } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/Field'
import { ErrorState, Loading } from '@/components/Feedback'
import { formatDateOnly } from '@/lib/utils'
import type { Branch, BranchAvailability } from '@/types/api'

interface HourRow { weekday: number; configured: boolean; is_closed: boolean; opens_at: string; closes_at: string }
interface QueueSettings { online_daily_quota: number | null; max_waiting_threshold: number | null; operating_hours: HourRow[]; holidays: { id: string; holiday_date: string; holiday_name: string }[]; availability: BranchAvailability }
const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']
const numberOrNull = (value: string) => (value.trim() === '' ? null : Number(value))

export function QueueSettingsPage() {
  const { user } = useAuth()
  const client = useQueryClient()
  const isGlobal = user?.branch_id == null
  const [branchId, setBranchId] = useState(user?.branch_id ?? '')
  const [quota, setQuota] = useState('')
  const [threshold, setThreshold] = useState('')
  const [hours, setHours] = useState<HourRow[]>([])
  const [holiday, setHoliday] = useState({ holiday_date: '', holiday_name: '' })

  const branches = useQuery({ queryKey: ['branches-for-form'], enabled: isGlobal, queryFn: async () => (await api.get<{ data: Branch[] }>('/branches', { params: { per_page: 100 } })).data.data })
  useEffect(() => { if (isGlobal && !branchId && branches.data?.length) setBranchId(branches.data[0].id) }, [isGlobal, branchId, branches.data])

  const settings = useQuery({ queryKey: ['queue-settings', branchId], enabled: Boolean(branchId), queryFn: async () => (await api.get<{ data: QueueSettings }>('/settings/queue', { params: { branch_id: branchId || undefined } })).data.data })
  useEffect(() => {
    if (!settings.data) return
    setQuota(settings.data.online_daily_quota?.toString() ?? '')
    setThreshold(settings.data.max_waiting_threshold?.toString() ?? '')
    setHours(settings.data.operating_hours)
  }, [settings.data])

  const refresh = (data: QueueSettings) => client.setQueryData(['queue-settings', branchId], (previous: QueueSettings | undefined) => ({ ...(previous ?? data), ...data }))
  const save = useMutation({
    mutationFn: async () => (await api.put<{ data: QueueSettings }>('/settings/queue', {
      branch_id: branchId || undefined, online_daily_quota: numberOrNull(quota), max_waiting_threshold: numberOrNull(threshold),
      operating_hours: hours.map(({ weekday, is_closed, opens_at, closes_at }) => ({ weekday, is_closed, opens_at, closes_at })),
    })).data.data,
    onSuccess: refresh,
  })
  const addHoliday = useMutation({
    mutationFn: async () => (await api.post<{ data: QueueSettings }>('/settings/holidays', { ...holiday, branch_id: branchId || undefined })).data.data,
    onSuccess: () => { setHoliday({ holiday_date: '', holiday_name: '' }); void client.invalidateQueries({ queryKey: ['queue-settings', branchId] }) },
  })
  const removeHoliday = useMutation({
    mutationFn: async (id: string) => (await api.delete(`/settings/holidays/${id}`, { params: { branch_id: branchId || undefined } })).data,
    onSuccess: () => void client.invalidateQueries({ queryKey: ['queue-settings', branchId] }),
  })

  const setRow = (weekday: number, patch: Partial<HourRow>) => setHours((rows) => rows.map((row) => (row.weekday === weekday ? { ...row, ...patch } : row)))
  const availability = settings.data?.availability

  return <>
    <PageHeader title="Pengaturan Antrian" description="Atur jam operasional, hari libur, dan batas pendaftaran online. Walk-in di kantor tidak dibatasi kuota online." action={isGlobal ? <Select className="w-64" value={branchId} onChange={(event) => setBranchId(event.target.value)}>{(branches.data ?? []).map((branch) => <option key={branch.id} value={branch.id}>{branch.branch_code} — {branch.branch_name}</option>)}</Select> : undefined} />
    {settings.isLoading || !branchId ? <Loading /> : settings.isError ? <ErrorState message={errorMessage(settings.error)} /> : <div className="grid gap-6 xl:grid-cols-[1.3fr_.7fr]">
      <div className="space-y-6">
        {availability && <div className={`rounded-2xl p-4 text-sm ${availability.online_available ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'}`}>
          <b>Status pendaftaran online hari ini:</b> {availability.online_available ? 'Dibuka' : availability.online_message}
          {availability.online_quota.remaining != null && <span> · sisa kuota {availability.online_quota.remaining}</span>} · {availability.waiting_count} antrian menunggu
        </div>}
        <Card>
          <CardHeader title="Jam operasional" description="Pendaftaran online hanya diterima pada jam buka (WIB). Hari yang belum diatur dianggap buka." />
          <div className="space-y-2">{hours.map((row) => <div key={row.weekday} className="grid grid-cols-[5rem_1fr] items-center gap-3 sm:grid-cols-[6rem_auto_1fr_1fr]">
            <span className="font-semibold">{DAYS[row.weekday - 1]}</span>
            <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={row.is_closed} onChange={(event) => setRow(row.weekday, { is_closed: event.target.checked, configured: true })} />Tutup</label>
            <Input type="time" disabled={row.is_closed} value={row.opens_at} onChange={(event) => setRow(row.weekday, { opens_at: event.target.value, configured: true })} aria-label={`Jam buka ${DAYS[row.weekday - 1]}`} />
            <Input type="time" disabled={row.is_closed} value={row.closes_at} onChange={(event) => setRow(row.weekday, { closes_at: event.target.value, configured: true })} aria-label={`Jam tutup ${DAYS[row.weekday - 1]}`} />
          </div>)}</div>
        </Card>
        <Card>
          <CardHeader title="Batas pendaftaran online" description="Kosongkan untuk tanpa batas." />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Kuota online per hari"><Input type="number" min={0} value={quota} onChange={(event) => setQuota(event.target.value)} placeholder="Tanpa batas" /></Field>
            <Field label="Maks. antrian menunggu (online ditutup bila terlampaui)"><Input type="number" min={0} value={threshold} onChange={(event) => setThreshold(event.target.value)} placeholder="Tanpa batas" /></Field>
          </div>
          {save.isError && <p className="mt-4 text-sm text-red-600">{errorMessage(save.error)}</p>}
          {save.isSuccess && <p className="mt-4 text-sm text-emerald-600">Pengaturan disimpan.</p>}
          <div className="mt-5 flex justify-end"><Button disabled={save.isPending} onClick={() => save.mutate()}>{save.isPending ? 'Menyimpan…' : 'Simpan pengaturan'}</Button></div>
        </Card>
      </div>
      <Card className="h-fit">
        <CardHeader title="Hari libur" description="Pendaftaran online ditutup pada tanggal ini." />
        <div className="space-y-3">
          <Field label="Tanggal"><Input type="date" value={holiday.holiday_date} min={new Date().toLocaleDateString('en-CA')} onChange={(event) => setHoliday({ ...holiday, holiday_date: event.target.value })} /></Field>
          <Field label="Keterangan"><Input value={holiday.holiday_name} maxLength={150} onChange={(event) => setHoliday({ ...holiday, holiday_name: event.target.value })} placeholder="Contoh: Cuti Bersama" /></Field>
          {addHoliday.isError && <p className="text-sm text-red-600">{errorMessage(addHoliday.error)}</p>}
          <Button className="w-full" disabled={addHoliday.isPending || !holiday.holiday_date || !holiday.holiday_name.trim()} onClick={() => addHoliday.mutate()}><Plus size={16} className="mr-2" />Tambah hari libur</Button>
        </div>
        <ul className="mt-6 divide-y divide-slate-100">
          {(settings.data?.holidays ?? []).map((item) => <li key={item.id} className="flex items-center justify-between gap-3 py-3">
            <div><p className="font-semibold">{item.holiday_name}</p><p className="text-xs text-slate-500">{formatDateOnly(item.holiday_date)}</p></div>
            <div className="flex items-center gap-2">{item.holiday_date.slice(0, 10) === new Date().toLocaleDateString('en-CA') && <Badge tone="amber">Hari ini</Badge>}
              <Button variant="ghost" aria-label={`Hapus ${item.holiday_name}`} disabled={removeHoliday.isPending} onClick={() => window.confirm(`Hapus hari libur "${item.holiday_name}"?`) && removeHoliday.mutate(item.id)}><Trash2 size={16} /></Button></div>
          </li>)}
          {(settings.data?.holidays ?? []).length === 0 && <li className="flex items-center gap-2 py-6 text-sm text-slate-400"><CalendarOff size={16} />Belum ada hari libur.</li>}
        </ul>
      </Card>
    </div>}
  </>
}
