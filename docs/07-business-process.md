# Business Process Flow

> Diperbarui 31 Oktober 2026 sesuai implementasi.

## 1. Pendaftaran antrian

```mermaid
flowchart TD
 A[Customer / staf / kiosk memilih cabang & layanan] --> CH{Kanal?}
 CH -- online publik --> B{Libur? Tutup? Di luar jam?<br/>Kuota penuh? Menunggu ≥ ambang?}
 B -- ya --> X[422 + alasan jelas]
 B -- tidak --> C
 CH -- walk-in staf login --> W1{Izin queues.register/assign<br/>& cabang milik staf?}
 W1 -- tidak --> X2[401/403/422]
 W1 -- ya --> C
 CH -- kiosk --> K1[Token perangkat KIOSK<br/>cabang dari perangkat, tanpa prioritas]
 K1 --> C
 C[Normalisasi telepon] --> D[Lock queue_sequence layanan]
 D --> DUP{Telepon sama sudah punya antrian aktif<br/>untuk layanan ini?}
 DUP -- ya --> X
 DUP -- tidak --> E[Buat QUEUE WAITING + token hash + transisi + audit]
 E --> F[Commit]
 F --> G[assignNext untuk layanan]
 G --> H{Petugas AVAILABLE, kompeten,<br/>loket bebas?}
 H -- tidak --> I[Tetap WAITING]
 H -- ya --> J[Assignment atomik → ASSIGNED]
```

- Pendaftaran **online**: menerima token pelacakan; kuota & ambang diperiksa di bawah advisory lock PostgreSQL per cabang agar tidak terlampaui saat serentak.
- **Walk-in** tidak dibatasi jam operasional/kuota online. Prioritas hanya diterima dari staf berizin `queues.assign`.
- Pemeriksaan duplikat telepon dilakukan **setelah** baris sequence terkunci (serialisasi per cabang+layanan+hari).

## 2. Pelayanan oleh petugas

```mermaid
flowchart TD
 S0[OFFLINE] -->|Mulai bertugas| S1[AVAILABLE + heartbeat tiap 20 dtk]
 S1 -->|assignPending| S2[BUSY: antrian ASSIGNED]
 S2 -->|Panggil| C1[CALLED + log panggilan + event display/suara]
 C1 -->|Panggil ulang| C1
 C1 -->|Mulai| SV[SERVING + service_session]
 C1 -->|Lewati / Tidak hadir| T1[SKIPPED / NO_SHOW]
 SV -->|Selesaikan| T2[COMPLETED]
 T1 --> R[Release assignment → petugas AVAILABLE]
 T2 --> R
 R --> A2[assignPending: cari antrian berikutnya semua layanan cabang]
 S1 -->|Istirahat| B[BREAK]
 B -->|Kembali bertugas| S1
```

- Petugas yang masih punya antrian aktif **tidak dapat** istirahat/offline (409).
- Heartbeat tidak mengubah status (BREAK tetap BREAK). Heartbeat basi (> `stale_heartbeat_seconds`) membuat petugas tidak dipilih distribusi, tetapi sesi pelayanannya tidak otomatis ditutup.
- Auto-assign juga dijalankan saat antrian dibatalkan, petugas kembali tersedia, atau heartbeat diterima.
- Klik ganda aksi yang sama ditolak (403/409) karena state machine dan lock, bukan idempotency key.

## 3. Pelacakan dan pembatalan customer
Token → posisi (skor prioritas + aging) → estimasi = ⌈antrian di depan ÷ petugas aktif⌉ × rata-rata durasi layanan (+ ½ layanan berjalan bila ada yang sibuk). Estimasi `null` bila tidak ada petugas aktif. Pembatalan berlaku untuk WAITING/ASSIGNED/CALLED dan membebaskan petugas serta kuota online.

## 4. Central Display dan Kiosk
- Perangkat dipasangkan sekali: admin membuat perangkat (tipe DISPLAY/KIOSK) → kunci pairing tampil sekali → perangkat menukar kunci dengan token 7 hari (diperpanjang otomatis; rotate/revoke mengakhiri akses).
- Display: snapshot REST (sumber kebenaran) + event WebSocket; setelah reconnect ambil snapshot ulang; suara hanya untuk event baru yang belum kedaluwarsa dan belum pernah diproses.
- **Video TV:** daftar sumber berurutan dari pengaturan cabang. Pemutar mencoba sumber 1; "tersambung" = benar-benar mulai memutar (stream: event `playing`; YouTube: status `playing` dari player) dalam 12–15 detik, jika tidak → sumber berikutnya. Semua gagal → panel "Video tidak tersambung" dan ulang dari sumber 1 tiap 30 detik. Selagi memakai cadangan, sumber yang lebih prioritas dicoba diam-diam (pemutar tersembunyi, tanpa suara) tiap 3 menit dan dipakai begitu tersambung. Video selalu bisu agar tidak menutupi pengumuman panggilan.
- Kiosk: layanan → nama (+telepon opsional) → tiket dengan QR pelacakan, cetak opsional, kembali ke awal otomatis (25 dtk / 90 dtk idle).

## 5. Pengelolaan data master
Tambah → ubah → nonaktifkan → hapus (soft) → **pulihkan**. Penonaktifan/penghapusan ditolak (409) bila data sedang dipakai (antrian aktif, loket melayani, petugas melayani, cabang masih punya data turunan). Menonaktifkan/menghapus petugas mengakhiri semua sesi login, menutup status (CLOSED), dan memblokir login. Pemulihan ditolak bila cabang induk masih terhapus; hubungan petugas–layanan/loket harus diatur ulang.
