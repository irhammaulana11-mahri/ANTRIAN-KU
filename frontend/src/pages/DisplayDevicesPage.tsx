import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Check, Copy, KeyRound, Plus, Power, RefreshCcw, Trash2, X } from 'lucide-react'
import { api, errorMessage, fieldError } from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/Field'
import { EmptyState, ErrorState, Loading } from '@/components/Feedback'
import { ConfirmDialog, Modal } from '@/components/ui/Modal'
import { BranchGate } from '@/components/BranchGate'
import { can } from '@/lib/access'
import { formatDate } from '@/lib/utils'
import type { Branch, Unit } from '@/types/api'

interface Device { id: string; branch_id: string; unit_id: string | null; unit_name: string | null; device_code: string; device_name: string; device_type: 'DISPLAY' | 'KIOSK'; last_seen_at: string | null; revoked_at: string | null; is_online: boolean }
interface MediaRow { type: 'STREAM' | 'YOUTUBE'; label: string; url: string }
interface DisplaySettings { display_announcement: string | null; voice_rate: number; voice_volume: number; media_volume: number; display_media?: { type: 'STREAM' | 'YOUTUBE'; label: string | null; url: string }[] }
const MAX_MEDIA = 5

export function DisplayDevicesPage() {
  const { user } = useAuth()
  const client = useQueryClient()
  const isGlobal = user?.branch_id == null
  const [branchId, setBranchId] = useState(user?.branch_id ?? '')
  const hasOwnUnit = Boolean(user?.unit_id)
  const [unitId, setUnitId] = useState('')
  const [filterUnit, setFilterUnit] = useState('')
  const [form, setForm] = useState({ device_code: '', device_name: '', device_type: 'DISPLAY' as 'DISPLAY' | 'KIOSK' })
  const [credential, setCredential] = useState<{ value: string; device: string; type: 'DISPLAY' | 'KIOSK' } | null>(null)
  const [copied, setCopied] = useState(false)
  const [confirmRotate, setConfirmRotate] = useState<Device | null>(null)
  const [confirmRevoke, setConfirmRevoke] = useState<Device | null>(null)
  const [confirmDelete, setConfirmDelete] = useState<Device | null>(null)
  const [assign, setAssign] = useState<{ device: Device; unitId: string } | null>(null)

  const branches = useQuery({ queryKey: ['branches-for-form'], enabled: isGlobal, queryFn: async () => (await api.get<{ data: Branch[] }>('/branches', { params: { per_page: 100 } })).data.data })
  useEffect(() => { if (isGlobal && !branchId && branches.data?.length) setBranchId(branches.data[0].id) }, [isGlobal, branchId, branches.data])

  // Unit pada cabang terpilih. Admin Unit hanya menerima unitnya sendiri dari server.
  const units = useQuery({ queryKey: ['units-lookup', user?.id, branchId], enabled: Boolean(branchId) && can(user, 'units.view'), staleTime: 60_000, queryFn: async () => (await api.get<{ data: Unit[]; meta?: { branch_serves_directly: boolean } }>('/units/dropdown', { params: { branch_id: branchId || undefined } })).data })
  const unitList = units.data?.data ?? []
  const servesDirectly = units.data?.meta?.branch_serves_directly ?? true
  useEffect(() => { setUnitId(''); setFilterUnit('') }, [branchId])

  const devices = useQuery({ queryKey: ['display-devices', branchId, filterUnit], enabled: Boolean(branchId), refetchInterval: 15000, queryFn: async () => (await api.get<{ data: Device[] }>('/display-devices', { params: { branch_id: branchId || undefined, unit_id: filterUnit || undefined } })).data.data })
  const create = useMutation({
    mutationFn: async () => (await api.post<{ data: Device; credential: string }>('/display-devices', { ...form, branch_id: branchId, unit_id: hasOwnUnit ? undefined : unitId })).data,
    onSuccess: (result) => { setCredential({ value: result.credential, device: result.data.device_name, type: result.data.device_type }); setForm({ device_code: '', device_name: '', device_type: form.device_type }); void client.invalidateQueries({ queryKey: ['display-devices'] }) },
  })
  const rotate = useMutation({
    mutationFn: async (device: Device) => ({ device, result: (await api.post<{ credential: string }>(`/display-devices/${device.id}/rotate`)).data }),
    onSuccess: ({ device, result }) => { setConfirmRotate(null); setCredential({ value: result.credential, device: device.device_name, type: device.device_type }); void client.invalidateQueries({ queryKey: ['display-devices'] }) },
  })
  const assignUnit = useMutation({
    mutationFn: async (target: { device: Device; unitId: string }) => api.put(`/display-devices/${target.device.id}/unit`, { unit_id: target.unitId }),
    onSuccess: () => { setAssign(null); void client.invalidateQueries({ queryKey: ['display-devices'] }) },
  })
  const remove = useMutation({
    mutationFn: async (device: Device) => api.delete(`/display-devices/${device.id}`),
    onSuccess: () => { setConfirmDelete(null); void client.invalidateQueries({ queryKey: ['display-devices'] }) },
  })
  const revoke = useMutation({
    mutationFn: async (device: Device) => api.post(`/display-devices/${device.id}/revoke`),
    onSuccess: () => { setConfirmRevoke(null); void client.invalidateQueries({ queryKey: ['display-devices'] }) },
  })

  const settings = useQuery({ queryKey: ['display-settings', branchId], enabled: Boolean(branchId) && can(user, 'settings.manage'), queryFn: async () => (await api.get<{ data: DisplaySettings }>('/settings/display', { params: { branch_id: branchId || undefined } })).data.data })
  const [announcement, setAnnouncement] = useState('')
  const [voice, setVoice] = useState({ voice_rate: 1, voice_volume: 1, media_volume: 0.35 })
  const [media, setMedia] = useState<MediaRow[]>([])
  useEffect(() => { if (settings.data) { setAnnouncement(settings.data.display_announcement ?? ''); setVoice({ voice_rate: settings.data.voice_rate, voice_volume: settings.data.voice_volume, media_volume: settings.data.media_volume ?? 0.35 }); setMedia((settings.data.display_media ?? []).map((item) => ({ type: item.type, label: item.label ?? '', url: item.url }))) } }, [settings.data])
  const saveSettings = useMutation({ mutationFn: async () => api.put('/settings/display', { branch_id: branchId || undefined, display_announcement: announcement || null, ...voice, display_media: media.filter((row) => row.url.trim() !== '').map((row) => ({ type: row.type, label: row.label.trim() || null, url: row.url.trim() })) }), onSuccess: () => void client.invalidateQueries({ queryKey: ['display-settings'] }) })

  const link = credential ? `${window.location.origin}/${credential.type === 'KIOSK' ? 'kiosk' : 'display'}#key=${credential.value}` : ''
  // Format kunci pendek: setiap 4 karakter dipisah strip untuk mudah dibaca (mis. ABCD-EFGH)
  const keyDisplay = credential ? credential.value.match(/.{1,4}/g)?.join('-') ?? credential.value : ''
  const copy = async (text: string) => { await navigator.clipboard?.writeText(text); setCopied(true); window.setTimeout(() => setCopied(false), 2000) }

  return <>
    <PageHeader title="Perangkat Display & Kiosk" description="Daftarkan TV Central Display dan tablet kiosk pengambilan nomor. Setiap perangkat memakai kunci pairing sendiri dan hanya dapat mengakses data cabangnya." action={isGlobal ? <Select className="w-64" value={branchId} onChange={(event) => setBranchId(event.target.value)} aria-label="Pilih cabang"><option value="" disabled>Pilih cabang…</option>{(branches.data ?? []).map((branch) => <option key={branch.id} value={branch.id}>{branch.branch_code} — {branch.branch_name}</option>)}</Select> : undefined} />

    {credential && <Modal title="" onClose={() => setCredential(null)}>
      <div className="text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-500"><KeyRound size={26} /></div>
        <h2 className="mt-3 text-lg font-black text-ink">Kunci Pairing</h2>
        <p className="mt-1 text-sm text-slate-500">Perangkat &ldquo;<b className="text-slate-700">{credential.device}</b>&rdquo;</p>
        <p className="mt-1 text-xs text-amber-700 bg-amber-50 rounded-xl px-3 py-2">Simpan sekarang — kunci ini tidak akan ditampilkan lagi.</p>

        {/* Kunci besar mudah dibaca */}
        <div className="mt-5 rounded-2xl bg-slate-50 px-6 py-5 ring-1 ring-slate-200">
          <p className="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-2">Kunci aktivasi</p>
          <p className="text-4xl font-black tracking-[0.25em] text-brand select-all">{keyDisplay}</p>
          <p className="mt-2 text-xs text-slate-400">Masukkan kode ini di layar aktivasi perangkat</p>
        </div>

        <div className="mt-4 space-y-2">
          <button type="button" onClick={() => void copy(credential.value)} className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand px-4 py-2.5 text-sm font-bold text-white hover:bg-teal-700 transition-colors">
            {copied ? <><Check size={15} />Tersalin</> : <><Copy size={15} />Salin kunci</>}
          </button>
          <button type="button" onClick={() => void copy(link)} className="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50 transition-colors">
            <Copy size={15} />Salin tautan lengkap
          </button>
          <div className="mt-1 rounded-xl bg-slate-50 p-2.5 text-left">
            <p className="text-xs text-slate-400 mb-1">Tautan langsung</p>
            <code className="break-all text-xs text-slate-500">{link}</code>
          </div>
          <button type="button" onClick={() => setCredential(null)} className="flex w-full items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium text-slate-500 hover:bg-slate-50 transition-colors">
            <X size={15} />Tutup
          </button>
        </div>
      </div>
    </Modal>}

    <BranchGate isGlobal={isGlobal} branchId={branchId} branches={branches}>
    <div className="grid gap-6 xl:grid-cols-[1.4fr_.6fr]">
      <Card>
        <CardHeader title="Daftar perangkat" description={hasOwnUnit ? 'Perangkat unit Anda. Status online berdasarkan aktivitas terakhir (2 menit).' : 'Status online berdasarkan aktivitas terakhir (2 menit).'} />
        {!hasOwnUnit && <div className="mb-4"><Select className="w-64" value={filterUnit} onChange={(event) => setFilterUnit(event.target.value)} aria-label="Filter unit"><option value="">Semua titik layanan</option>{unitList.map((unit) => <option key={unit.id} value={unit.id}>{unit.unit_name}</option>)}</Select></div>}
        {devices.isLoading ? <Loading /> : devices.isError ? <ErrorState message={errorMessage(devices.error)} /> : (devices.data ?? []).length === 0 ? <EmptyState message="Belum ada perangkat display di cabang ini." /> :
          <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400"><tr><th className="px-3 py-3">Perangkat</th><th className="px-3 py-3">Unit</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Terakhir aktif</th><th className="px-3 py-3" /></tr></thead>
            <tbody className="divide-y divide-slate-100">{(devices.data ?? []).map((device) => <tr key={device.id}>
              <td className="px-3 py-4"><p className="font-bold">{device.device_name}</p><p className="text-xs text-slate-500">{device.device_code} · {device.device_type === 'KIOSK' ? 'Kiosk antrian' : 'Central Display'}</p></td>
              <td className="px-3 py-4 text-slate-600">{device.unit_name ?? (!hasOwnUnit && unitList.length > 0
                ? <Select className="w-44 py-1.5 text-xs" value="" aria-label={`Tentukan unit ${device.device_name}`} onChange={(event) => { assignUnit.reset(); setAssign({ device, unitId: event.target.value }) }}><option value="" disabled>{servesDirectly ? 'Cabang · pindah ke unit…' : 'Tentukan unit…'}</option>{unitList.map((unit) => <option key={unit.id} value={unit.id}>{unit.unit_name}</option>)}</Select>
                : <span className="text-slate-400">—</span>)}</td>
              <td className="px-3 py-4">{device.revoked_at ? <Badge tone="red">Dinonaktifkan</Badge> : device.is_online ? <Badge tone="green">Online</Badge> : <Badge>Offline</Badge>}</td>
              <td className="whitespace-nowrap px-3 py-4 text-slate-500">{formatDate(device.last_seen_at)}</td>
              <td className="px-3 py-4"><div className="flex justify-end gap-2">
                <Button variant="secondary" className="whitespace-nowrap px-3 py-1.5 text-xs" disabled={rotate.isPending} onClick={() => setConfirmRotate(device)}><RefreshCcw size={14} className="mr-1.5" />{device.revoked_at ? 'Aktifkan ulang' : 'Kunci baru'}</Button>
                {!device.revoked_at && <Button variant="ghost" className="whitespace-nowrap px-3 py-1.5 text-xs text-red-600 hover:bg-red-50" disabled={revoke.isPending} onClick={() => setConfirmRevoke(device)}><Power size={14} className="mr-1.5" />Nonaktifkan</Button>}
                <Button variant="ghost" aria-label={`Hapus ${device.device_name}`} title="Hapus perangkat" className="px-2 py-1.5 text-red-600 hover:bg-red-50" disabled={remove.isPending} onClick={() => { remove.reset(); setConfirmDelete(device) }}><Trash2 size={15} /></Button>
              </div></td></tr>)}</tbody></table></div>}
      </Card>

      <div className="space-y-6">
        <Card>
          <CardHeader title="Tambah perangkat" />
          <div className="space-y-4">
            {hasOwnUnit
              ? <Field label="Unit" hint="Perangkat otomatis tertaut ke unit Anda."><Input readOnly value={unitList[0]?.unit_name ?? '…'} aria-label="Unit" /></Field>
              : <Field label="Unit"><Select value={unitId} onChange={(event) => setUnitId(event.target.value)} aria-label="Unit perangkat">{servesDirectly ? <option value="">Cabang (tanpa unit)</option> : <option value="" disabled>Pilih unit…</option>}{unitList.map((unit) => <option key={unit.id} value={unit.id}>{unit.unit_name}</option>)}</Select>{fieldError(create.error, 'unit_id') && <p role="alert" className="mt-1 text-xs text-red-600">{fieldError(create.error, 'unit_id')}</p>}</Field>}
            <Field label="Tipe perangkat"><Select value={form.device_type} onChange={(event) => setForm({ ...form, device_type: event.target.value as 'DISPLAY' | 'KIOSK' })}><option value="DISPLAY">Central Display (TV)</option><option value="KIOSK">Kiosk antrian (tablet)</option></Select></Field>
            <Field label="Nama perangkat"><Input value={form.device_name} onChange={(event) => setForm({ ...form, device_name: event.target.value })} placeholder="TV Lobby / Kiosk Lobby" /></Field>
            <Field label="Kode perangkat"><Input value={form.device_code} onChange={(event) => setForm({ ...form, device_code: event.target.value })} placeholder="TV-LOBBY-01" /></Field>
            {create.isError && <p className="text-sm text-red-600">{errorMessage(create.error)}</p>}
            <Button className="w-full" disabled={create.isPending || !branchId || (!hasOwnUnit && !unitId && !servesDirectly) || !form.device_code || !form.device_name} onClick={() => create.mutate()}><Plus size={16} className="mr-2" />{create.isPending ? 'Membuat…' : 'Buat perangkat'}</Button>
          </div>
        </Card>

        {can(user, 'settings.manage') && <Card>
          <CardHeader title="Pengumuman &amp; suara" description="Berlaku untuk semua display cabang ini." />
          <div className="space-y-4">
            <Field label="Teks pengumuman (berjalan di bawah layar)"><textarea value={announcement} maxLength={500} onChange={(event) => setAnnouncement(event.target.value)} className="min-h-24 w-full rounded-xl border-0 bg-slate-50 px-3.5 py-2.5 text-sm ring-1 ring-slate-200 focus:bg-white focus:ring-2 focus:ring-brand" placeholder="Contoh: Kantor tutup pukul 15.00 WIB." /></Field>
            <Field label={`Volume bawaan (${Math.round(voice.voice_volume * 100)}%)`}><input type="range" min={0} max={1} step={0.05} value={voice.voice_volume} onChange={(event) => setVoice({ ...voice, voice_volume: Number(event.target.value) })} className="w-full" /></Field>
            <Field label={`Volume media saat idle (${Math.round(voice.media_volume * 100)}%)`}><input type="range" min={0} max={1} step={0.05} value={voice.media_volume} onChange={(event) => setVoice({ ...voice, media_volume: Number(event.target.value) })} className="w-full" /></Field>
            <Field label={`Kecepatan bicara bawaan (${voice.voice_rate.toFixed(2)}x)`}><input type="range" min={0.5} max={1.5} step={0.05} value={voice.voice_rate} onChange={(event) => setVoice({ ...voice, voice_rate: Number(event.target.value) })} className="w-full" /></Field>
            <div className="border-t border-slate-100 pt-4">
              <p className="text-sm font-semibold text-slate-700">Video di layar TV (IPTV / YouTube)</p>
              <p className="mt-1 text-xs text-slate-500">Urutan = prioritas. Media tetap berjalan ketika nomor antrean dipanggil. Atur volume media agar tetap nyaman bersama suara pengumuman. Link YouTube yang diterima: video, siaran langsung, playlist, atau kanal berformat <code>/channel/UC…</code> (memutar semua unggahan).</p>
              <div className="mt-3 space-y-3">
                {media.map((row, index) => <div key={index} className="rounded-xl bg-slate-50 p-3">
                  <div className="flex items-center gap-2">
                    <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand text-xs font-bold text-white">{index + 1}</span>
                    <Select aria-label={`Jenis sumber ${index + 1}`} value={row.type} onChange={(event) => setMedia(media.map((item, i) => (i === index ? { ...item, type: event.target.value as MediaRow['type'] } : item)))}><option value="STREAM">IPTV / URL stream</option><option value="YOUTUBE">YouTube</option></Select>
                    <button type="button" aria-label={`Naikkan prioritas sumber ${index + 1}`} disabled={index === 0} onClick={() => setMedia(media.map((item, i, all) => (i === index - 1 ? all[index] : i === index ? all[index - 1] : item)))} className="rounded-lg p-1.5 text-slate-500 hover:bg-white disabled:opacity-30"><ArrowUp size={16} /></button>
                    <button type="button" aria-label={`Turunkan prioritas sumber ${index + 1}`} disabled={index === media.length - 1} onClick={() => setMedia(media.map((item, i, all) => (i === index ? all[index + 1] : i === index + 1 ? all[index] : item)))} className="rounded-lg p-1.5 text-slate-500 hover:bg-white disabled:opacity-30"><ArrowDown size={16} /></button>
                    <button type="button" aria-label={`Hapus sumber ${index + 1}`} onClick={() => setMedia(media.filter((_, i) => i !== index))} className="rounded-lg p-1.5 text-red-500 hover:bg-white"><Trash2 size={16} /></button>
                  </div>
                  <Input className="mt-2" aria-label={`Alamat sumber ${index + 1}`} value={row.url} onChange={(event) => setMedia(media.map((item, i) => (i === index ? { ...item, url: event.target.value } : item)))} placeholder={row.type === 'YOUTUBE' ? 'https://www.youtube.com/watch?v=… · /playlist?list=… · /channel/UC…' : 'http://192.168.1.10/iptv/channel1.m3u8'} />
                  <Input className="mt-2" aria-label={`Nama sumber ${index + 1}`} value={row.label} maxLength={80} onChange={(event) => setMedia(media.map((item, i) => (i === index ? { ...item, label: event.target.value } : item)))} placeholder="Nama (opsional), mis. IPTV Lobi" />
                  {fieldError(saveSettings.error, `display_media.${index}.url`) && <p role="alert" className="mt-2 text-xs text-red-600">{fieldError(saveSettings.error, `display_media.${index}.url`)}</p>}
                </div>)}
                {media.length === 0 && <p className="rounded-xl bg-slate-50 p-3 text-xs text-slate-500">Belum ada video. Layar TV hanya menampilkan informasi antrian.</p>}
                <Button type="button" variant="secondary" className="w-full" disabled={media.length >= MAX_MEDIA} onClick={() => setMedia([...media, { type: media.length === 0 ? 'STREAM' : 'YOUTUBE', label: '', url: '' }])}><Plus size={15} className="mr-2" />Tambah sumber video{media.length >= MAX_MEDIA ? ` (maks. ${MAX_MEDIA})` : ''}</Button>
              </div>
            </div>
            {saveSettings.isError && !media.some((_, index) => fieldError(saveSettings.error, `display_media.${index}.url`)) && <p className="text-sm text-red-600">{errorMessage(saveSettings.error)}</p>}
            {saveSettings.isSuccess && <p className="text-sm text-emerald-600">Pengaturan disimpan.</p>}
            <Button className="w-full" disabled={saveSettings.isPending || !branchId} onClick={() => saveSettings.mutate()}>{saveSettings.isPending ? 'Menyimpan…' : 'Simpan pengaturan'}</Button>
          </div>
        </Card>}
      </div>
    </div>
    </BranchGate>

    {confirmRotate && <ConfirmDialog
      title={confirmRotate.revoked_at ? 'Aktifkan ulang perangkat?' : 'Buat kunci baru?'}
      message={confirmRotate.revoked_at
        ? `Perangkat "${confirmRotate.device_name}" akan diaktifkan kembali dengan kunci baru. Simpan kunci yang muncul setelahnya.`
        : `Kunci pairing "${confirmRotate.device_name}" akan diperbarui. Perangkat yang sedang tersambung akan otomatis keluar dan perlu dipasangkan ulang.`}
      confirmLabel={confirmRotate.revoked_at ? 'Aktifkan ulang' : 'Buat kunci baru'}
      onConfirm={() => rotate.mutate(confirmRotate)}
      onCancel={() => setConfirmRotate(null)}
      busy={rotate.isPending}
      error={rotate.isError ? errorMessage(rotate.error) : undefined}
    />}

    {assign && <ConfirmDialog
      title="Tentukan unit perangkat?"
      message={`Perangkat "${assign.device.device_name}" akan ditetapkan ke unit "${unitList.find((unit) => unit.id === assign.unitId)?.unit_name ?? ''}" dan hanya menampilkan antrian unit tersebut. Unit tidak dapat diubah setelah ditetapkan.`}
      confirmLabel="Tetapkan unit"
      onConfirm={() => assignUnit.mutate(assign)}
      onCancel={() => setAssign(null)}
      busy={assignUnit.isPending}
      error={assignUnit.isError ? errorMessage(assignUnit.error) : undefined}
    />}

    {confirmDelete && <ConfirmDialog
      title="Hapus perangkat?"
      message={`Perangkat "${confirmDelete.device_name}" (${confirmDelete.device_code}) akan dihapus dari daftar dan langsung keluar dari sistem. Kode perangkat dapat dipakai lagi.`}
      confirmLabel="Hapus"
      danger
      onConfirm={() => remove.mutate(confirmDelete)}
      onCancel={() => setConfirmDelete(null)}
      busy={remove.isPending}
      error={remove.isError ? errorMessage(remove.error) : undefined}
    />}

    {confirmRevoke && <ConfirmDialog
      title="Nonaktifkan perangkat?"
      message={`Perangkat "${confirmRevoke.device_name}" tidak dapat mengakses sistem setelah dinonaktifkan. Anda dapat mengaktifkannya kembali kapan saja.`}
      confirmLabel="Nonaktifkan"
      danger
      onConfirm={() => revoke.mutate(confirmRevoke)}
      onCancel={() => setConfirmRevoke(null)}
      busy={revoke.isPending}
      error={revoke.isError ? errorMessage(revoke.error) : undefined}
    />}
  </>
}
