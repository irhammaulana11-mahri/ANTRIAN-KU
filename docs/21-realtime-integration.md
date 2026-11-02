# Fase 6 — Real-Time Integration

## Komponen

- Laravel Reverb menerima koneksi WebSocket pada `REVERB_HOST:REVERB_PORT`.
- Laravel Echo + Pusher protocol digunakan oleh React.
- Event domain (`queue.created`, `queue.assigned`, `queue.called`, `queue.completed`, `queue.status-changed`, `employee.status-changed`, dan `display.updated`) dikirim ke private channel `branch.{branch_id}`.
- Event yang terkait petugas juga dikirim ke `employee.{employee_id}`.
- Otorisasi channel menggunakan Sanctum dan memeriksa `branch_id`; super admin dapat mengakses seluruh cabang.

## Menjalankan

```bash
php artisan migrate
php artisan reverb:start
php artisan queue:work
```

Salin `frontend/.env.example` menjadi `frontend/.env` dan samakan key, host, port, serta scheme Reverb dengan `.env` backend. Jalankan frontend dengan `npm run dev`.

## Commit boundary

Semua event mengimplementasikan `ShouldDispatchAfterCommit`. Service queue, assignment, dan employee status hanya melakukan `dispatch()` di dalam transaksi; Laravel menahan broadcast sampai transaksi berhasil commit. Rollback tidak menghasilkan event ke dashboard atau display.

## Recovery dan deduplikasi

React menyimpan `event_id` dan timestamp terakhir melalui `DisplayEventGuard`. Event dengan ID sama tidak diproses dua kali. Setelah koneksi tersambung kembali, client mengambil snapshot queue `CALLED` dari API, menandai timestamp snapshot, lalu hanya menerima event baru. Snapshot juga mengisi ulang TanStack Query sehingga dashboard tidak bergantung pada urutan event yang terlewat.

## Audio Bahasa Indonesia

Central Display memakai `speechSynthesis` dengan locale `id-ID`. Browser mewajibkan aktivasi audio oleh pengguna; tombol suara mengaktifkan antrean audio. Event diproses serial, sehingga satu panggilan selesai sebelum panggilan berikutnya diputar.

## Pengujian

```bash
vendor/bin/phpunit
cd frontend && npm run test
```

Test frontend memastikan duplicate event dan event lama sesudah reconnect tidak diputar. Test backend memastikan kontrak event memakai `ShouldDispatchAfterCommit`.
