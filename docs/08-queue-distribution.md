# Queue Distribution Algorithm Specification

> Diperbarui 31 Oktober 2026 sesuai implementasi (`QueueDistributionEngine`, `*Strategy`).

Interface: `QueueDistributionStrategy` — `chooseQueue(Collection): ?Queue` dan `chooseEmployee(Collection, ?DistributionCursor): ?Employee`. Implementasi: `RoundRobinStrategy` (pemilihan petugas), `PriorityQueueStrategy` (pemilihan antrian), `ManualAssignmentStrategy`.

## Kandidat petugas
Aktif (tidak terhapus), cabang sama, berkompeten pada layanan, status terkini `AVAILABLE` dengan heartbeat belum basi (`queue_settings.stale_heartbeat_seconds`, default 90 dtk), tidak punya assignment/session aktif, dan memiliki loket aktif (tidak terhapus) yang tidak sedang dipakai. Baris petugas dan cursor dikunci (`FOR UPDATE`) dalam transaksi.

## Round Robin
Cursor di `distribution_cursors(branch_id, service_type_id, last_employee_id)`. Kandidat diurutkan stabil (`id`), dipilih yang tepat setelah petugas terakhir; cursor diperbarui bersama assignment. Tanpa kandidat → antrian tetap `WAITING`.

## Pemilihan antrian
Skor = `bobot + floor(lama_tunggu / aging_interval) × aging_rate`. Urutan: skor tertinggi, lalu `created_at`, lalu **`sequence_no`** (tie-breaker deterministik). Estimasi posisi pada pelacakan memakai fungsi skor yang sama.

> **Belum diimplementasikan:** kuota fairness untuk antrian normal (`normal_fairness_quota` ada di tabel tetapi tidak dibaca). Mencegah starvation saat ini mengandalkan aging; bobot prioritas yang sangat besar terhadap aging dapat menunda antrian normal lebih lama dari yang diinginkan — atur `weight` dan `aging_interval_seconds` dengan hati-hati.

## Pemicu distribusi
`assignNext(branch, layanan)` dipanggil setelah pendaftaran. `assignPending(branch)` (semua layanan aktif cabang, maks. 50 assignment) dipanggil setelah selesai/lewati/tidak hadir/batal, saat petugas menjadi AVAILABLE, dan pada heartbeat petugas AVAILABLE.

## Atomisitas
Transaksi: kunci antrian `WAITING` → kunci petugas & loket → insert assignment → status `ASSIGNED` → log status petugas `BUSY` → transisi & event. Event dikirim setelah commit. Partial unique index memastikan satu assignment aktif per queue, employee, dan counter sebagai pengaman kedua. Dibuktikan dengan tes paralel (`docs/23`): 8 proses berebut 1 antrian/4 petugas, 1 petugas/6 antrian, 3 petugas berbagi 1 loket.

## Assignment manual
Izin `queues.assign`, alasan wajib, petugas harus kompeten/tersedia, loket bebas dan aktif, antrian `WAITING`; tercatat sebagai `MANUAL` dengan actor. UI: halaman **Kendali Antrian** (`/supervisor`) — hanya petugas yang kompeten, tersedia (heartbeat segar), dan tanpa antrian aktif yang dapat dipilih; loket bebas disarankan otomatis (loket milik petugas bila bebas); alasan wajib. Setelah **transfer**, `assignPending` langsung dijalankan sehingga antrian yang pindah dan petugas yang dibebaskan segera mendapat pasangan.
