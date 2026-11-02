# SQMS — Final Database ERD

> Diperbarui 31 Oktober 2026. Perubahan sejak blueprint awal: `display_devices.device_type` (DISPLAY/KIOSK), `queue_settings.display_announcement`, tabel `distribution_cursors` dan `personal_access_tokens` (token perangkat memakai relasi polimorfik ke `display_devices`), serta tabel infrastruktur `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

Semua primary key adalah UUID. Semua tabel transaksi/operasional membawa `branch_id`. Composite foreign key `(entity_id, branch_id)` digunakan pada relasi lintas aggregate untuk mencegah ID dari cabang berbeda dipasangkan.

```mermaid
erDiagram
  branches ||--o{ users : scopes
  branches ||--o{ employees : has
  branches ||--o{ service_types : offers
  branches ||--o{ counters : has
  branches ||--o{ priority_types : configures
  branches ||--o{ queue_settings : owns
  branches ||--o{ operating_hours : sets
  branches ||--o{ holidays : observes
  branches ||--o{ queues : owns
  branches ||--o{ queue_sequences : numbers
  branches ||--o{ queue_assignments : scopes
  branches ||--o{ service_sessions : scopes
  branches ||--o{ employee_status_logs : scopes
  branches ||--o{ queue_call_logs : scopes
  branches ||--o{ queue_transitions : scopes
  branches ||--o{ display_devices : owns
  branches ||--o{ audit_logs : scopes
  branches ||--o{ distribution_cursors : rotates
  branches ||--o{ outbox_messages : publishes
  branches ||--o{ idempotency_keys : scopes
  users ||--o| employees : identifies
  users }o--o{ roles : model_has_roles
  roles }o--o{ permissions : role_has_permissions
  employees }o--o{ service_types : employee_services
  employees }o--o{ counters : employee_counters
  service_types ||--o{ queue_sequences : sequences
  service_types ||--o{ distribution_cursors : round_robin
  users ||--o{ personal_access_tokens : tokens
  display_devices ||--o{ personal_access_tokens : device_tokens
  service_types ||--o{ queues : requested
  priority_types ||--o{ queues : classifies
  queues ||--o{ queue_assignments : assigned
  employees ||--o{ queue_assignments : handles
  counters ||--o{ queue_assignments : serves_at
  queues ||--o{ service_sessions : served
  employees ||--o{ service_sessions : serves
  counters ||--o{ service_sessions : hosts
  queues ||--o{ queue_call_logs : called
  employees ||--o{ queue_call_logs : calls
  counters ||--o{ queue_call_logs : announces
  queues ||--o{ queue_transitions : changes
  users ||--o{ audit_logs : acts
  users ||--o{ queue_transitions : triggers
  users ||--o{ idempotency_keys : owns
```

## Invariant database

- `queue_sequences` unique pada `(branch_id, service_type_id, queue_date)`; generator nomor wajib mengunci row dengan `FOR UPDATE`.
- `queues` unique pada `(branch_id, service_type_id, queue_date, sequence_no)`; nomor publik dibentuk dari prefix + sequence.
- Partial unique index hanya mengizinkan satu assignment aktif per queue, employee, dan counter; hal yang sama berlaku untuk service session.
- FK komposit memastikan service, employee, counter, priority, dan queue selalu berasal dari cabang yang sama.
- Event real-time dikirim setelah commit memakai `ShouldDispatchAfterCommit` milik Laravel. **`outbox_messages` dan `idempotency_keys` sudah ada di skema tetapi belum dipakai kode** (dicadangkan bila nanti dibutuhkan jaminan pengiriman lintas worker atau retry command idempotent).
- Soft delete (`deleted_at`) pada `branches`, `employees`, `service_types`, `counters`, `priority_types`, `display_devices`. Kode tetap dicadangkan oleh unique constraint walau datanya dihapus; query mentah (`DB::table`) wajib menambahkan `whereNull('deleted_at')`.
- `display_devices.device_type` dibatasi CHECK (`DISPLAY`,`KIOSK`) pada PostgreSQL.
