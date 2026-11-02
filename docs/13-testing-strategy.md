# Testing Strategy

> Diperbarui 31 Oktober 2026. Cara menjalankan dan rincian tes concurrency: **`docs/23-testing-and-api-docs.md`**. Hanya hasil yang benar-benar dijalankan yang dilaporkan.

## Hasil terakhir dijalankan (31 Okt 2026)
| Suite | Hasil |
|---|---|
| Backend, PostgreSQL (schema `sqms_test`) | **132 lulus**, 1598 assertion (termasuk 11 tes concurrency) |
| Backend, SQLite in-memory | 121 lulus, 11 dilewati otomatis (tes concurrency butuh PostgreSQL) |
| Frontend (`tsc`, `vitest`, `vite build`) | tipe bersih; 34 unit test lulus; build berhasil |
| Uji visual manual (Chrome headless via DevTools Protocol) | login, dashboard, master data (+modal), pengaturan, perangkat, operator, registrasi (mobile & staf), tracking, kiosk (alur penuh + QR), display (1080p, panggilan ulang hidup via WebSocket), Swagger UI; **papan supervisor** (login via form, assign manual, transfer, verifikasi prioritas), **perpanjangan token otomatis**, redirect sesi berakhir, dan seluruh halaman utama di bawah **CSP produksi yang ditegakkan** (stack terisolasi di schema uji, tanpa menyentuh data aplikasi) |

## Cakupan
- **Unit:** pemformat nomor, skor prioritas+aging, Round Robin, kontrak event broadcast, aturan validasi form (Zod), pengeja/penyusun kalimat pengumuman, penjaga event display.
- **Feature (HTTP, DB nyata):** auth, otorisasi & isolasi cabang, pendaftaran walk-in/online + aturan jam/libur/kuota/ambang/duplikat telepon, estimasi & pelacakan, siklus penuh petugas (panggil→selesai→assign berikutnya), istirahat/heartbeat, transfer, master data (duplikat, pengaman hapus/nonaktifkan, pulihkan, soft-delete tidak bocor), perangkat display & kiosk (isolasi token, channel, rate limit), pengaturan, laporan, audit, **masa berlaku/perpanjangan token, audit login, CSP, papan supervisor & transfer**, **kesesuaian OpenAPI dengan rute**.
- **Concurrency (PostgreSQL, proses paralel):** penomoran, kuota online, telepon kembar (online & walk-in), satu antrian/petugas/loket tidak ganda, Round Robin serentak, dua petugas selesai bersamaan, klik ganda selesai, batal vs panggil. Terbukti sensitif lewat *mutation check* manual (menghapus `lockForUpdate` membuat tes gagal).

## Pengaman
`tests/CreatesApplication.php` menolak test PostgreSQL di luar schema `*_test` (mencegah `migrate:fresh`/`TRUNCATE` menyentuh data aplikasi).

## Verifikasi di browser dengan Nginx sungguhan (1 Nov 2026)
Stack terisolasi: Nginx asli memakai `deploy/nginx/sqms.conf` (path/upstream disesuaikan), API pada schema `sqms_test`, media uji dibuat dengan ffmpeg. Terverifikasi: CSP per rute (hanya `/display` longgar; API tetap satu header), menu ponsel/tablet, pemutar video (failover, YouTube sungguhan, kembali ke sumber utama, pulih dari semua-mati), pengumuman suara (teks/`lang`/chime/panggilan ulang), cetak tiket ke PDF (80×100 mm, satu halaman). **Tidak dapat diverifikasi:** keberadaan/kualitas suara `id-ID` (Chrome di mesin pengembangan melaporkan 0 suara) dan printer termal fisik — lihat `docs/24-uji-perangkat.md`.

## Belum dicakup
- Uji beban (registrasi/assignment/display), uji keamanan otomatis (pemindai), uji restore backup.
- Integrasi otomatis Reverb ↔ React di browser dan kontrak adaptor SpeechSynthesis. Suara `id-ID` hanya dapat diverifikasi pada browser perangkat nyata; headless Chrome tidak memiliki voice.
- Tes komponen/e2e frontend otomatis (saat ini uji visual manual).
- Validasi OpenAPI dengan validator eksternal.
- Race condition bersifat probabilistik: lulusnya tes membuktikan tidak ada pelanggaran pada skenario yang diuji, bukan ketiadaan semua kemungkinan race.

## Gerbang CI yang disarankan
Formatter/linter, `php artisan test`, `phpunit --configuration phpunit.pgsql.xml`, `tsc`, `vitest`, `vite build`, dan migrasi dari database kosong.
