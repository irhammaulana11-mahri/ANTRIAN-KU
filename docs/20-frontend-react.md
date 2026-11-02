# Fase 5 — Frontend React

## Stack

- React 19 + TypeScript + Vite
- Tailwind CSS dengan komponen UI lokal bergaya shadcn/ui
- React Router
- TanStack Query untuk server state dan polling
- React Hook Form + Zod untuk form dan validasi
- Recharts untuk laporan
- Axios typed API client dengan Sanctum bearer token

## Struktur folder

```text
frontend/
├── src/
│   ├── components/
│   │   ├── layout/AppLayout.tsx
│   │   ├── ui/{Badge,Button,Card,Field}.tsx
│   │   ├── Feedback.tsx
│   │   ├── PageHeader.tsx
│   │   └── StatCard.tsx
│   ├── context/AuthContext.tsx
│   ├── lib/{api,utils}.ts
│   ├── pages/
│   │   ├── LoginPage.tsx
│   │   ├── DashboardPage.tsx
│   │   ├── EntityPage.tsx
│   │   ├── QueuePages.tsx
│   │   └── ReportsPage.tsx
│   ├── types/api.ts
│   ├── App.tsx
│   ├── main.tsx
│   └── styles.css
├── package.json
├── vite.config.ts
├── tailwind.config.js
└── tsconfig.app.json
```

## Halaman

| Halaman | Route | Sumber data |
|---|---|---|
| Login | `/login` | `/api/v1/auth/login` |
| Dashboard Super Admin/Admin Cabang | `/` | `/api/v1/queues/history` |
| Branch Management | `/branches` | `/api/v1/branches` |
| Employee Management | `/employees` | `/api/v1/employees` |
| Counter Management | `/counters` | `/api/v1/counters` |
| Service Management | `/services` | `/api/v1/service-types` |
| Queue Monitoring | `/queues` | `/api/v1/queues/history` |
| CS/Teller Dashboard | `/operator` | `/api/v1/queues/history`, `/api/v1/queues/{id}/status` |
| Customer Registration | `/register` | `/api/v1/queues/online`, katalog publik |
| Customer Tracking | `/tracking` | `/api/v1/queues/track/{token}` |
| Central Display | `/display` | `/api/v1/queues/history` polling |
| Reports | `/reports` | `/api/v1/reports/summary` |

Customer catalog menggunakan endpoint read-only `/api/v1/catalog/branches` dan `/api/v1/catalog/service-types`; endpoint ini hanya mengembalikan master data aktif.

## Menjalankan

```bash
cd frontend
npm install
cp .env.example .env
npm run dev
```

Untuk production:

```bash
npm run build
npm run preview
```

`VITE_API_URL` dapat diisi URL backend penuh, misalnya `https://sqms.example.id/api/v1`. Token Sanctum disimpan di `localStorage` dan otomatis ditambahkan oleh Axios interceptor. Response 401 membersihkan sesi dan mengarahkan pengguna ke login.

## Validasi

```bash
npm run build
npm run lint
```

Build frontend telah berhasil dijalankan. Warning ukuran bundle berasal dari Recharts dan dependency UI; code splitting dapat ditambahkan pada fase optimasi deployment.
