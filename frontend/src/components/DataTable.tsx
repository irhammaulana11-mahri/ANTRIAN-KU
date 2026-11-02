import { flexRender, getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table'
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react'

declare module '@tanstack/react-table' {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData, TValue> { sortKey?: string; className?: string }
}

export interface SortState { key: string; direction: 'asc' | 'desc' }

/** Tabel dengan pagination & sorting dari server (manual); sorting dipicu dari header kolom ber-sortKey. */
export function DataTable<T>({ columns, data, sort, onSort }: { columns: ColumnDef<T, unknown>[]; data: T[]; sort: SortState; onSort: (key: string) => void }) {
  const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel(), manualSorting: true, manualPagination: true })
  return <div className="overflow-x-auto">
    <table className="w-full min-w-[640px] text-left text-sm">
      <thead className="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400">
        {table.getHeaderGroups().map((group) => <tr key={group.id}>{group.headers.map((header) => {
          const sortKey = header.column.columnDef.meta?.sortKey
          const active = sortKey !== undefined && sort.key === sortKey
          return <th key={header.id} className="px-3 py-3 font-semibold" aria-sort={active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
            {header.isPlaceholder ? null : sortKey
              ? <button type="button" onClick={() => onSort(sortKey)} className="inline-flex items-center gap-1 uppercase hover:text-ink">{flexRender(header.column.columnDef.header, header.getContext())}{active ? (sort.direction === 'asc' ? <ArrowUp size={13} /> : <ArrowDown size={13} />) : <ChevronsUpDown size={13} className="opacity-40" />}</button>
              : flexRender(header.column.columnDef.header, header.getContext())}
          </th>
        })}</tr>)}
      </thead>
      <tbody className="divide-y divide-slate-100">
        {table.getRowModel().rows.map((row) => <tr key={row.id} className="hover:bg-slate-50/60">{row.getVisibleCells().map((cell) => <td key={cell.id} className={`px-3 py-4 align-top ${cell.column.columnDef.meta?.className ?? ''}`}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>)}</tr>)}
      </tbody>
    </table>
  </div>
}

export interface PageMeta { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }

export function Pagination({ meta, onPage, onPerPage }: { meta: PageMeta; onPage: (page: number) => void; onPerPage: (size: number) => void }) {
  return <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-sm text-slate-500">
    <span>{meta.total === 0 ? 'Tidak ada data' : `Menampilkan ${meta.from}–${meta.to} dari ${meta.total}`}</span>
    <div className="flex items-center gap-3">
      <label className="flex items-center gap-2">Per halaman
        <select value={meta.per_page} onChange={(event) => onPerPage(Number(event.target.value))} className="rounded-lg border-0 bg-slate-50 py-1.5 pl-2 pr-7 text-sm ring-1 ring-slate-200">{[10, 25, 50, 100].map((size) => <option key={size}>{size}</option>)}</select>
      </label>
      <div className="flex items-center gap-1">
        <button type="button" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} className="rounded-lg px-3 py-1.5 ring-1 ring-slate-200 hover:bg-slate-50 disabled:opacity-40">Sebelumnya</button>
        <span className="px-2">Hal. {meta.current_page}/{Math.max(1, meta.last_page)}</span>
        <button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)} className="rounded-lg px-3 py-1.5 ring-1 ring-slate-200 hover:bg-slate-50 disabled:opacity-40">Berikutnya</button>
      </div>
    </div>
  </div>
}
