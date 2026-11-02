import { useState } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { z } from 'zod'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { EmployeeAccessPanel } from '@/components/EmployeeAccessPanel'
import { can } from '@/lib/access'
import type { Branch, Counter, Employee, ServiceType } from '@/types/api'
import type { Ctx, FieldDef, MasterConfig } from './types'

const code = (label: string, max: number) => z.string().trim().min(1, `${label} wajib diisi`).max(max, `Maksimal ${max} karakter`).regex(/^[A-Za-z0-9_-]+$/, 'Hanya huruf, angka, tanda hubung (-) dan garis bawah (_)')
const name = (label: string, max = 150) => z.string().trim().min(2, `${label} minimal 2 karakter`).max(max, `Maksimal ${max} karakter`)
const branchField = (ctx: Ctx): FieldDef[] => ctx.isGlobal ? [{ name: 'branch_id', label: 'Cabang', type: 'select', createOnly: true, options: ctx.branches.map((branch) => ({ value: branch.id, label: `${branch.branch_code} — ${branch.branch_name}` })) }] : []
const branchSchema = (ctx: Ctx, editing: boolean) => ({ branch_id: ctx.isGlobal && !editing ? z.string().uuid('Pilih cabang') : z.string().optional() })
const defaultBranch = (ctx: Ctx) => ({ branch_id: ctx.user?.branch_id ?? (ctx.branches.length === 1 ? ctx.branches[0].id : '') })
const activeBadge = (row: { is_active: boolean; deleted_at?: string | null }) => row.deleted_at ? <Badge tone="red">Dihapus</Badge> : <Badge tone={row.is_active ? 'green' : 'slate'}>{row.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
const identity = (title: string, subtitle?: string | null) => <><p className="font-bold">{title}</p>{subtitle && <p className="text-xs text-slate-500">{subtitle}</p>}</>

export const branchesConfig: MasterConfig<Branch> = {
  key: 'branches', title: 'Manajemen Cabang', description: 'Kelola cabang organisasi. Cabang baru otomatis mendapat pengaturan antrian dan kategori prioritas standar.',
  path: '/branches', singular: 'Cabang', managePermission: 'branches.manage', searchPlaceholder: 'Cari kode, nama, atau alamat cabang…',
  sortable: ['branch_code', 'branch_name', 'is_active', 'created_at'], defaultSort: 'branch_name', branchScoped: false,
  rowName: (row) => row.branch_name,
  deleteNote: 'Cabang hanya dapat dihapus bila tidak lagi memiliki petugas, loket, layanan, atau antrian aktif. Gunakan "Nonaktifkan" bila ingin menutup sementara.',
  columns: () => [
    { id: 'branch', header: 'Cabang', meta: { sortKey: 'branch_name', className: 'min-w-[9rem]' }, cell: ({ row }) => identity(row.original.branch_name, row.original.branch_code) },
    { id: 'contact', header: 'Kontak', cell: ({ row }) => <><p className="text-slate-600">{row.original.address || '—'}</p><p className="text-xs text-slate-500">{row.original.phone || ''}</p></> },
    { id: 'usage', header: 'Data', cell: ({ row }) => <p className="text-xs text-slate-600">{row.original.employees_count ?? 0} petugas · {row.original.counters_count ?? 0} loket · {row.original.service_types_count ?? 0} layanan</p> },
    { id: 'status', header: 'Status', meta: { sortKey: 'is_active' }, cell: ({ row }) => activeBadge(row.original) },
  ],
  fields: () => [
    { name: 'branch_code', label: 'Kode cabang', placeholder: 'mis. 51453', uppercase: true },
    { name: 'branch_name', label: 'Nama cabang' },
    { name: 'address', label: 'Alamat', type: 'textarea' },
    { name: 'phone', label: 'Telepon' },
    { name: 'is_active', label: 'Cabang aktif', type: 'checkbox', editOnly: true, hint: 'Cabang nonaktif tidak muncul pada pendaftaran online.' },
  ],
  schema: () => z.object({ branch_code: code('Kode cabang', 30), branch_name: name('Nama cabang'), address: z.string().max(500).optional(), phone: z.string().max(40).optional(), is_active: z.boolean() }),
  defaults: () => ({ branch_code: '', branch_name: '', address: '', phone: '', is_active: true }),
  toForm: (row) => ({ branch_code: row.branch_code, branch_name: row.branch_name, address: row.address ?? '', phone: row.phone ?? '', is_active: row.is_active }),
}

export const countersConfig: MasterConfig<Counter> = {
  key: 'counters', title: 'Manajemen Loket', description: 'Kelola loket pelayanan. Tipe loket menentukan area (Customer Service/Teller) pada Central Display.',
  path: '/counters', singular: 'Loket', managePermission: 'counters.manage', searchPlaceholder: 'Cari kode atau nama loket…',
  sortable: ['counter_code', 'counter_name', 'counter_type', 'is_active', 'created_at'], defaultSort: 'counter_code', branchScoped: true,
  rowName: (row) => row.counter_name,
  restoreNote: 'Atur ulang loket ini pada petugas yang menggunakannya.',
  deleteNote: 'Loket yang sedang melayani antrian tidak dapat dihapus. Loket juga dilepas dari daftar loket petugas.',
  filters: () => [{ name: 'counter_type', label: 'Semua tipe', options: [{ value: 'CS', label: 'Customer Service' }, { value: 'TL', label: 'Teller' }] }],
  columns: (ctx) => [
    { id: 'counter', header: 'Loket', meta: { sortKey: 'counter_code' }, cell: ({ row }) => identity(row.original.counter_name, row.original.counter_code) },
    { id: 'type', header: 'Tipe', meta: { sortKey: 'counter_type' }, cell: ({ row }) => <Badge tone={row.original.counter_type === 'TL' ? 'blue' : 'amber'}>{row.original.counter_type === 'TL' ? 'Teller' : 'Customer Service'}</Badge> },
    ...(ctx.isGlobal ? [{ id: 'branch', header: 'Cabang', cell: ({ row }: { row: { original: Counter } }) => <span className="text-slate-600">{ctx.branchName(row.original.branch_id)}</span> }] : []),
    { id: 'status', header: 'Status', meta: { sortKey: 'is_active' }, cell: ({ row }) => activeBadge(row.original) },
  ],
  fields: (ctx) => [
    ...branchField(ctx),
    { name: 'counter_code', label: 'Kode loket', placeholder: 'mis. CS-01', uppercase: true },
    { name: 'counter_name', label: 'Nama loket', placeholder: 'mis. Loket Customer Service 1' },
    { name: 'counter_type', label: 'Tipe loket', type: 'select', options: [{ value: 'CS', label: 'Customer Service' }, { value: 'TL', label: 'Teller' }] },
    { name: 'is_active', label: 'Loket aktif', type: 'checkbox', editOnly: true },
  ],
  schema: (editing, ctx) => z.object({ ...branchSchema(ctx, editing), counter_code: code('Kode loket', 40), counter_name: name('Nama loket'), counter_type: z.enum(['CS', 'TL'], { message: 'Pilih tipe loket' }), is_active: z.boolean() }),
  defaults: (ctx) => ({ ...defaultBranch(ctx), counter_code: '', counter_name: '', counter_type: 'CS', is_active: true }),
  toForm: (row) => ({ counter_code: row.counter_code, counter_name: row.counter_name, counter_type: row.counter_type, is_active: row.is_active }),
}

export const servicesConfig: MasterConfig<ServiceType> = {
  key: 'services', title: 'Manajemen Layanan', description: 'Kelola jenis layanan dan prefix nomor antrian (mis. CS-001, TL-001).',
  path: '/service-types', singular: 'Layanan', managePermission: 'services.manage', searchPlaceholder: 'Cari kode, nama, atau prefix layanan…',
  sortable: ['service_code', 'service_name', 'queue_prefix', 'is_active', 'created_at'], defaultSort: 'service_name', branchScoped: true,
  rowName: (row) => row.service_name,
  restoreNote: 'Atur ulang kompetensi layanan ini pada petugas.',
  deleteNote: 'Layanan yang masih memiliki antrian aktif tidak dapat dihapus. Kompetensi petugas pada layanan ini ikut dilepas.',
  columns: (ctx) => [
    { id: 'service', header: 'Layanan', meta: { sortKey: 'service_name' }, cell: ({ row }) => identity(row.original.service_name, row.original.service_code) },
    { id: 'prefix', header: 'Prefix', meta: { sortKey: 'queue_prefix' }, cell: ({ row }) => <span className="font-black text-brand">{row.original.queue_prefix}</span> },
    { id: 'priority', header: 'Prioritas', cell: ({ row }) => <span className="text-slate-600">{row.original.priority_enabled ? 'Diaktifkan' : 'Tidak'}</span> },
    ...(ctx.isGlobal ? [{ id: 'branch', header: 'Cabang', cell: ({ row }: { row: { original: ServiceType } }) => <span className="text-slate-600">{ctx.branchName(row.original.branch_id)}</span> }] : []),
    { id: 'status', header: 'Status', meta: { sortKey: 'is_active' }, cell: ({ row }) => activeBadge(row.original) },
  ],
  fields: (ctx) => [
    ...branchField(ctx),
    { name: 'service_code', label: 'Kode layanan', placeholder: 'mis. CS', uppercase: true },
    { name: 'service_name', label: 'Nama layanan', placeholder: 'mis. Customer Service' },
    { name: 'queue_prefix', label: 'Prefix nomor antrian', placeholder: 'mis. CS', uppercase: true, hint: 'Huruf kapital/angka, unik per cabang.' },
    { name: 'priority_enabled', label: 'Izinkan antrian prioritas', type: 'checkbox' },
    { name: 'is_active', label: 'Layanan aktif', type: 'checkbox', editOnly: true, hint: 'Layanan nonaktif tidak muncul pada pendaftaran.' },
  ],
  schema: (editing, ctx) => z.object({
    ...branchSchema(ctx, editing), service_code: code('Kode layanan', 40), service_name: name('Nama layanan'),
    queue_prefix: z.string().trim().toUpperCase().min(1, 'Prefix wajib diisi').max(10, 'Maksimal 10 karakter').regex(/^[A-Z0-9]+$/, 'Hanya huruf kapital dan angka'),
    priority_enabled: z.boolean(), is_active: z.boolean(),
  }),
  defaults: (ctx) => ({ ...defaultBranch(ctx), service_code: '', service_name: '', queue_prefix: '', priority_enabled: true, is_active: true }),
  toForm: (row) => ({ service_code: row.service_code, service_name: row.service_name, queue_prefix: row.queue_prefix, priority_enabled: row.priority_enabled, is_active: row.is_active }),
}

function EmployeeAccessButton({ employee }: { employee: Employee }) {
  const [open, setOpen] = useState(false)
  return <>
    <Button variant="ghost" title="Atur layanan & loket" aria-label={`Atur layanan dan loket ${employee.employee_name}`} onClick={() => setOpen(true)}><SlidersHorizontal size={16} className="text-brand" /></Button>
    {open && <Modal title="Layanan & loket petugas" wide onClose={() => setOpen(false)}><EmployeeAccessPanel employee={employee} onClose={() => setOpen(false)} /></Modal>}
  </>
}

const employeeRoles = (ctx: Ctx) => [
  { value: 'Customer Service', label: 'Customer Service' }, { value: 'Teller', label: 'Teller' }, { value: 'Supervisor', label: 'Supervisor' },
  ...(can(ctx.user, 'users.manage') ? [{ value: 'Admin Cabang', label: 'Admin Cabang' }] : []),
]

export const employeesConfig: MasterConfig<Employee> = {
  key: 'employees', title: 'Manajemen Petugas', description: 'Kelola petugas beserta akun login dan role. Atur layanan dan loket (ikon pengaturan) agar petugas dapat menerima antrian.',
  path: '/employees', singular: 'Petugas', managePermission: 'employees.manage', searchPlaceholder: 'Cari nama, kode, atau email petugas…',
  sortable: ['employee_code', 'employee_name', 'is_active', 'created_at'], defaultSort: 'employee_name', branchScoped: true,
  rowName: (row) => row.employee_name,
  restoreNote: 'Akun dapat login kembali. Atur ulang layanan dan loket petugas, lalu petugas menekan "Mulai bertugas".',
  deleteNote: 'Akun login petugas ikut ditutup dan sesi aktifnya berakhir. Petugas yang sedang melayani antrian tidak dapat dihapus.',
  filters: (ctx) => [{ name: 'role', label: 'Semua role', options: employeeRoles(ctx) }],
  extraActions: (row) => <EmployeeAccessButton employee={row} />,
  columns: (ctx) => [
    { id: 'employee', header: 'Petugas', meta: { sortKey: 'employee_name' }, cell: ({ row }) => identity(row.original.employee_name, `${row.original.employee_code} · ${row.original.user?.email ?? 'tanpa akun login'}`) },
    { id: 'role', header: 'Role', cell: ({ row }) => <span className="text-slate-600">{row.original.user?.roles?.join(', ') || '—'}</span> },
    { id: 'access', header: 'Layanan & loket', cell: ({ row }) => <div className="text-xs text-slate-600"><p>{(row.original.service_types ?? []).map((service) => service.service_name).join(', ') || <span className="text-amber-600">Belum ada layanan</span>}</p><p className="mt-0.5">{(row.original.counters ?? []).map((counter) => counter.counter_code).join(', ') || <span className="text-amber-600">Belum ada loket</span>}</p></div> },
    ...(ctx.isGlobal ? [{ id: 'branch', header: 'Cabang', cell: ({ row }: { row: { original: Employee } }) => <span className="text-slate-600">{ctx.branchName(row.original.branch_id)}</span> }] : []),
    { id: 'status', header: 'Status', meta: { sortKey: 'is_active' }, cell: ({ row }) => activeBadge(row.original) },
  ],
  fields: (ctx) => [
    ...branchField(ctx),
    { name: 'employee_code', label: 'Kode petugas', placeholder: 'mis. CS-AGUS', uppercase: true },
    { name: 'employee_name', label: 'Nama petugas' },
    { name: 'email', label: 'Email login', type: 'email' },
    { name: 'role_name', label: 'Role', type: 'select', options: employeeRoles(ctx) },
    { name: 'password', label: 'Password', type: 'password', hint: 'Minimal 12 karakter. Saat mengubah petugas, kosongkan jika tidak ingin mengganti (mengganti password mengakhiri sesi login petugas).' },
    { name: 'is_active', label: 'Petugas aktif', type: 'checkbox', editOnly: true, hint: 'Petugas nonaktif tidak dapat login dan tidak menerima antrian.' },
  ],
  schema: (editing, ctx) => z.object({
    ...branchSchema(ctx, editing), employee_code: code('Kode petugas', 40), employee_name: name('Nama petugas'),
    email: z.string().trim().email('Email tidak valid').max(190),
    role_name: z.enum(['Customer Service', 'Teller', 'Supervisor', 'Admin Cabang'], { message: 'Pilih role' }),
    password: editing ? z.string().max(100).optional().refine((value) => !value || value.length >= 12, 'Password minimal 12 karakter') : z.string().min(12, 'Password minimal 12 karakter').max(100),
    is_active: z.boolean(),
  }),
  defaults: (ctx) => ({ ...defaultBranch(ctx), employee_code: '', employee_name: '', email: '', role_name: '', password: '', is_active: true }),
  toForm: (row) => ({ employee_code: row.employee_code, employee_name: row.employee_name, email: row.user?.email ?? '', role_name: row.user?.roles?.[0] ?? '', password: '', is_active: row.is_active }),
}
