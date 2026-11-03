import { useMutation } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Building2, KeyRound, Mail, MapPin, ShieldCheck, User } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { useToast } from '@/context/ToastContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input } from '@/components/ui/Field'

const profileSchema = z.object({
  name: z.string().trim().min(2, 'Nama minimal 2 karakter').max(150, 'Nama terlalu panjang'),
})
type ProfileForm = z.infer<typeof profileSchema>

const passwordSchema = z.object({
  current_password: z.string().min(1, 'Password saat ini wajib diisi'),
  password: z.string().min(8, 'Password minimal 8 karakter'),
  password_confirmation: z.string(),
}).refine((data) => data.password === data.password_confirmation, {
  message: 'Konfirmasi password tidak cocok',
  path: ['password_confirmation'],
})
type PasswordForm = z.infer<typeof passwordSchema>

function DetailItem({ icon: Icon, label, value }: { icon: React.ElementType; label: string; value: string | undefined }) {
  return <div className="flex items-center gap-3">
    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
      <Icon size={16} />
    </div>
    <div className="min-w-0">
      <p className="text-xs text-slate-400">{label}</p>
      <p className="truncate text-sm font-semibold text-slate-700">{value ?? '—'}</p>
    </div>
  </div>
}


export function ProfilePage() {
  const { user, refresh } = useAuth()
  const toast = useToast()

  const profileForm = useForm<ProfileForm>({
    resolver: zodResolver(profileSchema),
    defaultValues: { name: user?.name ?? '' },
  })
  const passwordForm = useForm<PasswordForm>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })

  const saveProfile = useMutation({
    mutationFn: async (values: ProfileForm) =>
      (await api.put<{ data: unknown; message: string }>('/auth/profile', { ...values, email: user?.email })).data,
    onSuccess: async (data) => {
      toast(data.message, 'success')
      await refresh()
    },
    onError: (error) => {
      const fields = (error as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors ?? {}
      const first = Object.values(fields)[0]?.[0]
      if (first) profileForm.setError('name', { message: first })
      else toast(errorMessage(error, 'Gagal menyimpan profil.'), 'error')
    },
  })

  const savePassword = useMutation({
    mutationFn: async (values: PasswordForm) =>
      (await api.put<{ message: string }>('/auth/password', values)).data,
    onSuccess: (data) => {
      toast(data.message, 'success')
      passwordForm.reset()
    },
    onError: (error) => {
      const fields = (error as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors ?? {}
      Object.entries(fields).forEach(([name, messages]) => {
        if (name in passwordSchema.shape) passwordForm.setError(name as keyof PasswordForm, { message: messages[0] })
      })
      if (!Object.keys(fields).length) toast(errorMessage(error, 'Gagal mengubah password.'), 'error')
    },
  })

  const initials = user?.name?.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase() ?? '?'
  const role = user?.roles?.join(', ') ?? 'Pengguna'

  return <>
    <PageHeader title="Profil Saya" description="Kelola informasi akun dan keamanan login Anda." />

    <div className="space-y-6">
      {/* Identity card */}
      <Card className="overflow-hidden p-0">
        <div className="h-20 bg-gradient-to-r from-teal-500 to-teal-600" />
        <div className="px-6 pb-6">
          <div className="-mt-10 mb-4 flex items-end justify-between gap-4">
            <div className="flex h-20 w-20 items-center justify-center rounded-2xl border-4 border-white bg-teal-50 text-brand shadow-sm">
              <span className="text-2xl font-black">{initials}</span>
            </div>
            <span className="mb-1 inline-block rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-700 ring-1 ring-teal-100">{role}</span>
          </div>
          <h2 className="text-xl font-black text-ink">{user?.name}</h2>
          <p className="mt-0.5 text-sm text-slate-500">{user?.email}</p>
          <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <DetailItem icon={Mail} label="Email" value={user?.email} />
            <DetailItem icon={Building2} label="Cabang" value={user?.branch?.branch_name ?? (user?.branch_id ? 'Cabang terhubung' : 'Semua cabang')} />
            <DetailItem icon={MapPin} label="Unit" value={user?.unit?.unit_name ?? '—'} />
          </div>
        </div>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Edit name */}
        <Card>
          <CardHeader title="Ubah Nama" description="Perbarui nama tampilan akun Anda." />
          <form className="space-y-4" noValidate onSubmit={profileForm.handleSubmit((v) => saveProfile.mutate(v))}>
            <Field label="Nama lengkap" error={profileForm.formState.errors.name?.message}>
              <Input {...profileForm.register('name')} autoComplete="name" placeholder="Nama lengkap" />
            </Field>
            <div className="rounded-xl bg-slate-50 px-3.5 py-2.5">
              <p className="text-xs text-slate-400">Email</p>
              <p className="text-sm font-medium text-slate-600">{user?.email}</p>
            </div>
            <div className="flex justify-end pt-1">
              <Button disabled={saveProfile.isPending}>{saveProfile.isPending ? 'Menyimpan…' : 'Simpan nama'}</Button>
            </div>
          </form>
        </Card>

        {/* Change password */}
        <Card>
          <CardHeader title="Ubah Password" description="Gunakan password kuat, minimal 8 karakter." />
          <form className="space-y-4" noValidate onSubmit={passwordForm.handleSubmit((v) => savePassword.mutate(v))}>
            <Field label="Password saat ini" error={passwordForm.formState.errors.current_password?.message}>
              <Input {...passwordForm.register('current_password')} type="password" autoComplete="current-password" placeholder="••••••••" />
            </Field>
            <Field label="Password baru" error={passwordForm.formState.errors.password?.message}>
              <Input {...passwordForm.register('password')} type="password" autoComplete="new-password" placeholder="Min. 8 karakter" />
            </Field>
            <Field label="Konfirmasi password baru" error={passwordForm.formState.errors.password_confirmation?.message}>
              <Input {...passwordForm.register('password_confirmation')} type="password" autoComplete="new-password" placeholder="Ulangi password baru" />
            </Field>
            <div className="flex justify-end pt-1">
              <Button disabled={savePassword.isPending}>{savePassword.isPending ? 'Mengubah…' : 'Ubah password'}</Button>
            </div>
          </form>
        </Card>
      </div>

      {/* Security info */}
      <Card className="py-4">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-500">
            <ShieldCheck size={20} />
          </div>
          <div>
            <p className="text-sm font-semibold text-slate-700">Keamanan akun</p>
            <p className="text-xs text-slate-400">Email tidak dapat diubah. Hubungi administrator jika perlu mengganti email akun.</p>
          </div>
        </div>
        <div className="mt-4 flex items-center gap-3">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-brand">
            <KeyRound size={20} />
          </div>
          <div>
            <p className="text-sm font-semibold text-slate-700">Sesi aktif</p>
            <p className="text-xs text-slate-400">Token login diperbarui otomatis setiap sesi aktif.</p>
          </div>
        </div>
      </Card>
    </div>
  </>
}
