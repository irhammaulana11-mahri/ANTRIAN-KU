<?php

use App\Models\PersonalAccessToken;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [
    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')),
    'guard' => ['web'],
    // Dibiarkan null: masa berlaku diatur PER TOKEN lewat `expires_at` (staf: staff_token_minutes; perangkat: 7 hari).
    // Jangan isi nilai global — Sanctum menghitungnya dari created_at semua token, termasuk token perangkat.
    'expiration' => null,

    // Masa berlaku token staf/admin (menit). Frontend memperpanjangnya otomatis selama tab terbuka (POST /auth/refresh).
    'staff_token_minutes' => (int) env('SANCTUM_STAFF_TOKEN_MINUTES', 720),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
    'personal_access_token_model' => PersonalAccessToken::class,
];
