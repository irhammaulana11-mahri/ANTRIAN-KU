# Queue Distribution Test Review

## Implementasi aktual

- Cursor tersimpan per `branch_id + service_type_id`.
- Candidate employee dibatasi branch, kompetensi, status AVAILABLE, heartbeat, assignment aktif, dan session aktif.
- Counter dipilih dari relasi employee-counter pada branch yang sama.
- Assignment dan status BUSY berada dalam transaksi dengan row lock.
- Partial unique index menjaga satu assignment aktif per queue, employee, dan counter.
- Service session dibuat ketika queue masuk SERVING dan diselesaikan pada terminal status.
- Priority memakai weight dan aging interval/rate.

## Test aktual

- Round Robin break/busy/competency: PASS.
- Cursor berpindah setelah completion: PASS.
- Manual assignment dan counter availability: PASS.
- Branch isolation: PASS.
- Unique guard active assignment/session: PASS.
- Fallback cursor ketika employee sebelumnya tidak eligible: PASS (unit test baru).

Concurrency PostgreSQL nyata belum PASS karena test suite saat ini memakai SQLite in-memory dan koneksi PostgreSQL aplikasi belum dikonfigurasi. Jalankan concurrency suite pada PostgreSQL testing database sebelum production approval.
