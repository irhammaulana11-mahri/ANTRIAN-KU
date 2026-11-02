# API Specification (v1)

> **Sumber kebenaran: `backend/resources/openapi.yaml` (OpenAPI 3.0.3, 74 operasi), ditampilkan di `/api/docs` (Swagger UI).** Kesesuaiannya dengan rute dan izin diuji otomatis (`OpenApiSpecTest`). Dokumen ini hanya ringkasan konvensi; jangan menyalin daftar endpoint di sini.

## Konvensi
- Base URL `/api/v1` (kecuali `POST /broadcasting/auth`). JSON.
- Sukses: `{ "data": …, "message": "…" }`. Daftar: pagination Laravel (`data`, `links`, `meta`) — **bukan** `{data, meta, errors}` seperti rancangan awal. Galat: `{ "message" }` atau `{ "message", "errors": { field: [pesan] } }` (422).
- Autentikasi: `Authorization: Bearer` — token Sanctum pengguna (`/auth/login`, kedaluwarsa 12 jam, `/auth/refresh` untuk memperpanjang) atau token perangkat (`/display/activate`, ability `display`/`kiosk`). Endpoint publik: katalog, pendaftaran online, pelacakan, pembatalan via token, aktivasi perangkat.
- Daftar master mendukung `page`, `per_page` (maks 100), `q`, `is_active`, `deleted=1`, `sort` (whitelist kolom), `direction`, `branch_id` (wajib bagi Super Admin pada endpoint per-cabang).
- Status HTTP: 200/201/204; 401 tak terautentikasi; 403 tanpa izin/lintas cabang/perangkat salah tipe; 404; **409** konflik status atau data sedang dipakai; **422** validasi; **429** rate limit.
- Rate limit: umum 120/mnt; login 5/mnt (email+IP); pendaftaran 20/mnt/IP; aktivasi perangkat 10/mnt/IP; kiosk 30/mnt/perangkat.

## Kelompok endpoint (ringkas)
| Kelompok | Contoh |
|---|---|
| Auth | `/auth/login`, `/auth/me`, `/auth/logout`, `/auth/refresh` (token staf 12 jam, diperpanjang otomatis) |
| Katalog & antrian publik | `/catalog/branches` (+ status buka & kuota), `/catalog/service-types`, `/queues/online`, `/queues/track/{token}`, `/queues/cancel-by-token` |
| Antrian staf | `/queues/walk-in` (**wajib login**), `/queues/history`, `/queues/{id}/{cancel,verify-priority,transfer,status,assign}`, `/distribution/next`, `/supervisor/board` |
| Ruang kerja petugas | `/employee/{workspace,status,heartbeat}`, `/service/{queue}/{call,recall,start,complete,skip,no-show}` |
| Master data | `/branches`, `/employees`, `/counters`, `/service-types` (CRUD + `/{id}/restore`), `/employees/{id}/{competencies,counters,status,heartbeat}`, `/priority-types` |
| Perangkat | `/display-devices` (+ `rotate`, `revoke`), `/display/{activate,refresh,snapshot}`, `/kiosk/{config,queues}` |
| Pengaturan | `/settings/queue`, `/settings/holidays`, `/settings/display` |
| Laporan & audit | `/reports/summary`, `/reports/export` (CSV), `/audit-logs` |
| Real-time | `POST /broadcasting/auth` (channel `private-branch.{id}`, `private-employee.{id}`) |

## Belum diimplementasikan dari rancangan awal
`Idempotency-Key` pada mutasi kritis (duplikasi klik ditangani state machine + lock → 409); endpoint `/counters/{id}/assign`, `/service/assign`, `/reports/productivity` & `/reports/branches` terpisah (data tersebut ada di `/reports/summary`); `/settings/operating-hours` terpisah (digabung ke `/settings/queue`).
