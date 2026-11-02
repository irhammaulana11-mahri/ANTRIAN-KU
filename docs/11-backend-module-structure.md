# Backend Module Structure

> Diperbarui 31 Oktober 2026. **Keputusan (ADR ringkas):** rancangan awal mengusulkan `app/Domain/<Modul>/…`. Implementasi memakai struktur Laravel berlapis (flat) karena basis kode masih satu tim kecil dan batas modul dijaga lewat service class; migrasi ke folder domain tetap mungkin tanpa mengubah perilaku.

```text
app/
  Http/Controllers/Api/V1/   controller tipis (Auth, Branch, Employee, EmployeeWorkspace, Queue, Distribution,
                             Display, DisplayDevice, DisplaySettings, Kiosk, QueueSettings, PriorityType, Reporting …)
  Http/Controllers/ApiDocsController.php   Swagger UI + spesifikasi
  Http/Middleware/           ResolveBranchContext (branch.context), EnsureDisplayDevice (device.type), SecurityHeaders
  Http/Requests/             Form Request per use-case (validasi + otorisasi izin)
  Http/Resources/            API Resource
  Models/                    Eloquent (UUID, soft delete pada master)
  Policies/                  Branch, Employee, Counter, ServiceType
  Services/                  logika bisnis & transaksi:
     QueueManagementService      pendaftaran, transisi status, transfer, prioritas, call log
     QueueDistributionEngine     assignNext / assignPending / manualAssign
     RoundRobinStrategy, PriorityQueueStrategy, ManualAssignmentStrategy
     EmployeeStatusService       status, heartbeat
     BranchAvailabilityService   jam operasional, libur, kuota, ambang
     WaitEstimator               posisi & estimasi tunggu
     QueueSettingsService        pengaturan antrian & hari libur
     BackendFoundationService    CRUD master, pengaman hapus/nonaktifkan, restore, listing
     ReportingService, AuditLogService, BranchAuthorizationService
  Events/                    BranchEvent (+ turunan) — ShouldBroadcast + ShouldDispatchAfterCommit
config/app.php               hanya override: timezone, api_docs
resources/openapi.yaml       dokumentasi API
routes/{api,channels}.php
database/{migrations,seeders}
tests/{Unit,Feature,Support}  Support/Parallel = pelari proses paralel untuk tes concurrency
phpunit.xml (SQLite) · phpunit.pgsql.xml (PostgreSQL schema sqms_test)
```

Prinsip: controller tipis → Form Request memvalidasi dan memeriksa izin → service membuka transaksi, mengunci baris, memutasi, mencatat audit → event dikirim setelah commit. Query mentah (`DB::table`) wajib memperhatikan `deleted_at` dan `branch_id`. Outbox/`idempotency_keys` **tidak** diimplementasikan (lihat `docs/15`).
