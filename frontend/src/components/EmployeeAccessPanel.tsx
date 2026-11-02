import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { Loading } from '@/components/Feedback'
import type { Counter, Employee, ServiceType } from '@/types/api'

function toggle(list: string[], id: string) { return list.includes(id) ? list.filter((item) => item !== id) : [...list, id] }

/** Mengatur layanan (kompetensi) dan loket petugas. Keduanya wajib agar petugas dapat menerima antrian. */
export function EmployeeAccessPanel({ employee, onClose }: { employee: Employee; onClose: () => void }) {
  const client = useQueryClient()
  const [serviceIds, setServiceIds] = useState<string[]>((employee.service_types ?? []).map((service) => service.id))
  const [counterIds, setCounterIds] = useState<string[]>((employee.counters ?? []).map((counter) => counter.id))
  const services = useQuery({ queryKey: ['employee-panel-services', employee.branch_id], queryFn: async () => (await api.get<{ data: ServiceType[] }>('/service-types', { params: { branch_id: employee.branch_id, per_page: 100 } })).data.data })
  const counters = useQuery({ queryKey: ['employee-panel-counters', employee.branch_id], queryFn: async () => (await api.get<{ data: Counter[] }>('/counters', { params: { branch_id: employee.branch_id, per_page: 100 } })).data.data })
  useEffect(() => { setServiceIds((employee.service_types ?? []).map((service) => service.id)); setCounterIds((employee.counters ?? []).map((counter) => counter.id)) }, [employee])
  const save = useMutation({
    mutationFn: async () => { await api.put(`/employees/${employee.id}/competencies`, { service_type_ids: serviceIds }); await api.put(`/employees/${employee.id}/counters`, { counter_ids: counterIds }) },
    onSuccess: () => { void client.invalidateQueries({ queryKey: ['employees'] }); onClose() },
  })
  if (services.isLoading || counters.isLoading) return <Loading />
  const group = (title: string, items: { id: string; label: string; active: boolean }[], selected: string[], set: (ids: string[]) => void) => (
    <div><p className="mb-2 text-sm font-semibold text-slate-700">{title}</p>
      {items.length === 0 ? <p className="text-sm text-slate-400">Belum ada data di cabang ini.</p> : <div className="space-y-1.5">{items.map((item) => <label key={item.id} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={selected.includes(item.id)} onChange={() => set(toggle(selected, item.id))} />{item.label}{!item.active && <span className="text-xs text-slate-400">(nonaktif)</span>}</label>)}</div>}
    </div>)
  return <div className="rounded-2xl bg-slate-50 p-5">
    <p className="mb-4 text-sm font-bold">Atur layanan &amp; loket — {employee.employee_name}</p>
    <div className="grid gap-6 sm:grid-cols-2">
      {group('Layanan yang boleh ditangani', (services.data ?? []).map((service) => ({ id: service.id, label: `${service.service_name} (${service.queue_prefix})`, active: service.is_active })), serviceIds, setServiceIds)}
      {group('Loket yang dapat digunakan', (counters.data ?? []).map((counter) => ({ id: counter.id, label: `${counter.counter_code} — ${counter.counter_name}`, active: counter.is_active })), counterIds, setCounterIds)}
    </div>
    {save.isError && <p className="mt-3 text-sm text-red-600">{errorMessage(save.error)}</p>}
    <div className="mt-5 flex justify-end gap-3"><Button variant="secondary" onClick={onClose}>Batal</Button><Button disabled={save.isPending} onClick={() => save.mutate()}>{save.isPending ? 'Menyimpan…' : 'Simpan'}</Button></div>
  </div>
}
