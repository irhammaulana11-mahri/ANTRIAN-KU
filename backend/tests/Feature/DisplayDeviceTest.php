<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DisplayDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $other;

    private ServiceType $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'DP001', 'branch_name' => 'Display Branch', 'is_active' => true]);
        $this->other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'DP002', 'branch_name' => 'Other Branch', 'is_active' => true]);
        $this->service = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'service_code' => 'CS', 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => ($branch ?? $this->branch)->id, 'name' => $role, 'email' => Str::slug($role).Str::random(4).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: string, 1: string} [credential, token] */
    private function pairedDevice(?Branch $branch = null): array
    {
        $branch ??= $this->branch;
        Sanctum::actingAs($this->user('Admin Cabang', $branch));
        $credential = $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-'.Str::random(4), 'device_name' => 'TV Lobby', 'branch_id' => $branch->id])->assertCreated()->json('credential');
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/display/activate', ['credential' => $credential])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        return [$credential, $token];
    }

    public function test_admin_pairs_device_and_credential_is_not_stored_in_plain_text(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $response = $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-01', 'device_name' => 'TV Lobby'])->assertCreated();
        $credential = $response->json('credential');
        $this->assertStringStartsWith($response->json('data.id').'.', $credential);
        $this->assertStringNotContainsString('credential', json_encode($response->json('data')));
        $this->assertDatabaseMissing('display_devices', ['credential_hash' => explode('.', $credential)[1]]);
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-01', 'device_name' => 'Duplikat'])->assertStatus(422);
    }

    public function test_customer_service_cannot_manage_devices_and_admin_cannot_touch_other_branch(): void
    {
        Sanctum::actingAs($this->user('Customer Service'));
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-X', 'device_name' => 'X'])->assertForbidden();

        [$credential] = $this->pairedDevice($this->other);
        $deviceId = explode('.', $credential)[0];
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson("/api/v1/display-devices/{$deviceId}/revoke")->assertForbidden();
    }

    public function test_activation_rejects_wrong_or_revoked_credentials(): void
    {
        [$credential] = $this->pairedDevice();
        [$id] = explode('.', $credential);
        $this->postJson('/api/v1/display/activate', ['credential' => $id.'.salah'])->assertUnauthorized();
        $this->postJson('/api/v1/display/activate', ['credential' => 'bukan-kredensial'])->assertUnauthorized();

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson("/api/v1/display-devices/{$id}/revoke")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/display/activate', ['credential' => $credential])->assertUnauthorized();
    }

    public function test_device_token_expires_and_is_killed_by_revoke_and_rotate(): void
    {
        [$credential, $token] = $this->pairedDevice();
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'display']);
        $this->assertTrue(\App\Models\PersonalAccessToken::query()->where('name', 'display')->whereNotNull('expires_at')->exists());

        $this->withToken($token)->getJson('/api/v1/display/snapshot')->assertOk();
        $this->app['auth']->forgetGuards();

        [$id] = explode('.', $credential);
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson("/api/v1/display-devices/{$id}/rotate")->assertOk()->assertJsonStructure(['credential']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_snapshot_returns_only_own_branch_calls_with_counter_and_settings(): void
    {
        [, $token] = $this->pairedDevice();
        $counter = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'CS-01', 'counter_name' => 'Loket CS 1', 'counter_type' => 'CS', 'is_active' => true]);
        $user = $this->user('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $user->id, 'employee_code' => 'E1', 'employee_name' => 'Budi', 'is_active' => true]);
        $employee->serviceTypes()->attach($this->service->id);
        $employee->counters()->attach($counter->id);
        $queueId = $this->postJson('/api/v1/queues/online', ['branch_id' => $this->branch->id, 'service_type_id' => $this->service->id, 'customer_name' => 'Pelanggan Rahasia'])->json('data.queue.id');
        $otherService = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->other->id, 'service_code' => 'CS', 'service_name' => 'CS Lain', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
        $this->postJson('/api/v1/queues/online', ['branch_id' => $this->other->id, 'service_type_id' => $otherService->id, 'customer_name' => 'Cabang Lain'])->assertCreated();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk();
        $this->postJson("/api/v1/service/{$queueId}/call")->assertOk();
        $this->postJson("/api/v1/service/{$queueId}/recall")->assertOk();
        $this->app['auth']->forgetGuards();

        $data = $this->withToken($token)->getJson('/api/v1/display/snapshot')->assertOk()->assertJsonPath('data.branch.branch_name', 'Display Branch')->json('data');
        $this->assertCount(2, $data['calls']);
        $this->assertSame('RECALL', $data['calls'][0]['call_type']);
        $this->assertSame('CS-001', $data['calls'][0]['queue_number']);
        $this->assertSame('CS-01', $data['calls'][0]['counter_code']);
        $this->assertSame('Pelanggan Rahasia', $data['calls'][0]['customer_name']);
        $this->assertArrayNotHasKey('customer_phone', $data['calls'][0]);
        $this->assertSame(60, $data['settings']['call_expiry_seconds']);
        $this->assertNotContains('Cabang Lain', array_column($data['calls'], 'customer_name'));
    }

    public function test_device_token_cannot_use_staff_endpoints_and_staff_token_cannot_use_display_endpoints(): void
    {
        [, $token] = $this->pairedDevice();
        foreach (['/api/v1/queues/history', '/api/v1/employee/workspace', '/api/v1/branches', '/api/v1/auth/me'] as $url) {
            $this->withToken($token)->getJson($url)->assertStatus(403);
            $this->app['auth']->forgetGuards();
        }
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->getJson('/api/v1/display/snapshot')->assertForbidden();
    }

    public function test_device_channel_auth_is_limited_to_its_own_branch(): void
    {
        [, $token] = $this->pairedDevice();
        $this->withToken($token)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-branch.'.$this->branch->id])->assertOk()->assertJsonStructure(['auth']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-branch.'.$this->other->id])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $employeeChannel = 'private-employee.'.Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'employee_code' => 'E9', 'employee_name' => 'X', 'is_active' => true])->id;
        $this->withToken($token)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $employeeChannel])->assertForbidden();
    }

    public function test_display_settings_are_branch_scoped_validated_and_served_to_device(): void
    {
        [, $token] = $this->pairedDevice();
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->putJson('/api/v1/settings/display', ['display_announcement' => 'Kantor tutup pukul 15.00', 'voice_rate' => 0.9, 'voice_volume' => 0.8])->assertOk()->assertJsonPath('data.display_announcement', 'Kantor tutup pukul 15.00');
        $this->putJson('/api/v1/settings/display', ['voice_rate' => 5])->assertStatus(422);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/display/snapshot')->assertOk()
            ->assertJsonPath('data.settings.announcement', 'Kantor tutup pukul 15.00')->assertJsonPath('data.settings.voice_rate', 0.9);
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->user('Customer Service'));
        $this->putJson('/api/v1/settings/display', ['display_announcement' => 'x'])->assertForbidden();
    }
}
