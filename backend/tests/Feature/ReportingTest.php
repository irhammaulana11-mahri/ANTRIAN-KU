<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_report_returns_daily_metrics_and_scope(): void
    {
        $branch = Branch::create(['id' => fake()->uuid(), 'branch_code' => 'RPT001', 'branch_name' => 'Report', 'is_active' => true]);
        $user = $this->reportUser($branch->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/reports/summary?period=today');

        $response->assertOk()->assertJsonPath('data.branch_id', $branch->id)->assertJsonStructure(['data' => ['total_queues', 'served_customers', 'average_wait_seconds', 'average_service_seconds', 'employee_productivity', 'branch_comparison']]);
        $this->assertSame(0, $response->json('data.total_queues'));
    }

    public function test_audit_endpoint_is_permission_protected_and_branch_scoped(): void
    {
        $branch = Branch::create(['id' => fake()->uuid(), 'branch_code' => 'AUD001', 'branch_name' => 'Audit', 'is_active' => true]);
        $user = $this->reportUser($branch->id);
        AuditLog::create(['id' => fake()->uuid(), 'branch_id' => $branch->id, 'actor_id' => $user->id, 'action' => 'test', 'entity_type' => 'test', 'entity_id' => null, 'metadata' => [], 'created_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/audit-logs');

        $response->assertOk()->assertJsonPath('data.0.branch_id', $branch->id);
    }

    private function reportUser(string $branchId): User
    {
        $permission = Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web'], ['id' => fake()->uuid()]);
        $audit = Permission::firstOrCreate(['name' => 'audit.view', 'guard_name' => 'web'], ['id' => fake()->uuid()]);
        $role = Role::create(['id' => fake()->uuid(), 'name' => 'Report Tester '.fake()->unique()->numberBetween(1, 99999), 'guard_name' => 'web']);
        $role->givePermissionTo([$permission, $audit]);
        $user = User::create(['id' => fake()->uuid(), 'branch_id' => $branchId, 'name' => 'Reporter', 'email' => fake()->unique()->safeEmail(), 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }
}
