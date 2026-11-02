<?php

// Hanya opsi yang perlu diubah; sisanya memakai default framework.
return [
    // Tanggal operasional antrian (queue_date) mengikuti zona waktu ini, bukan UTC.
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),

    // Halaman /api/docs (Swagger UI). Default aktif kecuali produksi; paksa dengan API_DOCS_ENABLED=true/false.
    'api_docs' => (bool) env('API_DOCS_ENABLED', env('APP_ENV', 'production') !== 'production'),
];
