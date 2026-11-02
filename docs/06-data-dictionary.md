# SQMS — Complete Data Dictionary

Konvensi: UUID primary key; `created_at`, `updated_at`, dan waktu bisnis adalah `timestamptz`; master memakai soft delete bila relevan; status menggunakan `varchar` + CHECK agar mudah dievolusikan.

| Tabel | Kolom inti | Aturan dan relasi |
|---|---|---|
| branches | id, branch_code, branch_name, address, phone, is_active | branch_code unik; parent seluruh data cabang |
| users | id, branch_id nullable, name, email, password, last_login_at | email unik; branch null untuk super admin |
| permissions | id, name, guard_name | unik name+guard; Spatie permission |
| roles | id, name, guard_name | unik name+guard; Spatie role |
| role_has_permissions | permission_id, role_id | PK gabungan; FK cascade |
| model_has_roles | role_id, model_type, model_id | PK polymorphic; UUID model_id |
| model_has_permissions | permission_id, model_type, model_id | PK polymorphic; UUID model_id |
| employees | id, branch_id, user_id, employee_code, employee_name, is_active | employee_code unik per branch; user opsional |
| service_types | id, branch_id, service_code, service_name, queue_prefix, priority_enabled, is_active | code/prefix unik per branch |
| counters | id, branch_id, counter_code, counter_name, counter_type, is_active | code unik per branch |
| employee_services | employee_id, service_type_id | kompetensi M:N; PK gabungan; dilepas saat layanan dihapus |
| employee_counters | employee_id, counter_id, is_primary | penempatan petugas-loket M:N; dilepas saat loket dihapus |
| priority_types | id, branch_id, priority_code, priority_name, weight, aging_rate, aging_interval_seconds, verification_required, is_active | weight/rate tidak negatif; code unik per branch |
| operating_hours | id, branch_id, weekday, opens_at, closes_at, is_closed | satu konfigurasi per weekday per branch (Senin=1…Minggu=7); `timetz`, dibaca sebagai jam dinding zona waktu aplikasi. Tanpa baris = dianggap buka. |
| holidays | id, branch_id, holiday_date, holiday_name | tanggal unik per branch; menutup pendaftaran online pada tanggal itu |
| queue_settings | id, branch_id, online_daily_quota, max_waiting_threshold, normal_fairness_quota, stale_heartbeat_seconds, voice_rate, voice_volume, display_announcement, version | satu row per branch. `online_daily_quota`/`max_waiting_threshold` NULL = tanpa batas. `normal_fairness_quota` belum dipakai mesin. `stale_heartbeat_seconds` default 90. |
| queue_sequences | id, branch_id, service_type_id, queue_date, last_number | lockable sequence; unique branch+service+date |
| queues | id, branch_id, service_type_id, priority_type_id, queue_date, sequence_no, queue_number, customer_name, customer_phone, service_notes, registration_channel, status, token_hash, priority_verified_by/at, called_at, completed_at | state CHECK; numbering unique; FK komposit ke service/priority |
| queue_assignments | id, branch_id, queue_id, employee_id, counter_id, assignment_type, reason, assigned_at, ended_at | partial unique active queue/employee/counter |
| service_sessions | id, branch_id, queue_id, employee_id, counter_id, started_at, completed_at, outcome, notes | partial unique active queue/employee/counter |
| employee_status_logs | id, branch_id, employee_id, from_status, to_status, reason, heartbeat_at, recorded_at | status CHECK; histori append-only |
| queue_call_logs | id, branch_id, queue_id, employee_id, counter_id, call_type, event_id, called_at | INITIAL/RECALL; event_id unik |
| queue_transitions | id, branch_id, queue_id, from_status, to_status, actor_id, reason, transitioned_at | audit state machine |
| display_devices | id, branch_id, device_code, device_name, **device_type**, credential_hash, last_seen_at, revoked_at | `device_type` DISPLAY/KIOSK (CHECK). credential = `{id}.{secret}`; hanya SHA-256 secret yang disimpan. device_code unik per branch. Soft delete. |
| audit_logs | id, branch_id, actor_id, action, entity_type/id, before_data, after_data, metadata, correlation_id, created_at | append-only, PII-aware |
| distribution_cursors | id, branch_id, service_type_id, last_employee_id | cursor Round Robin; unik per branch+layanan; dikunci saat memilih petugas |
| personal_access_tokens | id, tokenable_type/id (uuid), name, token (hash), abilities, last_used_at, expires_at | token Sanctum untuk `users` (tanpa expiry) dan `display_devices` (ability `display`/`kiosk`, 7 hari) |
| cache, cache_locks, jobs, job_batches, failed_jobs | — | infrastruktur Laravel (driver `database` di pengembangan) |
| outbox_messages | id, branch_id, event_type, aggregate_type/id, payload, published_at, attempts, last_error | **dicadangkan, belum dipakai** |
| idempotency_keys | id, actor_id, branch_id, key, request_hash, response_status/body, expires_at | **dicadangkan, belum dipakai** |

## Status dan nilai terkontrol

- Queue: `WAITING`, `ASSIGNED`, `CALLED`, `SERVING`, `COMPLETED`, `SKIPPED`, `CANCELLED`, `NO_SHOW`.
- Employee: `AVAILABLE`, `BUSY`, `BREAK`, `OFFLINE`, `CLOSED`.
- Registration: `WALK_IN`, `ONLINE`.
- Assignment: `AUTO`, `MANUAL`, `TRANSFER`.
- Call: `INITIAL`, `RECALL`.
- Device: `DISPLAY`, `KIOSK`.
- Counter type: `CS`, `TL` (menentukan area CS/Teller pada Central Display).
