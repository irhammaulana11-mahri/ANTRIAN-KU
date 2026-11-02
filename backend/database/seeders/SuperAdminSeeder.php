<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('SQMS_ADMIN_EMAIL'));
        $password = (string) env('SQMS_ADMIN_PASSWORD');
        $name = trim((string) env('SQMS_ADMIN_NAME', 'Super Administrator'));

        if ($email === '' || $password === '') {
            throw new RuntimeException(
                'SQMS_ADMIN_EMAIL dan SQMS_ADMIN_PASSWORD wajib diisi sebelum menjalankan seeder super admin.'
            );
        }

        if (mb_strlen($password) < 12) {
            throw new RuntimeException('SQMS_ADMIN_PASSWORD minimal 12 karakter.');
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'id' => (string) Str::uuid(),
                'name' => $name !== '' ? $name : 'Super Administrator',
                'password' => $password,
            ]
        );

        $role = Role::query()
            ->where('name', 'Super Admin')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $user->syncRoles([$role]);
    }
}
