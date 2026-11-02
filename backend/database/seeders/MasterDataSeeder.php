<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $branches = [
            ['branch_code' => 'JKT001', 'branch_name' => 'Cabang Jakarta Pusat', 'address' => 'Jl. Merdeka No. 1, Jakarta', 'phone' => '021-1000001'],
            ['branch_code' => 'BDG001', 'branch_name' => 'Cabang Bandung Dago', 'address' => 'Jl. Dago No. 10, Bandung', 'phone' => '022-2000001'],
        ];

        foreach ($branches as $branchData) {
            $branch = DB::table('branches')->where('branch_code', $branchData['branch_code'])->first();
            $branchId = $branch?->id ?? (string) Str::uuid();
            DB::table('branches')->updateOrInsert(
                ['branch_code' => $branchData['branch_code']],
                [
                    'id' => $branchId,
                    'branch_name' => $branchData['branch_name'],
                    'address' => $branchData['address'],
                    'phone' => $branchData['phone'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            DB::table('queue_settings')->updateOrInsert(
                ['branch_id' => $branchId],
                ['id' => (string) (DB::table('queue_settings')->where('branch_id', $branchId)->value('id') ?? Str::uuid()), 'normal_fairness_quota' => 3, 'stale_heartbeat_seconds' => 90, 'voice_rate' => 1.00, 'voice_volume' => 1.00, 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            );

            $this->seedBranch($branchId, $branchData['branch_code'], $now);
        }
    }

    private function seedBranch(string $branchId, string $branchCode, mixed $now): void
    {
        $services = [
            ['service_code' => 'CS', 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true],
            ['service_code' => 'TL', 'service_name' => 'Teller', 'queue_prefix' => 'TL', 'priority_enabled' => true],
        ];
        $serviceIds = [];
        foreach ($services as $service) {
            $existing = DB::table('service_types')->where(['branch_id' => $branchId, 'service_code' => $service['service_code']])->first();
            $id = $existing?->id ?? (string) Str::uuid();
            $serviceIds[$service['service_code']] = $id;
            DB::table('service_types')->updateOrInsert(
                ['branch_id' => $branchId, 'service_code' => $service['service_code']],
                [
                    'id' => $id,
                    'service_name' => $service['service_name'],
                    'queue_prefix' => $service['queue_prefix'],
                    'priority_enabled' => $service['priority_enabled'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $priorityIds = [];
        foreach ([['NORMAL', 'Normal', 0], ['PRIORITY', 'Prioritas', 20], ['URGENT', 'Mendesak', 50]] as [$code, $name, $weight]) {
            $existing = DB::table('priority_types')->where(['branch_id' => $branchId, 'priority_code' => $code])->first();
            $id = $existing?->id ?? (string) Str::uuid();
            $priorityIds[$code] = $id;
            DB::table('priority_types')->updateOrInsert(
                ['branch_id' => $branchId, 'priority_code' => $code],
                ['id' => $id, 'priority_name' => $name, 'weight' => $weight, 'aging_rate' => 1, 'aging_interval_seconds' => 300, 'verification_required' => $code !== 'NORMAL', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach ([['CS-01', 'Loket CS 1', 'CS'], ['TL-01', 'Loket Teller 1', 'TL']] as [$code, $name, $type]) {
            $existing = DB::table('counters')->where(['branch_id' => $branchId, 'counter_code' => $code])->first();
            $counterId = $existing?->id ?? (string) Str::uuid();
            DB::table('counters')->updateOrInsert(
                ['branch_id' => $branchId, 'counter_code' => $code],
                ['id' => $counterId, 'counter_name' => $name, 'counter_type' => $type, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $users = [
            ['email' => strtolower($branchCode).'@example.test', 'name' => 'Admin '.$branchCode, 'role' => 'Admin Cabang'],
            ['email' => 'cs.'.strtolower($branchCode).'@example.test', 'name' => 'Petugas CS '.$branchCode, 'role' => 'Customer Service'],
            ['email' => 'teller.'.strtolower($branchCode).'@example.test', 'name' => 'Petugas Teller '.$branchCode, 'role' => 'Teller'],
        ];
        foreach ($users as $userData) {
            $existing = DB::table('users')->where('email', $userData['email'])->first();
            $userId = $existing?->id ?? (string) Str::uuid();
            DB::table('users')->updateOrInsert(
                ['email' => $userData['email']],
                ['id' => $userId, 'branch_id' => $branchId, 'name' => $userData['name'], 'password' => Hash::make('ChangeMe123!'), 'created_at' => $now, 'updated_at' => $now],
            );
            $employee = DB::table('employees')->where(['branch_id' => $branchId, 'user_id' => $userId])->first();
            $employeeId = $employee?->id ?? (string) Str::uuid();
            $employeeCode = strtoupper(substr($userData['role'], 0, 2)).'-'.$branchCode;
            DB::table('employees')->updateOrInsert(
                ['branch_id' => $branchId, 'user_id' => $userId],
                ['id' => $employeeId, 'employee_code' => $employeeCode, 'employee_name' => $userData['name'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
            $roleId = DB::table('roles')->where(['name' => $userData['role'], 'guard_name' => 'web'])->value('id');
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $roleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $userId,
            ]);
        }

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            DB::table('operating_hours')->updateOrInsert(
                ['branch_id' => $branchId, 'weekday' => $weekday],
                ['id' => (string) (DB::table('operating_hours')->where(['branch_id' => $branchId, 'weekday' => $weekday])->value('id') ?? Str::uuid()), 'opens_at' => '08:00:00+07', 'closes_at' => '16:00:00+07', 'is_closed' => $weekday >= 6, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
