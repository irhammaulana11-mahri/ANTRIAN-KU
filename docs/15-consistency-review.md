# Blueprint Consistency Review

## A. Review blueprint awal (sebelum coding)

| Risiko | Mitigasi desain |
|---|---|
| Nomor duplikat saat concurrent registration | unique composite + `queue_sequences` row lock + transaction + test paralel |
| Double assignment / dua sesi petugas | lock queue/employee/counter, active partial unique index, state check |
| Data antar cabang tercampur | branch context server-side, policy/query scope, FK consistency, IDOR tests |
| Prioritas starvation | weighted aging, fairness window/quota normal, score audit |
| Event sebelum commit / event hilang | `ShouldDispatchAfterCommit` dan outbox dengan retry idempotent |
| Display memutar panggilan lama/duplikat | event ID/version, dedupe client, snapshot on reconnect, expiry |
| Browser petugas disconnect | heartbeat stale flag; tidak auto-complete session; recovery/reassign eksplisit |
| Counter/employee melayani dua customer | lock + unique active assignment/session |
| Laporan durasi salah | hitung dari `service_sessions.started_at/completed_at`, bukan status terakhir |

## B. Review implementasi (31 Oktober 2026)

Temuan nyata selama pengembangan dan pengujian — semuanya sudah diperbaiki dan memiliki tes pengaman:

| Temuan | Dampak | Perbaikan |
|---|---|---|
| Endpoint walk-in publik | Siapa pun dapat melewati jam operasional & kuota online | Walk-in hanya staf berizin atau perangkat kiosk |
| Cek duplikat telepon sebelum kunci sequence | 6 pendaftaran serentak lolos semua (terbukti tes paralel PostgreSQL) | Cek dilakukan setelah baris sequence terkunci |
| `/broadcasting/auth` memakai middleware session | Channel privat tidak pernah terotorisasi dengan bearer token | Sanctum; channel mengenali token perangkat |
| Zona waktu aplikasi UTC | Nomor antrian & "hari ini" ganti pukul 07.00 WIB | `config/app.php` → `APP_TIMEZONE=Asia/Jakarta` |
| Heartbeat memanggil `setStatus(AVAILABLE)` | Petugas BREAK kembali tersedia sendiri | Heartbeat hanya memperbarui detak |
| Petugas non-supervisor dapat mengubah status antrian siapa pun di cabangnya | Pelanggaran least privilege | Hanya antrian yang dialokasikan kepadanya |
| `PATCH status=ASSIGNED` membuat antrian "ASSIGNED" tanpa assignment | Antrian macet/tak dapat dipanggil | `ASSIGNED` hanya dari mesin distribusi; `ASSIGNED→WAITING` melepas assignment |
| Query mentah mengabaikan `deleted_at` | Loket terhapus masih ter-assign; cabang terhapus masih menerima pendaftaran; petugas terhapus masih bisa login | `whereNull('deleted_at')` + cek login memakai `withTrashed`; diuji |
| Edit petugas tidak mengubah akun | Email/role/password diabaikan diam-diam | `updateEmployee` mengelola akun; reset password/penonaktifan mengakhiri sesi |
| Kode duplikat → error 500 | UX buruk, noise log | Validasi unique per cabang (termasuk data terhapus) |
| `LIKE … ESCAPE '\'` pada PostgreSQL | Pencarian 500 hanya di PostgreSQL (lolos di SQLite) | Karakter escape `!`; diuji langsung di PostgreSQL |
| Admin Cabang tanpa `branches.view` | Daftar cabang 403 | Izin ditambahkan (tetap terbatas cabang sendiri) |
| Login gagal memuat ulang halaman (interceptor 401) | Pesan galat tidak pernah tampil | Interceptor mengecualikan `/auth/login` |
| `transfer()` tidak menutup sesi layanan bila antrian sedang dilayani | Petugas tertahan "melayani" (sesi aktif tersisa) setelah antrian dipindahkan | Sesi ditutup dengan outcome `TRANSFERRED`; diuji (mutation check) |
| Setelah transfer tidak ada distribusi | Antrian yang pindah dan petugas yang dibebaskan menunggu pemicu berikutnya | `assignPending` langsung dijalankan; diuji |
| Opsi `auth.headersProvider` Echo diabaikan pusher-js (hanya `params`/`headers` yang disalin dari `auth`) | Otorisasi channel dikirim **tanpa token** setelah perubahan header dinamis — **regresi tertangkap saat uji browser**, bukan oleh unit test | Memakai `channelAuthorization.headersProvider`; diverifikasi dengan menangkap permintaan nyata |
| Token staf tanpa masa berlaku | Token curian berlaku selamanya | Kedaluwarsa per-token 12 jam + perpanjangan otomatis; token lama diberi batas oleh migrasi |
| Login tidak diaudit | Tidak ada jejak percobaan masuk/brute force | `auth.login`, `auth.login_failed`, `auth.login_blocked`, `auth.logout` (tanpa menyimpan password/token) |
| Tanpa CSP | XSS lebih berdampak | CSP ketat di Nginx (SPA) dan Laravel (API/Swagger); diverifikasi di browser |
| Sidebar hilang di layar < 1024 px (`hidden lg:block` tanpa pengganti) | Tidak ada navigasi sama sekali di ponsel/tablet | Laci menu ☰; diuji di 390/820/1366 px (buka/tutup, pilih menu, Esc, ketuk latar) |
| Tiket cetak: nomor terpecah ("CS-" / "004") pada lebar 80 mm; halaman default Letter; latar ikut tercetak | Tiket tidak layak pakai pada printer termal — **tertangkap lewat render PDF**, tidak terlihat di layar | CSS cetak: `@page 80×100 mm`, nomor 44 pt tanpa bungkus, teks panjang dipotong, hitam-putih, tanpa latar |
| `Page.printToPDF`/uji cetak menunjukkan tiket 2 halaman bila nama sangat panjang | Dua lembar tiket | `max-height`, ellipsis, `break-inside: avoid`; diuji dengan nama 129 karakter |
| Pemutar YouTube: pesan "listening" hanya dikirim sekali saat iframe dimuat | Kadang pemutar tak pernah melapor status → sumber dianggap gagal padahal bisa jalan (± setengah pemuatan dingin gagal) — **tertangkap lewat uji ulang berulang di browser** | Pesan dikirim berulang (400 ms) sampai pemutar menjawab, seperti IFrame API resmi |
| Playlist YouTube kadang gagal dimuat sesaat (status -1, `video_id=videoseries`, "Video unavailable" menunggu klik Skip) | Panel "tidak tersambung" ± 10–25% pemuatan dingin | Dorong pemutaran, lalu muat ulang pemutar yang sama diam-diam hingga 3 kali; playlist melompati video yang tidak dapat disematkan; alasan kegagalan ditampilkan |
| Link kanal YouTube (`/@nama/videos`) ditolak validasi | Admin tidak bisa memakai link yang wajar | `/channel/UC…` dipetakan ke playlist semua unggahan (`UU…`); `@nama` diberi pesan panduan |
| Layout display melebihi 1080p; riwayat menggandakan panggilan ulang | Perlu scroll di TV; riwayat mubazir | Layout satu layar; riwayat satu baris per antrian |
| Mode registrasi staf salah default | Staf mendaftar sebagai online tanpa sadar | Mode mengikuti status staf setelah user termuat |

## C. Penyimpangan blueprint yang disengaja
- **Outbox/idempotency key tidak diimplementasikan.** Pengiriman setelah commit memakai `ShouldDispatchAfterCommit`; duplikasi klik ditangani state machine + lock (409). Tabel dicadangkan. Konsekuensi: bila worker mati tepat setelah commit, event real-time dapat hilang — **dipulihkan oleh snapshot REST** (display poll 30 dtk, reconnect) tetapi tidak ada retry broadcast terjamin.
- **Struktur kode berlapis**, bukan `app/Domain/*` (lihat `docs/11`).
- **shadcn/ui tidak dipakai**; komponen sendiri.
- **Kuota fairness** belum dipakai (aging saja).

## D. Risiko terbuka
1. MFA belum ada; audit belum dikunci di database; `REVERB_ALLOWED_ORIGINS` untuk perangkat nyata belum diatur (`docs/12`).
2. Suara `id-ID` dan cetak tiket belum diuji pada perangkat sebenarnya; headless Chrome tidak punya voice.
3. Belum ada uji beban; performa p95 belum diukur.
4. Estimasi tunggu memakai rata-rata sederhana (bukan model antrian); akurat setelah ada data layanan nyata.
5. Race condition diuji probabilistik pada PostgreSQL; tidak dapat dibuktikan secara formal.
6. Redis/multi-instance Reverb belum diuji (pengembangan memakai driver `database`).

## E. Keputusan yang masih perlu ditetapkan pemilik produk
SLA concurrency dan jumlah cabang/petugas bersamaan; retention PII; kebijakan kuota online vs walk-in (saat ini: kuota hanya untuk online); konfigurasi fairness prioritas; provider notifikasi eksternal (belum ada notifikasi).

## Kesimpulan
Desain inti (modular monolith, PostgreSQL, lock + constraint, Reverb) terbukti berjalan, termasuk di bawah uji paralel. Sebelum pilot: tutup risiko keamanan butir D.1, uji perangkat nyata (D.2), dan jalankan uji beban (D.3).
