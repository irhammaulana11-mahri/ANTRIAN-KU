# 24. Daftar Periksa Uji pada Perangkat Nyata

Bagian yang **tidak dapat dibuktikan secara otomatis/headless** dan perlu dicoba sekali pada perangkat produksi (TV, kiosk, printer, ponsel).
Bagian yang sudah diverifikasi otomatis dicantumkan agar jelas apa yang tidak perlu diulang.

## Sudah diverifikasi (31 Okt – 1 Nov 2026, Chrome headless + Nginx sungguhan, data uji terisolasi)
- Teks pengumuman dan urutannya: *"Nomor antrian C S nol nol satu, Andi Pratama, silakan menuju Loket L satu."* dengan `lang="id-ID"`, chime dibunyikan sebelum ucapan, panggilan ulang diumumkan lagi.
- Cetak tiket ke PDF dengan media cetak: satu halaman 80×100 mm, nomor satu baris, QR, hitam-putih; nama 129 karakter dipotong (tetap satu halaman).
- Video TV: failover antar sumber (stream gagal → cadangan; YouTube rusak → cadangan), YouTube sungguhan tersambung, kembali ke sumber utama setelah hidup, pulih dari kondisi semua sumber mati.
- Menu navigasi ponsel/tablet (laci), CSP pada seluruh halaman termasuk `/display` dengan video.

## A. Suara pengumuman (TV Display) — **wajib diuji di perangkat TV**
Chrome/Chromium di mesin pengembangan melaporkan **0 suara**, sehingga kualitas dan keberadaan suara `id-ID` tidak dapat dipastikan di sana.
1. Buka `/display#key=…` di browser TV. Buka ikon pengaturan (roda gigi) di kanan atas: baris status suara harus **hijau** — *"Suara Bahasa Indonesia terdeteksi: … (id-ID)"*. Bila **kuning**, perangkat tidak punya suara Indonesia: pasang paket suara Text-to-speech Bahasa Indonesia (Android TV: *Settings → Accessibility → Text-to-speech → Google TTS → Bahasa Indonesia*; Windows: *Settings → Time & language → Speech → Add voices*; Linux: speech-dispatcher + `espeak-ng`/suara id), lalu muat ulang.
2. Ketuk banner kuning **"Ketuk untuk mengaktifkan suara pengumuman"** (kebijakan autoplay browser mewajibkan satu ketukan/klik).
3. Tekan **Tes suara**: harus terdengar bunyi dua nada lalu kalimat. Atur **Volume** dan **Kecepatan** hingga jelas dari jarak tempat tunggu.
4. Dari Dashboard Petugas: panggil satu antrian, lalu **Panggil ulang**. Pastikan: kalimat benar (nomor dieja per angka, nama, loket), chime terdengar, panggilan ulang diumumkan lagi, dan tidak ada pengumuman ganda.
5. Dengan dua panggilan beruntun cepat: pengumuman diantrikan (tidak saling menimpa).
6. Matikan jaringan TV ±1 menit lalu hidupkan: setelah tersambung kembali, **tidak boleh** ada pengumuman lama yang diputar ulang, dan layar menampilkan data terbaru.

## B. Video TV (IPTV/YouTube)
1. Admin → *Perangkat Display/Kiosk* → *Video di layar TV*: isi sumber 1 (IPTV `.m3u8` di jaringan lokal) dan sumber 2 (link YouTube) → Simpan.
2. Muat ulang `/display`: video tampil di panel kanan, **tanpa suara**. Keterangan kecil di pojok menunjukkan sumber aktif (`· sumber cadangan` bila bukan sumber pertama).
3. Putuskan kabel/stream IPTV: dalam ±15–25 detik display pindah ke YouTube. Pulihkan IPTV: dalam beberapa menit (default 3) kembali ke IPTV.
4. IPTV `http://` pada jaringan lokal: pastikan server stream mengirim header **CORS** (`Access-Control-Allow-Origin`) — tanpa itu pemutar HLS di browser tidak dapat memuat segmen (MP4 langsung tidak butuh CORS).
5. YouTube: link yang diterima = video, siaran langsung, shorts, playlist, atau kanal berformat `https://www.youtube.com/channel/UC…` (memutar playlist "semua unggahan" kanal). Link `@nama` ditolak dengan pesan panduan — buka kanal, lalu ambil ID kanal (`UC…`) dari kode sumber halaman atau dari "Bagikan → Salin ID kanal" di YouTube Studio.
6. Ketahanan pemutar YouTube: bila pemutar menjawab tetapi tidak mulai memutar (mis. "Video unavailable"), aplikasi mendorong pemutaran, lalu **memuat ulang pemutar yang sama hingga 3 kali secara diam-diam** sebelum menyatakan gagal; video yang pemiliknya menonaktifkan penyematan (kode 101/150) pada playlist dilompati, pada video tunggal memicu pindah ke sumber berikutnya. Penyebab kegagalan ditampilkan kecil pada panel "Video tidak tersambung" dan pada atribut `data-media-reason`.

## C. Cetak tiket (kiosk & loket depan) — **wajib diuji dengan printer termal**
1. Pasang printer termal 80 mm sebagai printer default perangkat kiosk; jalankan Chrome dengan `--kiosk-printing` bila ingin cetak tanpa dialog.
2. Ambil nomor di `/kiosk`, tekan **Cetak tiket** (atau centang *Cetak tiket otomatis*).
3. Periksa: satu lembar, tidak terpotong, **nomor satu baris**, QR terbaca ponsel, tidak ada margin berlebih, tidak ada kertas kosong panjang. CSS memakai `@page { size: 80mm 100mm; margin: 3mm }` — bila driver memakai gulungan menerus, sesuaikan tinggi halaman di `frontend/src/styles.css`.
4. Pindai QR dengan ponsel: harus membuka halaman pelacakan dengan status antrian.

## D. Navigasi ponsel
Buka aplikasi di ponsel (≤ 1023 px): tombol ☰ tampil di kiri atas; menu terbuka sebagai laci, menutup saat memilih menu / ketuk latar / tombol X / Esc.

## E. Jaringan & WebSocket
- Daftarkan domain/IP TV ke `REVERB_ALLOWED_ORIGINS` (`backend/.env`), restart Reverb. Gejala bila belum: status **Terputus** permanen dan kode 4009 di log Reverb.
- Setelah deploy Nginx: konsol browser pada `/login`, `/operator`, `/display`, `/kiosk`, `/reports` harus bebas pelanggaran CSP.
