import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
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

  return <div className="grid min-h-screen lg:grid-cols-2">
    <div className="hidden bg-ink p-12 text-white lg:flex lg:flex-col lg:justify-between">
      <div>
        <BrandLogo className="mb-8 h-25 w-73 brightness-0 invert" />
        <h1 className="max-w-lg text-5xl font-black leading-tight">Antrian lebih tertib, pelayanan lebih berarti.</h1>
        <p className="mt-5 max-w-md text-slate-300">Kelola operasional cabang, petugas, loket, dan customer dalam satu ruang kerja.</p>
      </div>
    </div>
    <div className="flex items-center justify-center p-6">
      <div className="w-full max-w-md">
        <div className="mb-8 lg:hidden"><BrandLogo compact className="mb-4" /><h1 className="text-3xl font-black">Selamat datang kembali</h1></div>
        <div className="mb-8 hidden lg:block"><h2 className="text-3xl font-black">Masuk / Login Aplikasi</h2><p className="mt-2 text-slate-500">Gunakan akun operasional Anda.</p></div>
        {expired && !error && <div role="status" className="mb-5 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Sesi Anda telah berakhir. Silakan masuk kembali.</div>}
        {error && <div className="mb-5 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</div>}
        <form className="space-y-5" onSubmit={handleSubmit(async (values) => {
          try { setError(''); await login(values.email, values.password); navigate('/') }
          catch (e) { setError((e as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'Login gagal. Periksa kembali kredensial Anda.') }
        })}>
          <Field label="Email" error={errors.email?.message}><Input type="email" autoComplete="email" placeholder="nama@organisasi.id" {...register('email')} /></Field>
          <Field label="Password" error={errors.password?.message}><Input type="password" autoComplete="current-password" placeholder="••••••••" {...register('password')} /></Field>
          <Button className="w-full" disabled={isSubmitting}>{isSubmitting ? 'Memproses…' : 'Masuk'}</Button>
        </form>
      </div>
    </div>
  </div>
}
