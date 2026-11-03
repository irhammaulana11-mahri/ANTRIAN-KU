import type { ReactNode } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import type { z } from 'zod'
import type { Branch, Unit, User } from '@/types/api'

export interface MasterRow { id: string; branch_id?: string | null; user_id?: string | null; is_active: boolean; deleted_at?: string | null }
export interface Option { value: string; label: string; sublabel?: string }
export interface Ctx { user: User | null; branches: Branch[]; isGlobal: boolean; branchName: (id?: string | null) => string }
/** Ctx diperluas dengan daftar unit — dipakai di halaman yang perlu dropdown unit. */
export interface CtxWithUnits extends Ctx { units: Unit[] }

export interface FieldDef {
  name: string
  label: string
  type?: 'text' | 'password' | 'email' | 'select' | 'combobox' | 'checkbox' | 'textarea'
  options?: Option[]
  hint?: string
  placeholder?: string
  createOnly?: boolean
  editOnly?: boolean
  uppercase?: boolean
  /** Sembunyikan field ini saat pengguna mengedit datanya sendiri (user_id cocok dengan user login). */
  hiddenWhenSelf?: boolean
  /** Untuk type combobox: tampilkan opsi "tidak terikat" (nilai kosong). Default true. */
  allowEmpty?: boolean
}

export interface FilterDef { name: string; label: string; options: Option[] }

/** Konfigurasi satu halaman master data; seluruh logika tampilan ada di MasterDataPage. */
export interface MasterConfig<T extends MasterRow> {
  key: string
  title: string
  description: string
  path: string
  singular: string
  managePermission: string
  searchPlaceholder: string
  sortable: string[]
  defaultSort: string
  branchScoped: boolean
  columns: (ctx: Ctx) => ColumnDef<T, unknown>[]
  fields: (ctx: Ctx) => FieldDef[]
  schema: (editing: boolean, ctx: Ctx) => z.ZodType<Record<string, unknown>, Record<string, unknown>>
  defaults: (ctx: Ctx) => Record<string, unknown>
  toForm: (row: T) => Record<string, unknown>
  filters?: (ctx: Ctx) => FilterDef[]
  extraActions?: (row: T, open: (row: T) => void) => ReactNode
  rowName: (row: T) => string
  deleteNote?: string
  restoreNote?: string
}
