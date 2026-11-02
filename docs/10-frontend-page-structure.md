# Frontend Page Structure

> Diperbarui 31 Oktober 2026. React 19 + Vite + Tailwind, React Router, TanStack Query/Table, React Hook Form + Zod, Recharts. (shadcn/ui **tidak** dipakai; komponen UI ditulis sendiri di `src/components/ui`.)

## Route aktual
| Route | Akses | Halaman |
|---|---|---|
| `/login` | publik | Masuk |
| `/register` | publik (online); staf berizin melihat toggle Walk-in | Pendaftaran antrian + tiket cetak |
| `/tracking` | publik | Lacak/batalkan antrian via token (`#token=` atau tiket terakhir di perangkat) |
| `/display` | perangkat DISPLAY | Central Display (aktivasi lewat `#key=` atau input kunci) |
| `/kiosk` | perangkat KIOSK | Kiosk pengambilan nomor |
| `/` | login | Dashboard (operator CS/Teller langsung diarahkan ke `/operator`) |
| `/operator` | `queues.call` | Dashboard Petugas |
| `/supervisor` | `queues.assign` | **Kendali Antrian**: papan antrian & petugas; dialog assign manual, transfer, verifikasi prioritas |
| `/queues` | `queues.view` | Monitoring antrian |
| `/branches` | `branches.manage` | Master cabang |
| `/employees`, `/counters`, `/services` | `*.view` (ubah: `*.manage`) | Master petugas/loket/layanan |
| `/queue-settings` | `settings.manage` | Jam operasional, hari libur, kuota, ambang |
| `/display-devices` | `displays.manage` | Perangkat display & kiosk, pengumuman, suara |
| `/reports` | `reports.view` | Laporan & ekspor CSV |

Menu sidebar dan route guard mengikuti `permissions` dari `/auth/me`; backend tetap penjaga utama. **Di bawah 1024 px sidebar diganti laci menu** (tombol ☰; tertutup otomatis saat pindah halaman, ketuk latar, tombol X, atau Esc; scroll halaman terkunci selagi terbuka).

## Belum ada (dari rancangan awal)
`/priorities` (CRUD kategori prioritas), `/audit` (penampil audit log; API tersedia), UI ubah status petugas oleh supervisor (API tersedia), `/settings` umum. Tidak ada tes komponen/e2e otomatis (hanya 17 unit test logika; uji visual dilakukan manual).

## Pola
- **MasterDataPage** (`src/pages/master/`): satu kerangka generik berkonfigurasi untuk empat master — tabel TanStack server-side (pencarian, filter, sort, pagination), form modal RHF+Zod dengan pemetaan galat 422 ke field, dialog konfirmasi untuk nonaktifkan/hapus/pulihkan.
- Echo memakai `channelAuthorization.headersProvider` (bukan `auth.headers`): token terbaru dibaca pada setiap otorisasi channel.
- Server state lewat TanStack Query; invalidasi mengikuti event Reverb; reconnect melakukan refetch.
- Sesi staf: `sqms_token` + `sqms_token_meta` (waktu terbit/kedaluwarsa); token diperpanjang otomatis saat sisa umur < 50% (dicek tiap 2 menit dan saat tab kembali aktif). Token kedaluwarsa → 401 → `/login?expired=1` dengan pesan "Sesi Anda telah berakhir".
- Token staf (`sqms_token`), display (`sqms_display_token`), dan kiosk (`sqms_kiosk_token`) disimpan terpisah; klien Echo dibuat per-token dan di-reset saat login/logout.
- Tata letak diverifikasi di 390 px (ponsel), 820 px (tablet), 1024×768 (kiosk), 1366×860 (admin), 1920×1080 (TV, tanpa scroll; dengan atau tanpa panel video).
- **Panel video TV** (`components/display/MediaPanel.tsx`, logika murni di `lib/media.ts`): failover antar sumber, pemutar tersembunyi untuk mengecek ulang sumber prioritas, `hls.js` dimuat malas hanya bila ada stream `.m3u8`. Interval dapat diubah saat build: `VITE_MEDIA_PROBE_SECONDS` (bawaan 180), `VITE_MEDIA_RETRY_SECONDS` (bawaan 30).
- **Cetak tiket:** CSS `@media print` + `@page { size: 80mm 100mm }` (kertas termal 80 mm); nomor 44 pt satu baris, teks panjang dipotong agar tetap satu halaman.
