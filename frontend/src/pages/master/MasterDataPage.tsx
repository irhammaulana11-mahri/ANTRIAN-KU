import { useMemo, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm, type FieldValues } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Pencil, Plus, Power, RotateCcw, Search, Trash2 } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import { api, errorMessage } from '@/lib/api'
import { can } from '@/lib/access'
import { useAuth } from '@/context/AuthContext'
import { useDebounced } from '@/hooks/useDebounced'
import { PageHeader } from '@/components/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/Field'
import { ConfirmDialog, Modal } from '@/components/ui/Modal'
import { DataTable, Pagination, type PageMeta, type SortState } from '@/components/DataTable'
import { EmptyState, ErrorState, Loading } from '@/components/Feedback'
import type { Branch } from '@/types/api'
import type { Ctx, FieldDef, MasterConfig, MasterRow } from './types'

interface Listing<T> { data: T[]; meta: PageMeta }

function FormDialog<T extends MasterRow>({ config, ctx, row, onClose, onSaved }: { config: MasterConfig<T>; ctx: Ctx; row: T | null; onClose: () => void; onSaved: (message: string) => void }) {
  const editing = row !== null
  const fields = config.fields(ctx).filter((field) => (editing ? !field.createOnly : !field.editOnly))
  const { register, handleSubmit, setError, formState: { errors } } = useForm<FieldValues>({
    resolver: zodResolver(config.schema(editing, ctx)),
    defaultValues: editing ? config.toForm(row) : config.defaults(ctx),
  })
  const client = useQueryClient()
  const save = useMutation({
    mutationFn: async (values: FieldValues) => {
      const payload = Object.fromEntries(Object.entries(values).filter(([name, value]) => !(editing && name === 'password' && !value)))
      if (editing) return (await api.put(`${config.path}/${row.id}`, payload)).data
      return (await api.post(config.path, payload)).data
    },
    onSuccess: () => { void client.invalidateQueries({ queryKey: [config.key] }); onSaved(editing ? `${config.singular} diperbarui.` : `${config.singular} ditambahkan.`) },
    onError: (error) => {
      const serverErrors = (error as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors ?? {}
      const names = new Set(fields.map((field) => field.name))
      Object.entries(serverErrors).forEach(([name, messages]) => { if (names.has(name)) setError(name, { message: messages[0] }) })
    },
  })
  const formLevelError = save.isError && !Object.keys((save.error as { response?: { data?: { errors?: object } } }).response?.data?.errors ?? {}).some((name) => fields.some((field) => field.name === name))

  const renderField = (field: FieldDef) => {
    const error = errors[field.name]?.message as string | undefined
    if (field.type === 'checkbox') {
      return <label key={field.name} className="flex items-start gap-3 rounded-xl bg-slate-50 p-3 text-sm"><input type="checkbox" className="mt-0.5" {...register(field.name)} /><span><b>{field.label}</b>{field.hint && <span className="block text-xs text-slate-500">{field.hint}</span>}</span></label>
    }
    return <Field key={field.name} label={field.label} error={error}>
      {field.type === 'select' ? <Select {...register(field.name)}><option value="">Pilih…</option>{field.options?.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select>
        : field.type === 'textarea' ? <textarea {...register(field.name)} rows={3} className="w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm ring-1 ring-slate-200 focus:bg-white focus:ring-2 focus:ring-brand" />
        : <Input type={field.type ?? 'text'} placeholder={field.placeholder} autoComplete={field.type === 'password' ? 'new-password' : 'off'} className={field.uppercase ? 'uppercase' : undefined} {...register(field.name)} />}
      {field.hint && !error && <span className="block text-xs text-slate-400">{field.hint}</span>}
    </Field>
  }

  return <Modal title={editing ? `Ubah ${config.singular}` : `Tambah ${config.singular}`} onClose={onClose}>
    <form className="space-y-4" noValidate onSubmit={handleSubmit((values) => save.mutate(values))}>
      {fields.map(renderField)}
      {formLevelError && <p className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{errorMessage(save.error, 'Data gagal disimpan.')}</p>}
      <div className="flex justify-end gap-3 pt-2"><Button type="button" variant="secondary" onClick={onClose} disabled={save.isPending}>Batal</Button><Button disabled={save.isPending}>{save.isPending ? 'Menyimpan…' : 'Simpan'}</Button></div>
    </form>
  </Modal>
}

export function MasterDataPage<T extends MasterRow>({ config }: { config: MasterConfig<T> }) {
  const { user } = useAuth()
  const client = useQueryClient()
  const canManage = can(user, config.managePermission)
  const isGlobal = user?.branch_id == null

  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [branchFilter, setBranchFilter] = useState('')
  const [extra, setExtra] = useState<Record<string, string>>({})
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [sort, setSort] = useState<SortState>({ key: config.defaultSort, direction: 'asc' })
  const [form, setForm] = useState<{ row: T | null } | null>(null)
  const [confirm, setConfirm] = useState<{ kind: 'toggle' | 'delete' | 'restore'; row: T } | null>(null)
  const [notice, setNotice] = useState('')
  const q = useDebounced(search.trim())

  const branches = useQuery({ queryKey: ['branches-lookup'], enabled: isGlobal || config.branchScoped, staleTime: 60_000, queryFn: async () => (await api.get<{ data: Branch[] }>('/branches', { params: { per_page: 100 } })).data.data })
  const ctx = useMemo<Ctx>(() => ({ user, isGlobal, branches: branches.data ?? [], branchName: (id) => (branches.data ?? []).find((branch) => branch.id === id)?.branch_name ?? '—' }), [user, isGlobal, branches.data])

  const params = { q: q || undefined, is_active: status === 'deleted' ? undefined : status || undefined, deleted: status === 'deleted' ? 1 : undefined, branch_id: (isGlobal && config.branchScoped ? branchFilter : '') || undefined, sort: sort.key, direction: sort.direction, page, per_page: perPage, ...Object.fromEntries(Object.entries(extra).filter(([, value]) => value)) }
  const list = useQuery({ queryKey: [config.key, params], placeholderData: keepPreviousData, queryFn: async () => (await api.get<Listing<T>>(config.path, { params })).data })

  const mutate = useMutation({
    mutationFn: async (action: { kind: 'toggle' | 'delete' | 'restore'; row: T }) => {
      if (action.kind === 'delete') await api.delete(`${config.path}/${action.row.id}`)
      else if (action.kind === 'restore') await api.post(`${config.path}/${action.row.id}/restore`)
      else await api.put(`${config.path}/${action.row.id}`, { is_active: !action.row.is_active })
      return action
    },
    onSuccess: (action) => {
      void client.invalidateQueries({ queryKey: [config.key] })
      const label = `${config.singular} "${config.rowName(action.row)}"`
      setNotice(action.kind === 'delete' ? `${label} dihapus. Data dapat dipulihkan dari filter "Dihapus".` : action.kind === 'restore' ? `${label} dipulihkan.${config.restoreNote ? ` ${config.restoreNote}` : ''}` : `${label} ${action.row.is_active ? 'dinonaktifkan' : 'diaktifkan'}.`)
      setConfirm(null)
    },
  })

  const changeFilter = (apply: () => void) => { apply(); setPage(1) }
  const actionColumn: ColumnDef<T, unknown> = {
    id: 'actions', header: '', meta: { className: 'text-right whitespace-nowrap' },
    cell: ({ row }) => canManage && row.original.deleted_at ? <div className="flex justify-end">
      <Button variant="secondary" onClick={() => { mutate.reset(); setConfirm({ kind: 'restore', row: row.original }) }}><RotateCcw size={15} className="mr-2" />Pulihkan</Button>
    </div> : canManage ? <div className="flex justify-end gap-1">
      {config.extraActions?.(row.original, (target) => setForm({ row: target }))}
      <Button variant="ghost" aria-label={`Ubah ${config.rowName(row.original)}`} title="Ubah" onClick={() => { setNotice(''); setForm({ row: row.original }) }}><Pencil size={16} /></Button>
      <Button variant="ghost" aria-label={row.original.is_active ? 'Nonaktifkan' : 'Aktifkan'} title={row.original.is_active ? 'Nonaktifkan' : 'Aktifkan'} onClick={() => { mutate.reset(); setConfirm({ kind: 'toggle', row: row.original }) }}><Power size={16} className={row.original.is_active ? 'text-emerald-600' : 'text-slate-400'} /></Button>
      <Button variant="ghost" aria-label={`Hapus ${config.rowName(row.original)}`} title="Hapus" onClick={() => { mutate.reset(); setConfirm({ kind: 'delete', row: row.original }) }}><Trash2 size={16} className="text-red-500" /></Button>
    </div> : null,
  }
  const columns = useMemo(() => [...config.columns(ctx), ...(canManage ? [actionColumn] : [])], [config, ctx, canManage]) // eslint-disable-line react-hooks/exhaustive-deps
  const filters = config.filters?.(ctx) ?? []
  const toggleSort = (key: string) => setSort((current) => ({ key, direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc' }))

  return <>
    <PageHeader title={config.title} description={config.description} action={canManage ? <Button onClick={() => { setNotice(''); setForm({ row: null }) }}><Plus size={17} className="mr-2" />Tambah {config.singular.toLowerCase()}</Button> : undefined} />
    {notice && <div role="status" className="mb-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</div>}
    <Card>
      <div className="mb-5 flex flex-wrap items-end gap-3">
        <div className="relative min-w-56 flex-1"><Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><Input value={search} onChange={(event) => changeFilter(() => setSearch(event.target.value))} placeholder={config.searchPlaceholder} className="pl-9" aria-label="Cari" /></div>
        {isGlobal && config.branchScoped && <Select className="w-52" value={branchFilter} onChange={(event) => changeFilter(() => setBranchFilter(event.target.value))} aria-label="Filter cabang"><option value="">Semua cabang</option>{ctx.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.branch_name}</option>)}</Select>}
        {filters.map((filter) => <Select key={filter.name} className="w-44" value={extra[filter.name] ?? ''} onChange={(event) => changeFilter(() => setExtra({ ...extra, [filter.name]: event.target.value }))} aria-label={filter.label}><option value="">{filter.label}</option>{filter.options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select>)}
        <Select className="w-40" value={status} onChange={(event) => changeFilter(() => setStatus(event.target.value))} aria-label="Filter status"><option value="">Semua status</option><option value="1">Aktif</option><option value="0">Nonaktif</option><option value="deleted">Dihapus</option></Select>
      </div>
      {list.isLoading ? <Loading /> : list.isError ? <ErrorState message={errorMessage(list.error, 'Data gagal dimuat.')} /> : (list.data?.data ?? []).length === 0 ? <EmptyState message={q || status || branchFilter || Object.values(extra).some(Boolean) ? 'Tidak ada data yang cocok dengan pencarian/filter.' : `Belum ada ${config.singular.toLowerCase()}.`} /> :
        <div className={list.isPlaceholderData ? 'opacity-60 transition' : 'transition'}>
          <DataTable columns={columns} data={list.data?.data ?? []} sort={sort} onSort={(key) => { if (config.sortable.includes(key)) changeFilter(() => toggleSort(key)) }} />
          {list.data && <Pagination meta={list.data.meta} onPage={setPage} onPerPage={(size) => changeFilter(() => setPerPage(size))} />}
        </div>}
    </Card>

    {form && <FormDialog config={config} ctx={ctx} row={form.row} onClose={() => setForm(null)} onSaved={(message) => { setNotice(message); setForm(null) }} />}
    {confirm && <ConfirmDialog
      title={confirm.kind === 'restore' ? `Pulihkan ${config.singular.toLowerCase()}?` : confirm.kind === 'delete' ? `Hapus ${config.singular.toLowerCase()}?` : confirm.row.is_active ? `Nonaktifkan ${config.singular.toLowerCase()}?` : `Aktifkan ${config.singular.toLowerCase()}?`}
      danger={confirm.kind === 'delete' || (confirm.kind === 'toggle' && confirm.row.is_active)}
      confirmLabel={confirm.kind === 'restore' ? 'Pulihkan' : confirm.kind === 'delete' ? 'Hapus' : confirm.row.is_active ? 'Nonaktifkan' : 'Aktifkan'}
      busy={mutate.isPending} error={mutate.isError ? errorMessage(mutate.error) : undefined}
      onCancel={() => setConfirm(null)} onConfirm={() => mutate.mutate(confirm)}
      message={<><p><b>{config.rowName(confirm.row)}</b></p><p className="mt-2">{confirm.kind === 'restore' ? (config.restoreNote ?? 'Data akan tampil kembali di daftar.') : confirm.kind === 'delete' ? (config.deleteNote ?? 'Data akan dihapus dari daftar, riwayat transaksi tetap tersimpan.') : confirm.row.is_active ? 'Data tidak akan dapat dipakai sampai diaktifkan kembali.' : 'Data akan dapat dipakai kembali.'}</p></>}
    />}
  </>
}
