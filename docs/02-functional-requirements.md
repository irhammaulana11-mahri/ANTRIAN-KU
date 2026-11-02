# Functional Requirements

Status per 31 Oktober 2026: ✅ selesai · ◐ sebagian · ⬜ belum.

| ID | Requirement | Prioritas | Status | Catatan implementasi |
|---|---|---|---|---|
| FR-01 | Login, logout, token expiration, reset password, MFA-ready untuk pengguna internal | Must | ◐ | Login/logout ✅. **Token staf kedaluwarsa 12 jam** (`SANCTUM_STAFF_TOKEN_MINUTES`) dan diperpanjang otomatis selama tab terbuka (`/auth/refresh`); sesi berakhir → login dengan pesan jelas ✅. Token perangkat 7 hari. Reset password oleh admin (mengakhiri semua sesi). ⬜ MFA dan "lupa password" mandiri. |
| FR-02 | CRUD cabang, pegawai, loket, service type, kompetensi, priority type, jam operasional, hari libur | Must | ◐ | Cabang/petugas/loket/layanan: CRUD + nonaktifkan + hapus (soft) + **pulihkan**, pencarian/filter/sort/pagination ✅. Kompetensi & loket petugas ✅. Jam operasional & hari libur ✅. **Priority type: hanya default otomatis + endpoint baca; belum ada CRUD/UI.** |
| FR-03 | Registrasi walk-in dan online dengan validasi jam, kuota, identitas, token status | Must | ✅ | Online: jam, hari libur, kuota harian, ambang menunggu, nomor telepon ganda. Walk-in: staf login atau perangkat kiosk. |
| FR-04 | Nomor atomik per cabang/service/tanggal (transaction + row lock) | Must | ✅ | Diuji dengan 12 pendaftaran paralel. |
| FR-05 | Transisi WAITING→ASSIGNED→CALLED→SERVING→COMPLETED atau terminal valid | Must | ✅ | `ASSIGNED` hanya dibuat mesin distribusi/assign manual. |
| FR-06 | Round robin per cabang, layanan, kompetensi, loket, cursor giliran | Must | ✅ | `distribution_cursors`; dipicu saat daftar, selesai/lewati/batal, dan petugas tersedia. |
| FR-07 | Weighted priority dengan aging tanpa starvation; verifikasi & audit | Must | ◐ | Skor = bobot + aging ✅; verifikator tercatat ✅. **`normal_fairness_quota` ada di tabel tetapi belum dipakai mesin** (aging saja yang mencegah starvation). |
| FR-08 | Dashboard petugas: panggil, recall, mulai, selesai, skip, break, kembali, heartbeat | Must | ✅ | Plus "Tidak hadir", antrian berikutnya, timer. |
| FR-09 | Assignment manual/transfer oleh supervisor dengan alasan dan audit | Must | ✅ | API + UI **Kendali Antrian** (`/supervisor`): papan antrian & petugas, assign manual (petugas tersedia + loket bebas + alasan wajib), transfer layanan (alasan wajib; menutup sesi layanan bila sedang dilayani, lalu distribusi langsung), verifikasi prioritas. |
| FR-10 | Display per cabang, real-time, riwayat, jam, pengumuman, voice id-ID | Must | ✅ | Muat satu layar 1080p. Suara bergantung ketersediaan voice `id-ID` pada perangkat — **panel pengaturan display menampilkan status suara yang terdeteksi** (hijau/kuning). Uji suara pada TV nyata: `docs/24`. |
| FR-11 | Dashboard admin/supervisor dan laporan lintas cabang sesuai permission | Must | ✅ | Ringkasan, per layanan, produktivitas petugas, perbandingan cabang, ekspor CSV. |
| FR-12 | Audit log immutable untuk login, master, assignment, status, prioritas, konfigurasi | Must | ◐ | Login berhasil/gagal/diblokir, logout, master data, pengaturan, perangkat, assignment, status, prioritas ✅ (password/token tidak pernah disimpan). Append-only secara aplikasi; ⬜ belum dikunci trigger database. |
| FR-13 | Device authentication terbatas untuk display dan rotasi/revoke credential | Must | ✅ | Tipe `DISPLAY` dan `KIOSK`; kunci pairing sekali tampil; rotate/revoke. |
| FR-14 | Kiosk walk-in terautentikasi perangkat | Should | ✅ | `/kiosk`: terkunci ke cabang, tanpa klaim prioritas, tiket + QR + cetak. |
| FR-15 | Estimasi waktu tunggu & posisi sadar prioritas | Should | ✅ | Rata-rata durasi layanan 30 hari (batas 1–60 mnt) dan jumlah petugas aktif. |
| FR-16 | Pulihkan data master yang dihapus | Should | ✅ | Filter status "Dihapus" → Pulihkan; hubungan petugas–layanan/loket tidak ikut pulih. |
| FR-17 | Dokumentasi API OpenAPI dan Swagger UI | Should | ✅ | `/api/docs`; kesesuaian dengan rute diuji otomatis. |
| FR-18 | Papan supervisor real-time | Should | ✅ | `GET /supervisor/board` + halaman Kendali Antrian: antrian aktif, status petugas, loket terpakai; pembaruan otomatis (event + polling 6 dtk). |
| FR-19 | Video di layar TV (IPTV/YouTube) dengan failover | Should | ✅ | Admin mengatur hingga 5 sumber berurutan (stream `.m3u8`/MP4 atau link YouTube). Display memakai sumber pertama yang tersambung, pindah otomatis bila gagal, dan mencoba kembali sumber yang lebih prioritas secara berkala; selalu tanpa suara; HLS lewat `hls.js` (dimuat malas). |
| FR-20 | Navigasi responsif untuk ponsel/tablet | Must | ✅ | Laci menu (☰) di bawah 1024 px; sidebar tetap di desktop. |
