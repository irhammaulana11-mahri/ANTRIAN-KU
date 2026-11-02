import type { ReactNode } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import type { z } from 'zod'
import type { Branch, User } from '@/types/api'

export interface MasterRow { id: string; branch_id?: string | null; is_active: boolean; deleted_at?: string | null }
export interface Option { value: string; label: string }
export interface Ctx { user: User | null; branches: Branch[]; isGlobal: boolean; branchName: (id?: string | null) => string }

export interface FieldDef {
  name: string
  label: string
  type?: 'text' | 'password' | 'email' | 'select' | 'checkbox' | 'textarea'
  options?: Option[]
  hint?: string
  placeholder?: string
  createOnly?: boolean
  editOnly?: boolean
  uppercase?: boolean
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
