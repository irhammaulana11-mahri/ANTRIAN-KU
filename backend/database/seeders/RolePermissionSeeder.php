<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cache agar syncPermissions membaca dari database, bukan cache stale
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = [
            'branches.view', 'branches.manage', 'branches.update_own', 'units.view', 'units.manage', 'units.update_own',
            'users.manage', 'employees.view', 'employees.manage',
            'services.view', 'services.manage', 'counters.view', 'counters.manage', 'queues.view',
            'queues.register', 'queues.cancel', 'queues.assign', 'queues.call', 'queues.recall',
            'queues.start', 'queues.complete', 'queues.skip', 'queues.transfer', 'employees.status',
            'reports.view', 'settings.manage', 'displays.manage', 'audit.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web'], ['id' => (string) Str::uuid()]);
        }

        $rolePermissions = [
            'Super Admin' => $permissions,
            'Admin Cabang' => [
                'branches.view', 'branches.update_own', 'units.view', 'units.manage',
                'employees.view', 'employees.manage', 'users.manage',
                'services.view', 'services.manage', 'counters.view', 'counters.manage',
                'queues.view', 'queues.register', 'queues.cancel',
                'reports.view', 'settings.manage', 'displays.manage', 'audit.view',
            ],
            // Admin Unit: kelola pegawai (CS/Teller), loket, dan supervisi antrian di unitnya
            'Admin Unit' => [
                'units.view', 'units.update_own', 'employees.view', 'employees.manage', 'users.manage',
                'services.view', 'counters.view', 'counters.manage',
                'queues.view', 'queues.register', 'queues.cancel', 'queues.assign',
                'queues.call', 'queues.recall', 'queues.start', 'queues.complete',
                'queues.skip', 'queues.transfer', 'employees.status',
                'reports.view', 'displays.manage',
            ],
            'Customer Service' => [
                'queues.view', 'queues.call', 'queues.recall', 'queues.start', 'queues.complete',
                'queues.skip', 'employees.status',
            ],
            'Teller' => [
                'queues.view', 'queues.call', 'queues.recall', 'queues.start', 'queues.complete',
                'queues.skip', 'employees.status',
            ],
            'Customer' => ['queues.register', 'queues.view', 'queues.cancel'],
            'Display Device' => ['queues.view'],
        ];

        foreach ($rolePermissions as $roleName => $rolePermissionNames) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['id' => (string) Str::uuid()]);
            $role->syncPermissions($rolePermissionNames);
        }
    }
}
