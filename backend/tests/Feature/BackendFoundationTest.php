<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackendFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $branchId, string $roleName = 'Admin Cabang'): User
    {
        $user = User::create(['id' => fake()->uuid(), 'branch_id' => $branchId, 'name' => 'Admin', 'email' => fake()->unique()->safeEmail(), 'password' => Hash::make('password123')]);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'], ['id' => fake()->uuid()]);
        foreach (['employees.view', 'employees.manage', 'services.view', 'services.manage', 'branches.view'] as $permission) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web'], ['id' => fake()->uuid()]));
        }
        $user->assignRole($role);

        return $user;
    }

    public function test_login_returns_sanctum_token(): void
    {
        $branch = Branch::create(['id' => fake()->uuid(), 'branch_code' => 'TST001', 'branch_name' => 'Test', 'is_active' => true]);
        $user = $this->user($branch->id);

        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123']);

        $response->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_branch_user_cannot_read_another_branch_employee(): void
    {
        $own = Branch::create(['id' => fake()->uuid(), 'branch_code' => 'OWN001', 'branch_name' => 'Own', 'is_active' => true]);
        $other = Branch::create(['id' => fake()->uuid(), 'branch_code' => 'OTH001', 'branch_name' => 'Other', 'is_active' => true]);
        $user = $this->user($own->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/employees?branch_id='.$other->id);

        $response->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_super_admin_can_create_service_type_for_selected_branch(): void
    {
        $branch = Branch::create(['id' => fake()->uuid(), 'branch_code' => 'ADM001', 'branch_name' => 'Admin', 'is_active' => true]);
        $user = User::create(['id' => fake()->uuid(), 'name' => 'Super Admin', 'email' => fake()->unique()->safeEmail(), 'password' => Hash::make('password123')]);
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'], ['id' => fake()->uuid()]);
        $user->assignRole($role);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'services.manage', 'guard_name' => 'web'], ['id' => fake()->uuid()]));

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/service-types', ['branch_id' => $branch->id, 'service_code' => 'CS', 'service_name' => 'Customer Service', 'queue_prefix' => 'CS']);

        $response->assertCreated()->assertJsonPath('data.branch_id', $branch->id);
    }
}
