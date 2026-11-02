# Fase 4 — Queue Distribution Engine

## Komponen

- `QueueDistributionStrategy`: kontrak strategi distribusi.
- `RoundRobinStrategy`: pemilihan petugas mengitari `distribution_cursors`.
- `PriorityQueueStrategy`: pemilihan queue berdasarkan weighted priority + aging.
- `ManualAssignmentStrategy`: strategi untuk assignment supervisor.
- `QueueDistributionEngine`: transaction boundary, eligibility, locking, assignment, dan release.
- `EmployeeStatusService`: sumber status operasional dari `employee_status_logs`.

## Eligibility petugas

Petugas hanya kandidat jika seluruh kondisi berikut benar:

1. `employees.is_active = true`.
2. `employee_services` memiliki kompetensi service type queue.
3. Status terakhir adalah `AVAILABLE`.
4. `heartbeat_at` belum melewati `queue_settings.stale_heartbeat_seconds`.
5. Tidak mempunyai `queue_assignments` aktif.
6. Tidak mempunyai `service_sessions` aktif.
7. Memiliki minimal satu counter aktif melalui `employee_counters`.
8. Counter tersebut tidak mempunyai assignment aktif.
9. Seluruh entity memiliki `branch_id` yang sama.

Status `BUSY`, `BREAK`, `OFFLINE`, `CLOSED`, heartbeat stale, dan petugas tanpa counter eligible otomatis ditolak.

## Algoritma weighted priority + aging

```text
score(queue) = priority.weight
             + floor(wait_seconds / priority.aging_interval_seconds)
               * priority.aging_rate
```

Queue dengan score terbesar dipilih. Jika score sama, queue paling lama dibuat dipilih. Karena kategori Normal juga memperoleh aging, customer normal tetap memiliki kesempatan pelayanan dan tidak mengalami starvation.

## Algoritma Round Robin

1. Lock seluruh queue `WAITING` pada branch + service menggunakan `FOR UPDATE`.
2. Pilih queue melalui `PriorityQueueStrategy`.
3. Lock kandidat employee eligible secara deterministic berdasarkan `employee.id`.
4. Lock row `distribution_cursors` untuk branch + service.
5. Pilih employee setelah `last_employee_id`; jika mencapai akhir, kembali ke kandidat pertama.
6. Lock counter aktif pertama berdasarkan `counter_code`.
7. Insert assignment, ubah queue menjadi `ASSIGNED`, catat transition, dan ubah status petugas menjadi `BUSY` dalam transaction yang sama.
8. Commit baru mengakhiri critical section.

Row lock queue mencegah dua worker mengambil queue yang sama. Partial unique index pada `queue_assignments` dan `service_sessions` menjadi database guard tambahan.

## Lock ordering

```text
queue rows -> employee rows -> distribution cursor -> counter row -> assignment/status/transition writes
```

Semua jalur auto dan manual memakai transaction dengan retry deadlock sebanyak lima kali. Branch dan service selalu divalidasi sebelum assignment.

## Manual assignment

Supervisor memakai `POST /api/v1/queues/{queue}/assign` dengan `employee_id`, `counter_id`, dan `reason`. Engine tetap memeriksa kompetensi, status AVAILABLE, heartbeat, assignment aktif, session aktif, counter aktif, dan branch isolation. Manual assignment tidak melewati invariant engine.

## Trigger otomatis

- Setelah walk-in/online registration, engine mencoba mengambil queue berikutnya.
- Setelah queue mencapai `COMPLETED`, assignment ditutup, petugas kembali `AVAILABLE`, lalu engine mencoba queue berikutnya untuk branch + service yang sama.
- Jika tidak ada petugas atau counter eligible, queue tetap `WAITING`.

## Simulasi distribusi

Asumsi: tiga petugas kompeten CS, tiga counter, semua AVAILABLE, cursor awal kosong.

| Event | Queue terpilih | Petugas | Cursor setelah commit |
|---|---|---|---|
| 1 | CS-001 Normal | CS-01 | CS-01 |
| 2 | CS-002 Normal | CS-02 | CS-02 |
| 3 | CS-003 Priority | CS-03 | CS-03 |
| 4 | CS-004 Normal | CS-01 | CS-01 |

Jika CS-02 menjadi BREAK sebelum event 5, urutan kandidat menjadi CS-03 lalu CS-01; engine melewati CS-02 tanpa menghapus cursor atau memindahkan queue secara paksa. Jika seluruh kandidat BUSY/BREAK/OFFLINE atau counter penuh, queue tetap WAITING dan dapat dicoba ulang oleh worker/operator.

## Test coverage

`QueueDistributionEngineTest` membuktikan:

- Round Robin dan skip BREAK/BUSY.
- Kompetensi service.
- Auto-assignment setelah completion.
- Manual assignment dan counter availability.
- Multi-branch isolation.
- Satu employee tidak memiliki dua assignment aktif.
- Partial unique index mencegah dua service session aktif pada employee yang sama.

Migration tambahan yang diperlukan adalah [`2026_10_30_000006_create_distribution_cursors_table.php`](../database/migrations/2026_10_30_000006_create_distribution_cursors_table.php). Tidak ada perubahan pada tabel transaksi Fase 1.
