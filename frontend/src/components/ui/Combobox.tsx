import { useEffect, useRef, useState } from 'react'
import { Check, ChevronDown, Search, X } from 'lucide-react'
import { cn } from '@/lib/utils'

export interface ComboboxOption { value: string; label: string; sublabel?: string }

interface Props {
  value: string
  onChange: (value: string) => void
  options: ComboboxOption[]
  placeholder?: string
  emptyLabel?: string
  allowEmpty?: boolean
  /** Teks pilihan kosong, mis. nama cabang bila kosong berarti milik cabang. */
  emptyOptionLabel?: string
  /** Sisi tempat daftar pilihan menempel; 'right' untuk filter di tepi kanan layar agar daftar tidak terpotong. */
  menuAlign?: 'left' | 'right'
  disabled?: boolean
}

export function Combobox({ value, onChange, options, placeholder = 'Pilih…', emptyLabel = 'Tidak ada pilihan yang cocok.', allowEmpty = true, emptyOptionLabel = 'Tidak terikat unit', menuAlign = 'left', disabled }: Props) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const selected = options.find((o) => o.value === value)

  const filtered = query.trim()
    ? options.filter((o) => `${o.label} ${o.sublabel ?? ''}`.toLowerCase().includes(query.toLowerCase()))
    : options

  useEffect(() => {
    if (!open) return
    const handler = (e: MouseEvent) => { if (containerRef.current && !containerRef.current.contains(e.target as Node)) setOpen(false) }
    const keyHandler = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', handler)
    document.addEventListener('keydown', keyHandler)
    return () => { document.removeEventListener('mousedown', handler); document.removeEventListener('keydown', keyHandler) }
  }, [open])

  const openDropdown = () => { if (!disabled) { setOpen(true); setQuery(''); setTimeout(() => inputRef.current?.focus(), 0) } }

  const select = (opt: ComboboxOption) => { onChange(opt.value); setOpen(false); setQuery('') }

  const clear = (e: React.MouseEvent) => { e.stopPropagation(); onChange(''); setQuery('') }

  return (
    <div ref={containerRef} className="relative">
      {/* Trigger */}
      <button
        type="button"
        onClick={openDropdown}
        disabled={disabled}
        className={cn(
          'flex w-full items-center gap-2 rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-left text-sm ring-1 ring-slate-200 transition',
          'focus:outline-none focus:bg-white focus:ring-2 focus:ring-brand',
          disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-white',
          open && 'bg-white ring-2 ring-brand',
        )}
      >
        <span className="flex-1 truncate">
          {selected ? (
            <span className="font-medium text-slate-800">{selected.label}
              {selected.sublabel && <span className="ml-1.5 font-normal text-slate-500">{selected.sublabel}</span>}
            </span>
          ) : (
            <span className="text-slate-400">{placeholder}</span>
          )}
        </span>
        <span className="flex shrink-0 items-center gap-0.5">
          {selected && !disabled && (
            <span onClick={clear} className="rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600">
              <X size={13} />
            </span>
          )}
          <ChevronDown size={14} className={cn('text-slate-400 transition-transform', open && 'rotate-180')} />
        </span>
      </button>

      {/* Dropdown */}
      {open && (
        <div className={cn('absolute z-50 mt-1.5 w-max min-w-full max-w-[min(26rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl', menuAlign === 'right' ? 'right-0' : 'left-0')}>
          {/* Search */}
          <div className="flex items-center gap-2 border-b border-slate-100 px-3.5 py-3">
            <Search size={13} className="shrink-0 text-slate-400" />
            <input
              ref={inputRef}
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Cari…"
              className="flex-1 bg-transparent text-sm text-slate-700 placeholder:text-slate-400 focus:outline-none"
            />
          </div>

          {/* Options */}
          <ul className="max-h-80 overflow-y-auto py-1.5" role="listbox">
            {/* Empty option — hanya tampil jika allowEmpty */}
            {allowEmpty && (
              <li>
                <button type="button" onClick={() => { onChange(''); setOpen(false) }}
                  className={cn('flex w-full items-center gap-2 px-3.5 py-2 text-left text-sm text-slate-400 hover:bg-slate-50', !value && 'bg-slate-50')}>
                  <span className="w-4" />
                  <span className="italic">— {emptyOptionLabel} —</span>
                </button>
              </li>
            )}
            {filtered.length === 0 ? (
              <li className="px-3.5 py-3 text-center text-sm text-slate-400">{emptyLabel}</li>
            ) : (
              filtered.map((opt) => (
                <li key={opt.value}>
                  <button type="button" onClick={() => select(opt)}
                    className={cn(
                      'flex w-full items-center gap-2 px-3.5 py-2.5 text-left text-sm transition hover:bg-slate-50',
                      opt.value === value && 'bg-teal-50',
                    )}>
                    <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                      {opt.value === value && <Check size={13} className="text-teal-600" strokeWidth={2.5} />}
                    </span>
                    <span className="flex-1 break-words">
                      <span className={cn('font-medium', opt.value === value ? 'text-teal-700' : 'text-slate-700')}>{opt.label}</span>
                      {opt.sublabel && <span className="ml-1.5 text-xs text-slate-400">{opt.sublabel}</span>}
                    </span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}
    </div>
  )
}
