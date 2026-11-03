import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Layers, LayoutGrid } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Loading } from '@/components/Feedback'
import type { Counter, Employee, ServiceType } from '@/types/api'

function toggle(list: string[], id: string) { return list.includes(id) ? list.filter((i) => i !== id) : [...list, id] }

function SelectionCard({ selected, active, label, sublabel, color, onClick }: {
  selected: boolean; active: boolean; label: string; sublabel?: string; color: 'teal' | 'violet'; onClick: () => void
}) {
  const ring = color === 'teal'
    ? selected ? 'border-teal-500 bg-teal-50 shadow-teal-100' : 'border-slate-200 bg-white hover:border-teal-300 hover:bg-teal-50/40'
    : selected ? 'border-violet-500 bg-violet-50 shadow-violet-100' : 'border-slate-200 bg-white hover:border-violet-300 hover:bg-violet-50/40'
  const checkBg = color === 'teal' ? 'bg-teal-500' : 'bg-violet-500'
  return (
    <button type="button" onClick={onClick}
      className={`group flex w-full items-center gap-3 rounded-xl border-2 px-3.5 py-3 text-left shadow-sm transition-all ${ring} ${!active ? 'opacity-60' : ''}`}>
      <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-all ${selected ? `${checkBg} border-transparent` : 'border-slate-300 group-hover:border-slate-400'}`}>
        {selected && <Check size={11} strokeWidth={3} className="text-white" />}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold text-slate-800">{label}</span>
        {sublabel && <span className="block truncate text-xs text-slate-500">{sublabel}</span>}
      </span>
      {!active && <span className="shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-400">Nonaktif</span>}
    </button>
  )
}

function SelectAll({ count, total, onToggle }: { count: number; total: number; onToggle: () => void }) {
  if (total === 0) return null
  const allSelected = count === total
  return (
    <button type="button" onClick={onToggle} className="text-xs font-medium text-slate-500 underline-offset-2 hover:text-slate-700 hover:underline">
      {allSelected ? 'Hapus semua' : 'Pilih semua'}
    </button>
  )
}

/** Mengatur layanan (kompetensi) dan loket petugas. Keduanya wajib agar petugas dapat menerima antrian. */
export function EmployeeAccessPanel({ employee, onClose }: { employee: Employee; onClose: () => void }) {
  const client = useQueryClient()
  const [serviceIds, setServiceIds] = useState<string[]>((employee.service_types ?? []).map((s) => s.id))
  const [counterIds, setCounterIds] = useState<string[]>((employee.counters ?? []).map((c) => c.id))
  const services = useQuery({ queryKey: ['employee-panel-services', employee.branch_id], queryFn: async () => (await api.get<{ data: ServiceType[] }>('/service-types', { params: { branch_id: employee.branch_id, per_page: 100 } })).data.data })
  const counters = useQuery({ queryKey: ['employee-panel-counters', employee.branch_id], queryFn: async () => (await api.get<{ data: Counter[] }>('/counters', { params: { branch_id: employee.branch_id, per_page: 100 } })).data.data })
  useEffect(() => { setServiceIds((employee.service_types ?? []).map((s) => s.id)); setCounterIds((employee.counters ?? []).map((c) => c.id)) }, [employee])

  const save = useMutation({
    mutationFn: async () => { await api.put(`/employees/${employee.id}/competencies`, { service_type_ids: serviceIds }); await api.put(`/employees/${employee.id}/counters`, { counter_ids: counterIds }) },
    onSuccess: () => { void client.invalidateQueries({ queryKey: ['employees'] }); onClose() },
  })

  if (services.isLoading || counters.isLoading) return <Loading />

  const allServices = services.data ?? []
  const allCounters = counters.data ?? []

  const toggleAll = (ids: string[], all: string[], set: (v: string[]) => void) => set(ids.length === all.length ? [] : all)

  const SectionHeader = ({ icon: Icon, title, color, count, total, onToggleAll }: { icon: typeof Layers; title: string; color: string; count: number; total: number; onToggleAll: () => void }) => (
    <div className="mb-3 flex items-center justify-between">
      <div className="flex items-center gap-2">
        <span className={`flex h-7 w-7 items-center justify-center rounded-lg ${color}`}><Icon size={14} className="text-white" /></span>
        <span className="text-sm font-bold text-slate-700">{title}</span>
        {total > 0 && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{count}/{total}</span>}
      </div>
      <SelectAll count={count} total={total} onToggle={onToggleAll} />
    </div>
  )

  return (
    <div className="space-y-5">
      {/* Header info petugas */}
      <div className="flex items-center gap-3 rounded-xl bg-gradient-to-r from-slate-700 to-slate-600 px-4 py-3">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/20 text-sm font-bold text-white">
          {employee.employee_name.charAt(0).toUpperCase()}
        </div>
        <div>
          <p className="text-sm font-bold text-white">{employee.employee_name}</p>
          <p className="text-xs text-slate-300">{employee.user?.email ?? '—'} · {employee.user?.roles?.join(', ') ?? '—'}</p>
        </div>
      </div>

      <div className="grid gap-5 sm:grid-cols-2">
        {/* Layanan */}
        <div>
          <SectionHeader icon={Layers} title="Layanan" color="bg-teal-500" count={serviceIds.length} total={allServices.length} onToggleAll={() => toggleAll(serviceIds, allServices.map((s) => s.id), setServiceIds)} />
          {allServices.length === 0
            ? <p className="rounded-xl bg-slate-50 py-6 text-center text-sm text-slate-400">Belum ada layanan di cabang ini.</p>
            : <div className="space-y-1.5">{allServices.map((s) => <SelectionCard key={s.id} selected={serviceIds.includes(s.id)} active={s.is_active} label={s.service_name} sublabel={`Prefix: ${s.queue_prefix}`} color="teal" onClick={() => setServiceIds(toggle(serviceIds, s.id))} />)}</div>}
        </div>

        {/* Loket */}
        <div>
          <SectionHeader icon={LayoutGrid} title="Loket" color="bg-violet-500" count={counterIds.length} total={allCounters.length} onToggleAll={() => toggleAll(counterIds, allCounters.map((c) => c.id), setCounterIds)} />
          {allCounters.length === 0
            ? <p className="rounded-xl bg-slate-50 py-6 text-center text-sm text-slate-400">Belum ada loket di cabang ini.</p>
            : <div className="space-y-1.5">{allCounters.map((c) => <SelectionCard key={c.id} selected={counterIds.includes(c.id)} active={c.is_active} label={c.counter_name} sublabel={c.counter_code} color="violet" onClick={() => setCounterIds(toggle(counterIds, c.id))} />)}</div>}
        </div>
      </div>

      {/* Peringatan bila belum ada pilihan */}
      {(serviceIds.length === 0 || counterIds.length === 0) && (
        <p className="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs text-amber-700">
          Petugas perlu minimal satu layanan <strong>dan</strong> satu loket agar dapat menerima antrian.
        </p>
      )}

      {save.isError && <p className="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-700">{errorMessage(save.error)}</p>}
      <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
        <Button variant="secondary" onClick={onClose}>Batal</Button>
        <Button disabled={save.isPending} onClick={() => save.mutate()}>{save.isPending ? 'Menyimpan…' : 'Simpan'}</Button>
      </div>
    </div>
  )
}
