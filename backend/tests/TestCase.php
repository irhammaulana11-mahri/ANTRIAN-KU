<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Mendaftarkan antrian seperti petugas di kantor (pendaftaran hanya offline). Memakai token Admin Cabang untuk cabang pada
     * payload, dan pengguna yang sedang dipakai tes dikembalikan setelahnya. Butuh RolePermissionSeeder sudah dijalankan.
     */
    protected function registerQueue(array $payload): TestResponse
    {
        if (! \App\Models\Role::query()->where('name', 'Admin Cabang')->exists()) {
            $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        }
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $payload['branch_id'], 'name' => 'Petugas Uji', 'email' => 'petugas'.Str::random(8).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole('Admin Cabang');
        $previous = $this->app['auth']->guard('sanctum')->user();
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/queues/walk-in', $payload);
        $this->app['auth']->forgetGuards();
        if ($previous) { // kembalikan pengguna yang sedang dipakai tes
            \Laravel\Sanctum\Sanctum::actingAs($previous);
        }

        return $response;
    }
}
