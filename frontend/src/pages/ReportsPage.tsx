import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CheckCircle2, Clock, Download, Hourglass, Ticket, XCircle } from 'lucide-react'
import { api, errorMessage, getData } from '@/lib/api'
import { can } from '@/lib/access'
import { useAuth } from '@/context/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardHeader } from '@/components/ui/Card'
import { StatCard } from '@/components/StatCard'
import { ErrorState, Loading } from '@/components/Feedback'
import { Combobox } from '@/components/ui/Combobox'
import type { Branch, Employee, Unit } from '@/types/api'

type Counts = { total: number; served: number; cancelled: number; active: number }
type Report = {
  period: { from: string; to: string }
  total_queues: number; served_customers: number; waiting_customers: number; cancelled_customers: number
  average_wait_seconds: number | null; average_service_seconds: number | null
  by_status: { status: string; total: number }[]
  by_channel: { channel: 'WALK_IN' | 'ONLINE'; total: number }[]
  by_service: ({ service_name: string } & Counts)[]
  by_unit: ({ unit_id: string | null; unit_name: string } & Counts)[]
  by_hour: { hour: number; total: number }[]
  daily: ({ date: string } & Counts)[]
  employee_productivity: { employee_id: string; employee_name: string | null; served_count: number; average_service_seconds: number | null }[]
}
type Period = 'today' | 'month' | 'monthly'

const STATUS: Record<string, { label: string; color: string }> = {
  WAITING: { label: 'Menunggu', color: 'bg-slate-400' },
  ASSIGNED: { label: 'Ditugaskan', color: 'bg-sky-400' },
  CALLED: { label: 'Dipanggil', color: 'bg-indigo-400' },
  SERVING: { label: 'Dilayani', color: 'bg-violet-500' },
  COMPLETED: { label: 'Selesai', color: 'bg-emerald-500' },
  SKIPPED: { label: 'Dilewati', color: 'bg-amber-400' },
  CANCELLED: { label: 'Dibatalkan', color: 'bg-red-400' },
  NO_SHOW: { label: 'Tidak hadir', color: 'bg-orange-400' },
}

const thisMonth = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }
const duration = (seconds: number | null | undefined) => {
  if (!seconds) return '0 dtk'
  const s = Math.round(seconds)
  return s < 60 ? `${s} dtk` : `${Math.floor(s / 60)} mnt ${s % 60} dtk`
}
const percent = (part: number, total: number) => (total ? Math.round((part / total) * 100) : 0)
const formatDate = (iso: string) => new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })

function CountsTable({ title, label, rows }: { title: string; label: string; rows: ({ name: string } & Counts)[] }) {
  return <Card>
    <CardHeader title={title} />
    {rows.length === 0 ? <p className="py-6 text-center text-sm text-slate-500">Belum ada data.</p> :
      <div className="overflow-x-auto"><table className="w-full text-sm">
        <thead><tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400"><th className="py-2 pr-3">{label}</th><th className="px-2 py-2 text-right">Total</th><th className="px-2 py-2 text-right">Selesai</th><th className="px-2 py-2 text-right">Batal</th><th className="py-2 pl-2 text-right">Aktif</th></tr></thead>
        <tbody>{rows.map((row) => <tr key={row.name} className="border-b border-slate-50 last:border-0">
          <td className="py-2.5 pr-3 font-semibold text-slate-700">{row.name}</td>
          <td className="px-2 py-2.5 text-right font-bold">{row.total}</td>
          <td className="px-2 py-2.5 text-right text-emerald-600">{row.served} <span className="text-xs text-slate-400">({percent(row.served, row.total)}%)</span></td>
          <td className="px-2 py-2.5 text-right text-red-500">{row.cancelled}</td>
          <td className="py-2.5 pl-2 text-right text-slate-500">{row.active}</td>
        </tr>)}</tbody>
      </table></div>}
  </Card>
}

export function ReportsPage() {
  const { user } = useAuth()
  const [period, setPeriod] = useState<Period>('today')
  const [month, setMonth] = useState(thisMonth())
  const [branchId, setBranchId] = useState('')
  const [unitId, setUnitId] = useState('')
  const [employeeId, setEmployeeId] = useState('')
  const [exporting, setExporting] = useState(false)
  const isGlobal = user?.branch_id == null
  const hasOwnUnit = user?.unit_id != null
  const canPickUnit = !hasOwnUnit && can(user, 'units.view')

  const branches = useQuery({ queryKey: ['reports-branches', user?.id], enabled: isGlobal, staleTime: 60_000, queryFn: async () => (await api.get<{ data: Branch[] }>('/branches', { params: { per_page: 100 } })).data.data })
  const units = useQuery({ queryKey: ['reports-units', user?.id, branchId], enabled: canPickUnit, staleTime: 60_000, queryFn: async () => (await api.get<{ data: Unit[]; meta?: { branch_serves_directly: boolean } }>('/units/dropdown', { params: { branch_id: isGlobal ? branchId || undefined : undefined } })).data })
  const unitList = units.data?.data ?? []
  // Antrian cabang (tanpa unit) hanya ada bila cabang membuka layanan langsung.
  const servesDirectly = units.data?.meta?.branch_serves_directly ?? true
  const selectedUnit = unitList.find((unit) => unit.id === unitId)
  const scopeUnitId = hasOwnUnit ? user?.unit_id ?? '' : unitId !== 'none' ? unitId : ''
  // Filter petugas muncul bila cakupan sudah satu unit (Admin Unit otomatis).
  const employees = useQuery({
    queryKey: ['reports-employees', user?.id, scopeUnitId], enabled: Boolean(scopeUnitId), staleTime: 60_000,
    queryFn: async () => (await api.get<{ data: Employee[] }>('/employees', { params: { per_page: 100, branch_id: isGlobal ? selectedUnit?.branch_id : undefined } })).data.data,
  })
  const branchName = isGlobal ? (branches.data ?? []).find((branch) => branch.id === branchId)?.branch_name : user?.branch?.branch_name
  const branchOptions = (branches.data ?? []).map((branch) => ({ value: branch.id, label: branch.branch_name, sublabel: branch.branch_code }))
  const unitOptions = [
    ...(branchName && servesDirectly ? [{ value: 'none', label: `${branchName} (cabang saja)` }] : []),
    ...unitList.filter((unit) => !isGlobal || !branchId || unit.branch_id === branchId).map((unit) => ({ value: unit.id, label: unit.unit_name, sublabel: isGlobal && !branchId ? (branches.data ?? []).find((branch) => branch.id === unit.branch_id)?.branch_name ?? unit.unit_code : unit.unit_code })),
  ]
  const employeeOptions = (employees.data ?? []).filter((employee) => employee.unit_id === scopeUnitId).map((employee) => ({ value: employee.id, label: employee.employee_name, sublabel: employee.employee_code }))
  const params = { period, month: period === 'monthly' ? month : undefined, branch_id: isGlobal ? branchId || undefined : undefined, unit_id: hasOwnUnit ? undefined : unitId || undefined, employee_id: employeeId || undefined }
  const query = useQuery({ queryKey: ['reports-summary', params], queryFn: () => getData<Report>('/reports/summary', params) })
  const report = query.data?.data

  const exportReport = async () => {
    setExporting(true)
    try {
      const response = await api.get('/reports/export', { params, responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = `laporan-antrian-${period === 'monthly' ? month : period}.csv`
      link.click()
      URL.revokeObjectURL(url)
    } finally { setExporting(false) }
  }

  const control = 'rounded-xl border-0 bg-white py-2 pl-3 pr-9 text-sm ring-1 ring-slate-200 focus:ring-2 focus:ring-brand'
  const total = report?.total_queues ?? 0
  const showDaily = period !== 'today'

  return <>
    <PageHeader title="Laporan" description={report ? `Periode ${formatDate(report.period.from)}${period === 'today' ? '' : ` – ${formatDate(report.period.to)}`}${user?.unit ? ` · ${user.unit.unit_name}` : ''}` : 'Rekap aktivitas antrian sesuai wilayah akun Anda.'}
      action={<div className="flex flex-wrap items-center gap-2">
        {isGlobal && <div className="w-52"><Combobox value={branchId} onChange={(value) => { setBranchId(value); setUnitId(''); setEmployeeId('') }} options={branchOptions} placeholder="Semua cabang" emptyOptionLabel="Semua cabang" menuAlign="right" /></div>}
        {canPickUnit && <div className="w-56"><Combobox value={unitId} onChange={(value) => { setUnitId(value); setEmployeeId('') }} options={unitOptions} placeholder="Semua unit" emptyOptionLabel="Semua unit" menuAlign="right" /></div>}
        {hasOwnUnit && <span className="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200">{user?.unit?.unit_name ?? 'Unit saya'}</span>}
        {Boolean(scopeUnitId) && <div className="w-52"><Combobox value={employeeId} onChange={setEmployeeId} options={employeeOptions} placeholder="Semua petugas" emptyOptionLabel="Semua petugas" menuAlign="right" /></div>}
        <select className={control} value={period} onChange={(e) => setPeriod(e.target.value as Period)} aria-label="Periode"><option value="today">Hari ini</option><option value="month">Bulan ini</option><option value="monthly">Pilih bulan…</option></select>
        {period === 'monthly' && <input type="month" className={control.replace('pr-9', 'pr-3')} value={month} max={thisMonth()} onChange={(e) => e.target.value && setMonth(e.target.value)} aria-label="Bulan" />}
        <button className="flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-bold text-white disabled:opacity-60" disabled={exporting || !report} onClick={() => void exportReport()}><Download size={15} />{exporting ? 'Menyiapkan…' : 'Export CSV'}</button>
      </div>} />

    {query.isLoading ? <Loading /> : query.isError ? <ErrorState message={errorMessage(query.error, 'Laporan tidak dapat dimuat.')} /> : report && <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total antrean" value={total} icon={<Ticket size={20} />} />
        <StatCard label={`Terlayani · ${percent(report.served_customers, total)}%`} value={report.served_customers} icon={<CheckCircle2 size={20} />} tone="blue" />
        <StatCard label="Masih aktif" value={report.waiting_customers} icon={<Hourglass size={20} />} tone="amber" />
        <StatCard label={`Batal / dilewati · ${percent(report.cancelled_customers, total)}%`} value={report.cancelled_customers} icon={<XCircle size={20} />} tone="red" />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Rincian status antrian" description="Seluruh antrian pada periode ini menurut status terakhirnya." />
          {total > 0 && <div className="mb-5 flex h-3 overflow-hidden rounded-full bg-slate-100">{report.by_status.filter((s) => s.total > 0).map((s) => <div key={s.status} className={STATUS[s.status]?.color} style={{ width: `${percent(s.total, total)}%` }} title={`${STATUS[s.status]?.label}: ${s.total}`} />)}</div>}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">{report.by_status.map((s) => <div key={s.status} className="rounded-xl bg-slate-50 p-3">
            <div className="flex items-center gap-2 text-xs text-slate-500"><span className={`h-2.5 w-2.5 rounded-full ${STATUS[s.status]?.color}`} />{STATUS[s.status]?.label ?? s.status}</div>
            <p className="mt-1 text-xl font-black text-ink">{s.total}</p>
          </div>)}</div>
        </Card>
        <Card>
          <CardHeader title="Waktu & kanal" />
          <dl className="space-y-3 text-sm">
            <div className="flex items-center justify-between rounded-xl bg-slate-50 p-3"><dt className="flex items-center gap-2 text-slate-500"><Clock size={15} />Rata-rata tunggu</dt><dd className="font-bold">{duration(report.average_wait_seconds)}</dd></div>
            <div className="flex items-center justify-between rounded-xl bg-slate-50 p-3"><dt className="flex items-center gap-2 text-slate-500"><Clock size={15} />Rata-rata pelayanan</dt><dd className="font-bold">{duration(report.average_service_seconds)}</dd></div>
            {report.by_channel.map((c) => <div key={c.channel} className="flex items-center justify-between rounded-xl bg-slate-50 p-3"><dt className="text-slate-500">{c.channel === 'ONLINE' ? 'Daftar online' : 'Daftar di kantor'}</dt><dd className="font-bold">{c.total} <span className="text-xs font-normal text-slate-400">({percent(c.total, total)}%)</span></dd></div>)}
          </dl>
        </Card>
      </div>

      <Card>
        <CardHeader title={showDaily ? 'Tren harian' : 'Jam ramai'} description={showDaily ? 'Jumlah antrian per hari: selesai, batal, dan masih aktif.' : 'Jumlah pendaftaran per jam hari ini.'} />
        <div className="h-72">{total === 0 ? <p className="py-24 text-center text-sm text-slate-500">Belum ada data pada periode ini.</p> :
          <ResponsiveContainer width="100%" height="100%">{showDaily
            ? <BarChart data={report.daily.map((d) => ({ ...d, label: d.date.slice(8) }))}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="label" fontSize={11} /><YAxis allowDecimals={false} fontSize={11} /><Tooltip labelFormatter={(_, payload) => payload?.[0] ? formatDate(payload[0].payload.date) : ''} /><Legend /><Bar dataKey="served" name="Selesai" stackId="a" fill="#10b981" /><Bar dataKey="cancelled" name="Batal" stackId="a" fill="#f87171" /><Bar dataKey="active" name="Aktif" stackId="a" fill="#94a3b8" radius={[6, 6, 0, 0]} /></BarChart>
            : <BarChart data={report.by_hour.map((h) => ({ ...h, label: `${String(h.hour).padStart(2, '0')}:00` }))}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="label" fontSize={11} /><YAxis allowDecimals={false} fontSize={11} /><Tooltip /><Bar dataKey="total" name="Pendaftaran" fill="#0f766e" radius={[6, 6, 0, 0]} /></BarChart>}
          </ResponsiveContainer>}</div>
      </Card>

      <div className={`grid gap-6 ${report.by_unit.length > 0 ? 'lg:grid-cols-2' : ''}`}>
        <CountsTable title="Per layanan" label="Layanan" rows={report.by_service.map((r) => ({ ...r, name: r.service_name }))} />
        {report.by_unit.length > 0 && <CountsTable title="Per unit" label="Unit" rows={report.by_unit.map((r) => ({ ...r, name: r.unit_name }))} />}
      </div>

      <Card>
        <CardHeader title="Produktivitas petugas" description="Jumlah antrian yang diselesaikan tiap petugas." />
        {report.employee_productivity.length === 0 ? <p className="py-6 text-center text-sm text-slate-500">Belum ada antrian yang diselesaikan.</p> :
          <div className="overflow-x-auto"><table className="w-full text-sm">
            <thead><tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400"><th className="py-2 pr-3">Petugas</th><th className="px-2 py-2 text-right">Diselesaikan</th><th className="py-2 pl-2 text-right">Rata-rata pelayanan</th></tr></thead>
            <tbody>{[...report.employee_productivity].sort((a, b) => b.served_count - a.served_count).map((e) => <tr key={e.employee_id} className="border-b border-slate-50 last:border-0"><td className="py-2.5 pr-3 font-semibold text-slate-700">{e.employee_name ?? '—'}</td><td className="px-2 py-2.5 text-right font-bold">{e.served_count}</td><td className="py-2.5 pl-2 text-right text-slate-600">{duration(e.average_service_seconds)}</td></tr>)}</tbody>
          </table></div>}
      </Card>
    </div>}
  </>
}
