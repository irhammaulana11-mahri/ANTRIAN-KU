import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { AlertCircle } from 'lucide-react'
import { BrandLogo } from '@/components/BrandLogo'
import { Button } from '@/components/ui/Button'
import { Field, Input } from '@/components/ui/Field'
import { useAuth } from '@/context/AuthContext'

const schema = z.object({ email: z.string().email('Email tidak valid'), password: z.string().min(8, 'Minimal 8 karakter') })
type Form = z.infer<typeof schema>

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const expired = new URLSearchParams(window.location.search).get('expired') === '1'
  const [error, setError] = useState('')
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<Form>({ resolver: zodResolver(schema) })

  return <div className="grid min-h-screen lg:grid-cols-[1fr_1fr]">
    {/* Left panel */}
    <div className="relative hidden overflow-hidden bg-ink lg:flex lg:flex-col lg:justify-between p-12">
      <div className="absolute inset-0 bg-gradient-to-br from-teal-900/40 to-transparent" />
      <div className="relative z-10">
        <BrandLogo className="mb-10 h-25 w-73 brightness-0 invert" />
        <h1 className="max-w-lg text-5xl font-black leading-[1.1] tracking-tight text-white">
          Antrian lebih tertib,<br />pelayanan lebih berarti.
        </h1>
        <p className="mt-5 max-w-sm text-base leading-relaxed text-slate-300">
          Kelola operasional cabang, petugas, loket, dan nasabah dalam satu ruang kerja terpadu.
        </p>
      </div>
      <div className="relative z-10 flex gap-8 text-sm text-slate-500">
        <span>Manajemen Antrian</span>
        <span>·</span>
        <span>Multi Cabang</span>
        <span>·</span>
        <span>Realtime</span>
      </div>
    </div>

    {/* Right panel */}
    <div className="flex items-center justify-center bg-white p-6">
      <div className="w-full max-w-sm">
        <div className="mb-8 lg:hidden text-center">
          <BrandLogo compact className="mb-6 inline-block" />
        </div>
        <div className="mb-8">
          <h2 className="text-2xl font-black text-ink">Masuk ke akun Anda</h2>
          <p className="mt-1 text-sm text-slate-400">Gunakan akun operasional yang diberikan oleh admin.</p>
        </div>

        {expired && !error && (
          <div role="status" className="mb-5 flex items-center gap-2.5 rounded-xl bg-amber-50 p-3.5 text-sm text-amber-800 ring-1 ring-amber-100">
            <AlertCircle size={16} className="shrink-0" />Sesi Anda telah berakhir. Silakan masuk kembali.
          </div>
        )}
        {error && (
          <div className="mb-5 flex items-center gap-2.5 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-100">
            <AlertCircle size={16} className="shrink-0" />{error}
          </div>
        )}

        <form className="space-y-5" onSubmit={handleSubmit(async (values) => {
          try { setError(''); await login(values.email, values.password); navigate('/') }
          catch (e) { setError((e as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'Login gagal. Periksa kembali kredensial Anda.') }
        })}>
          <Field label="Email" error={errors.email?.message}>
            <Input type="email" autoComplete="email" placeholder="nama@organisasi.id" {...register('email')} />
          </Field>
          <Field label="Password" error={errors.password?.message}>
            <Input type="password" autoComplete="current-password" placeholder="••••••••" {...register('password')} />
          </Field>
          <Button className="mt-1 w-full py-3 text-base" disabled={isSubmitting}>
            {isSubmitting ? 'Memproses…' : 'Masuk'}
          </Button>
        </form>
      </div>
    </div>
  </div>
}
