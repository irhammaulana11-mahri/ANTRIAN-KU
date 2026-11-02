<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'branches.view', 'branches.manage', 'users.manage', 'employees.view', 'employees.manage',
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
                'branches.view', 'employees.view', 'employees.manage', 'services.view', 'services.manage',
                'counters.view', 'counters.manage', 'queues.view', 'queues.register', 'queues.cancel',
                'reports.view', 'settings.manage', 'displays.manage', 'audit.view',
            ],
            'Supervisor' => [
                'employees.view', 'services.view', 'counters.view', 'queues.view', 'queues.assign',
                'queues.call', 'queues.recall', 'queues.start', 'queues.complete', 'queues.skip',
                'queues.transfer', 'employees.status', 'reports.view',
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
