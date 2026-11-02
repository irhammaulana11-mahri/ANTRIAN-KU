# Fase 3 — Queue Management

## Endpoint

| Method | Endpoint | Akses |
|---|---|---|
| POST | `/api/v1/queues/walk-in` | kiosk/client; token dikembalikan sekali |
| POST | `/api/v1/queues/online` | customer; token dikembalikan sekali |
| GET | `/api/v1/queues/track/{token}` | secure token |
| POST | `/api/v1/queues/cancel-by-token` | secure token |
| GET | `/api/v1/queues/history` | `queues.view` |
| PATCH | `/api/v1/queues/{queue}/status` | `queues.assign` |
| POST | `/api/v1/queues/{queue}/verify-priority` | `queues.assign` |
| POST | `/api/v1/queues/{queue}/transfer` | `queues.transfer` |
| POST | `/api/v1/queues/{queue}/cancel` | `queues.cancel` |

## Numbering dan concurrency

Pendaftaran berada dalam `DB::transaction(..., 5)`. Generator menjalankan `INSERT ... ON CONFLICT DO NOTHING` melalui `insertOrIgnore`, kemudian mengambil row sequence menggunakan `lockForUpdate()`, menaikkan `last_number`, dan membuat queue dalam transaction yang sama. Scope sequence adalah `branch_id + service_type_id + queue_date`; unique constraint queue menjadi guard kedua.

Transfer juga mengambil sequence target dengan mekanisme yang sama sehingga nomor baru tidak bertabrakan. PostgreSQL menjalankan row locking dan partial unique index assignment/session secara penuh.

## Prioritas dan keamanan token

Customer hanya dapat mendaftar Normal. Priority/Prioritas/Urgent memerlukan endpoint verifikasi oleh actor dengan permission `queues.assign`. Token customer dibuat dari 48 random bytes; hanya hash SHA-256 yang disimpan pada database. Token plaintext dikembalikan pada response pendaftaran dan tidak disimpan di resource queue.

## State transition

```text
WAITING -> ASSIGNED -> CALLED -> SERVING -> COMPLETED
WAITING -> CANCELLED
ASSIGNED -> WAITING | CANCELLED | NO_SHOW
CALLED -> CALLED | SERVING | SKIPPED | CANCELLED
SERVING -> COMPLETED | SKIPPED | NO_SHOW
```

Setiap perubahan dicatat pada `queue_transitions`. Status terminal tidak dapat ditransfer atau diubah kembali.

## Testing

```bash
vendor/bin/phpunit
```

Test mencakup walk-in, online, numbering berurutan, secure tracking, cancellation, priority verification, transfer, status transition, branch isolation, Sanctum login, dan formatter nomor. Test menggunakan SQLite untuk kecepatan; migration mempertahankan constraint PostgreSQL production dan menjalankan SQL constraint PostgreSQL hanya pada driver PostgreSQL.
