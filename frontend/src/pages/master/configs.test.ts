import { describe, expect, it } from 'vitest'
import { branchesConfig, countersConfig, employeesConfig, servicesConfig } from '@/pages/master/configs'
import type { Ctx } from '@/pages/master/types'

const user = (permissions: string[], branchId: string | null) => ({ id: 'u', name: 'U', email: 'u@x.test', branch_id: branchId, permissions })
const branchAdmin: Ctx = { user: user(['employees.manage'], 'b1'), branches: [], isGlobal: false, branchName: () => '' }
const globalAdmin: Ctx = { user: user(['users.manage'], null), branches: [{ id: '11111111-1111-4111-8111-111111111111', branch_code: 'B1', branch_name: 'Cabang 1', is_active: true }], isGlobal: true, branchName: () => 'Cabang 1' }
const employee = { employee_code: 'E-1', employee_name: 'Budi', email: 'budi@x.test', role_name: 'Teller', password: 'passwordpanjang1', is_active: true }

describe('validasi form master data', () => {
  it('petugas: password wajib 12 karakter saat tambah, opsional saat ubah', () => {
    const create = employeesConfig.schema(false, branchAdmin)
    expect(create.safeParse(employee).success).toBe(true)
    expect(create.safeParse({ ...employee, password: 'pendek' }).success).toBe(false)
    expect(create.safeParse({ ...employee, password: '' }).success).toBe(false)

    const edit = employeesConfig.schema(true, branchAdmin)
    expect(edit.safeParse({ ...employee, password: '' }).success).toBe(true)
    expect(edit.safeParse({ ...employee, password: 'pendek' }).success).toBe(false)
  })

  it('petugas: role dan email divalidasi', () => {
    const schema = employeesConfig.schema(false, branchAdmin)
    expect(schema.safeParse({ ...employee, role_name: 'Presiden' }).success).toBe(false)
    expect(schema.safeParse({ ...employee, email: 'bukan-email' }).success).toBe(false)
  })

  it('cabang wajib dipilih saat Super Admin menambah data, tidak untuk Admin Cabang', () => {
    expect(employeesConfig.schema(false, globalAdmin).safeParse(employee).success).toBe(false)
    expect(employeesConfig.schema(false, globalAdmin).safeParse({ ...employee, branch_id: globalAdmin.branches[0].id }).success).toBe(true)
    expect(employeesConfig.schema(false, branchAdmin).safeParse(employee).success).toBe(true)
    expect(employeesConfig.schema(true, globalAdmin).safeParse(employee).success).toBe(true) // cabang tidak diubah saat edit
  })

  it('role Admin Cabang hanya ditawarkan kepada pengguna dengan izin users.manage', () => {
    const roles = (ctx: Ctx) => employeesConfig.fields(ctx).find((field) => field.name === 'role_name')?.options?.map((option) => option.value)
    expect(roles(branchAdmin)).not.toContain('Admin Cabang')
    expect(roles(globalAdmin)).toContain('Admin Cabang')
  })

  it('layanan: prefix diubah menjadi huruf kapital dan tidak boleh berspasi', () => {
    const schema = servicesConfig.schema(false, branchAdmin)
    const base = { service_code: 'CS', service_name: 'Customer Service', queue_prefix: 'cs', priority_enabled: true, is_active: true }
    expect(schema.parse(base).queue_prefix).toBe('CS')
    expect(schema.safeParse({ ...base, queue_prefix: 'C S' }).success).toBe(false)
    expect(schema.safeParse({ ...base, queue_prefix: 'TERLALUPANJANG' }).success).toBe(false)
  })

  it('loket: tipe hanya CS atau TL', () => {
    const schema = countersConfig.schema(false, branchAdmin)
    const base = { counter_code: 'CS-01', counter_name: 'Loket 1', counter_type: 'CS', is_active: true }
    expect(schema.safeParse(base).success).toBe(true)
    expect(schema.safeParse({ ...base, counter_type: 'LAIN' }).success).toBe(false)
    expect(schema.safeParse({ ...base, counter_code: 'CS 01' }).success).toBe(false)
  })

  it('cabang: kode dan nama wajib', () => {
    const schema = branchesConfig.schema(false, globalAdmin)
    expect(schema.safeParse({ branch_code: '51453', branch_name: 'ULP Jombang', address: '', phone: '', is_active: true }).success).toBe(true)
    expect(schema.safeParse({ branch_code: '', branch_name: 'ULP', is_active: true }).success).toBe(false)
  })

  it('form edit tidak menampilkan field khusus tambah dan sebaliknya', () => {
    const names = (editing: boolean) => employeesConfig.fields(globalAdmin).filter((field) => (editing ? !field.createOnly : !field.editOnly)).map((field) => field.name)
    expect(names(false)).toContain('branch_id')
    expect(names(false)).not.toContain('is_active')
    expect(names(true)).not.toContain('branch_id')
    expect(names(true)).toContain('is_active')
  })
})
