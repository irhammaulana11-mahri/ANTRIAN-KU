# UI/UX AUDIT
**Tanggal:** 2026-11-03

---

## 1. DESIGN SYSTEM

### Token & Warna
```
Brand: teal (--brand: oklch(0.62 0.14 188))
Ink: slate-900
Background: slate-50 (surface), white (card)
```

**Temuan:**
- ✅ Token warna konsisten via Tailwind config (`brand`, `ink`, `surface`)
- ✅ Dark mode tidak diimplementasikan — choice yang deliberate, menyederhanakan QA
- ✅ Komponen UI terpusat: `Button`, `Badge`, `Field`, `Input`, `Select`, `Modal`, `ConfirmDialog`
- ⚠️ Beberapa halaman masih menggunakan class warna hardcoded (`bg-teal-50`) alih-alih token `brand`

### Tipografi
- Font: System font stack
- Heading: `font-black` (900) untuk judul halaman
- Body: `text-sm` (14px) sebagai default — konsisten
- Micro text: `text-xs` (12px) untuk label statistik

**Temuan:**
- ✅ Hierarki tipografi jelas — h1 besar, label kecil
- ⚠️ Ukuran font di `SupervisorPage` header table menggunakan `text-[11px]` — terlalu kecil, sulit dibaca di layar kecil

---

## 2. HALAMAN PER ROLE

### LoginPage
- ✅ Layout split 50/50 desktop, single column mobile
- ✅ Left panel: gradient overlay, tagline kuat
- ✅ Alert `expired` dan `error` menggunakan ikon `AlertCircle` — konsisten
- ✅ Submit button full-width dengan ukuran `py-3 text-base` — mudah diklik di mobile
- ⚠️ Tidak ada "show/hide password" toggle

### DashboardPage  
- ✅ 4 StatCard dengan accent bar warna berbeda per metrik
- ✅ Tabel aktivitas terbaru dengan kolom queue_number fixed-width
- ✅ Filter by status di halaman QueuePages
- ⚠️ Dashboard tidak ada widget "petugas aktif sekarang" — informasi penting untuk supervisor
- ⚠️ Tidak ada auto-refresh — data stale setelah beberapa menit (harus reload manual)

### OperatorPage (CS/Teller)
- ✅ Active queue wrapper dengan gradient `from-teal-50 to-slate-50` — visual hierarchy jelas
- ✅ Tombol aksi (Panggil, Mulai, Selesai, Skip) dengan ikon dan warna yang berbeda
- ✅ Dot badge pada status petugas
- ⚠️ Tidak ada countdown timer untuk antrian yang sedang dilayani
- ⚠️ Recall button tidak ada visual feedback bahwa suara sedang diputar

### SupervisorPage
- ✅ Tabel antrian aktif dengan filter status
- ✅ Employee list menampilkan status real-time
- ⚠️ Table tidak responsive di mobile — kolom terlalu banyak untuk layar kecil
- ⚠️ Manual assign tidak ada konfirmasi visual sebelum aksi

### QueuePages (History)
- ✅ Summary tiles redesigned dengan accent bar
- ✅ Status dot badge konsisten
- ✅ Paginasi tersedia
- ⚠️ Tidak ada export ke CSV/Excel

### DisplayDevicesPage
- ✅ ConfirmDialog untuk "Kunci Baru" dan "Nonaktifkan" — tidak ada accidental action
- ✅ Error ditampilkan di dalam dialog
- ⚠️ Tidak ada realtime status apakah device sedang online/offline

### ProfilePage
- ✅ Form update nama/email dan ganti password
- ✅ Validasi inline dengan error per field

---

## 3. KOMPONEN UI

### Button
- ✅ 3 variant: `primary` (teal), `secondary` (outline), `danger` (red)
- ✅ `active:scale-[0.98]` — microinteraction klik
- ✅ `focus-visible:ring-2` — keyboard navigation accessible
- ✅ `disabled` state: opacity-50 + cursor-not-allowed

### Badge
- ✅ 7 tone: slate, green, amber, blue, red, teal, purple
- ✅ `dot` prop untuk status indicator kecil
- ✅ `ring-1` subtle border untuk contrast

### Modal/ConfirmDialog
- ✅ Blur backdrop — depth perception
- ✅ Body scroll locked on mount
- ✅ Close on Esc key + click outside
- ✅ Danger mode: AlertTriangle banner merah
- ⚠️ Modal tidak ada animasi enter/exit — transisi abrupt

### Field/Input
- ✅ Focus ring `ring-2 ring-brand`
- ✅ `hint` prop untuk help text
- ✅ Error state dengan ikon ⚠

### StatCard
- ✅ Accent bar warna per tone
- ✅ Ikon + value + label + sub description
- ⚠️ Tidak ada skeleton loading state saat data belum tersedia

---

## 4. RESPONSIVENESS

| Halaman | Mobile (< 640px) | Tablet (640-1024px) | Desktop |
|---------|-----------------|---------------------|---------|
| Login | ✅ Single column | ✅ Single column | ✅ Split |
| Dashboard | ✅ Stacked cards | ✅ 2 kolom | ✅ 4 kolom |
| Operator | ✅ Stacked | ✅ OK | ✅ Side by side |
| Supervisor | ⚠️ Table overflow | ⚠️ Partial | ✅ OK |
| Queue History | ✅ Stacked | ✅ OK | ✅ OK |

---

## 5. ACCESSIBILITY

**Temuan:**
- ✅ Tombol memiliki `aria-label` untuk icon-only buttons
- ✅ `role="dialog" aria-modal="true"` pada Modal
- ✅ Color tidak satu-satunya indikator — selalu ada teks/ikon pendamping
- ⚠️ Tidak ada skip-to-content link untuk keyboard navigation
- ⚠️ Form error tidak menggunakan `aria-describedby` — screen reader mungkin tidak membacanya

---

## 6. FEEDBACK & NOTIFIKASI

**Temuan:**
- ✅ `Loading`, `ErrorState`, `EmptyState` komponen tersedia dan digunakan
- ✅ ConfirmDialog untuk aksi destruktif
- ⚠️ Toast notification tidak terlihat ada implementasinya — berhasil/gagal hanya kadang ditampilkan via ErrorState
- ⚠️ Beberapa mutation tidak ada success feedback — user tidak tahu apakah aksi berhasil

---

## 7. KESIMPULAN UI/UX

| Aspek | Nilai | Catatan |
|-------|-------|---------|
| Visual design | 8/10 | Konsisten, modern |
| Component library | 8/10 | Solid, beberapa komponen kurang |
| Responsiveness | 7/10 | Supervisor page bermasalah di mobile |
| Accessibility | 6/10 | Dasar ada, tapi belum WCAG 2.1 AA |
| Loading/error states | 7/10 | Tidak semua halaman konsisten |
| Feedback mechanism | 6/10 | Tidak ada toast global |
| **TOTAL** | **7.3/10** | |
