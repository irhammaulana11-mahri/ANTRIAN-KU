import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Building2 } from 'lucide-react'
import { errorMessage } from '@/lib/api'
import { Card } from '@/components/ui/Card'
import { ErrorState, Loading } from '@/components/Feedback'

interface Props {
  isGlobal: boolean
  branchId: string
  branches: { isLoading: boolean; isError: boolean; error: unknown; data?: unknown[] }
  children: ReactNode
}

/** Halaman per-cabang untuk akun global: tampilkan pesan yang jelas bila cabang belum ada/belum dipilih, bukan loading tanpa akhir. */
export function BranchGate({ isGlobal, branchId, branches, children }: Props) {
  if (!isGlobal || branchId) return <>{children}</>
  if (branches.isLoading) return <Loading />
  if (branches.isError) return <ErrorState message={errorMessage(branches.error, 'Daftar cabang tidak dapat dimuat.')} />
  const empty = (branches.data ?? []).length === 0
  return <Card className="py-12 text-center">
    <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-50 text-brand"><Building2 size={24} /></span>
    <p className="mt-4 font-bold text-ink">{empty ? 'Belum ada cabang' : 'Pilih cabang terlebih dahulu'}</p>
    <p className="mt-1 text-sm text-slate-500">{empty ? 'Tambahkan cabang di menu Cabang agar halaman ini dapat digunakan.' : 'Gunakan pilihan cabang di kanan atas.'}</p>
    {empty && <Link to="/branches" className="mt-5 inline-flex rounded-xl bg-brand px-4 py-2 text-sm font-bold text-white">Kelola cabang</Link>}
  </Card>
}
