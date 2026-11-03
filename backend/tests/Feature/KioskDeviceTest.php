<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\Queue;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KioskDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $other;

    private ServiceType $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'KS001', 'branch_name' => 'Kiosk Branch', 'is_active' => true]);
        $this->other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'KS002', 'branch_name' => 'Other Branch', 'is_active' => true]);
        $this->service = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => ($branch ?? $this->branch)->id, 'name' => $role, 'email' => Str::slug($role).Str::random(5).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    /** Membuat perangkat lewat API admin lalu mengaktifkannya; mengembalikan [token, id]. */
    private function pair(string $type, ?Branch $branch = null): array
    {
        $branch ??= $this->branch;
        Sanctum::actingAs($this->user('Admin Cabang', $branch));
        $created = $this->postJson('/api/v1/display-devices', ['device_code' => strtoupper($type).'-'.Str::random(4), 'device_name' => ucfirst(strtolower($type)), 'device_type' => $type])->assertCreated();
        $this->app['auth']->forgetGuards();
        $session = $this->postJson('/api/v1/display/activate', ['credential' => $created->json('credential')])->assertOk()->assertJsonPath('data.device.device_type', $type);
        $this->app['auth']->forgetGuards();

        return [$session->json('data.token'), $created->json('data.id')];
    }

    private function as(string $token, string $method, string $url, array $data = [])
    {
        $response = $this->json($method, $url, $data, ['Authorization' => 'Bearer '.$token]);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    public function test_kiosk_device_gets_a_kiosk_ability_token_and_default_type_is_display(): void
    {
        [$token] = $this->pair('KIOSK');
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'kiosk']);
        $this->assertSame('["kiosk"]', \DB::table('personal_access_tokens')->where('name', 'kiosk')->value('abilities'));

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-X', 'device_name' => 'TV'])->assertCreated()->assertJsonPath('data.device_type', 'DISPLAY');
        $this->postJson('/api/v1/display-devices', ['device_code' => 'BAD', 'device_name' => 'Salah', 'device_type' => 'ROBOT'])->assertStatus(422)->assertJsonValidationErrors('device_type');
        $this->assertNotEmpty($token);
    }

    public function test_kiosk_config_lists_only_active_services_of_its_own_branch(): void
    {
        $this->service->replicate()->forceFill(['id' => (string) Str::uuid(), 'queue_prefix' => 'OL', 'is_active' => false])->save();
        ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->other->id, 'service_name' => 'Cabang Lain', 'queue_prefix' => 'ZZ', 'priority_enabled' => true, 'is_active' => true]);
        [$token] = $this->pair('KIOSK');

        $config = $this->as($token, 'GET', '/api/v1/kiosk/config')->assertOk()->assertJsonPath('data.branch.branch_name', 'Kiosk Branch')->json('data');
        $this->assertSame(['Customer Service'], array_column($config['services'], 'service_name'));
        $this->assertTrue($config['availability']['is_open']);
    }

    public function test_kiosk_issues_a_ticket_in_its_own_branch_and_cannot_claim_priority_or_switch_branch(): void
    {
        $otherService = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->other->id, 'service_name' => 'CS Lain', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
        [$token] = $this->pair('KIOSK');

        $ticket = $this->as($token, 'POST', '/api/v1/kiosk/queues', ['service_type_id' => $this->service->id, 'customer_name' => 'Budi', 'customer_phone' => '0811-222-333', 'branch_id' => $this->other->id, 'priority_type_id' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.queue.queue_number', 'CS-001')->assertJsonPath('data.branch_name', 'Kiosk Branch')->assertJsonStructure(['data' => ['token', 'position', 'estimated_wait_seconds']])->json('data');

        $queue = Queue::firstOrFail();
        $this->assertSame($this->branch->id, $queue->branch_id, 'cabang selalu dari perangkat, bukan dari input');
        $this->assertSame('WALK_IN', $queue->registration_channel);
        $this->assertNull($queue->priority_type_id);
        $this->assertSame(96, strlen($ticket['token']));
        $this->getJson('/api/v1/queues/track/'.$ticket['token'])->assertOk()->assertJsonPath('data.queue_number', 'CS-001');

        $this->as($token, 'POST', '/api/v1/kiosk/queues', ['service_type_id' => $otherService->id, 'customer_name' => 'Salah Cabang'])->assertStatus(422);
        $this->as($token, 'POST', '/api/v1/kiosk/queues', ['service_type_id' => $this->service->id, 'customer_name' => 'B'])->assertStatus(422)->assertJsonValidationErrors('customer_name');
    }

    public function test_kiosk_ticket_is_auto_assigned_when_an_operator_is_available(): void
    {
        $staff = $this->user('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $staff->id, 'employee_code' => 'E1', 'employee_name' => 'Budi', 'is_active' => true]);
        $employee->serviceTypes()->attach($this->service->id);
        $employee->counters()->attach(Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'CS-01', 'counter_name' => 'Loket 1', 'counter_type' => 'CS', 'is_active' => true])->id);
        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk();
        $this->app['auth']->forgetGuards();
        [$token] = $this->pair('KIOSK');

        $this->as($token, 'POST', '/api/v1/kiosk/queues', ['service_type_id' => $this->service->id, 'customer_name' => 'Pelanggan'])->assertCreated()->assertJsonPath('data.queue.status', 'ASSIGNED')->assertJsonPath('data.estimated_wait_seconds', 0);
    }

    public function test_display_and_kiosk_tokens_cannot_cross_over_and_neither_can_use_staff_endpoints(): void
    {
        [$kiosk] = $this->pair('KIOSK');
        [$display] = $this->pair('DISPLAY');

        $this->as($kiosk, 'GET', '/api/v1/display/snapshot')->assertForbidden();
        $this->as($display, 'GET', '/api/v1/kiosk/config')->assertForbidden();
        $this->as($display, 'POST', '/api/v1/kiosk/queues', ['service_type_id' => $this->service->id, 'customer_name' => 'Budi'])->assertForbidden();
        foreach ([$kiosk, $display] as $token) {
            $this->as($token, 'GET', '/api/v1/queues/history')->assertForbidden();
            $this->as($token, 'POST', '/api/v1/queues/walk-in', ['branch_id' => $this->branch->id, 'service_type_id' => $this->service->id, 'customer_name' => 'Budi'])->assertForbidden();
            $this->as($token, 'GET', '/api/v1/auth/me')->assertForbidden();
        }
        $this->assertSame(0, Queue::count());
    }

    public function test_kiosk_cannot_subscribe_to_realtime_channels_but_display_can(): void
    {
        [$kiosk] = $this->pair('KIOSK');
        [$display] = $this->pair('DISPLAY');
        $channel = 'private-branch.'.$this->branch->id;

        $this->as($kiosk, 'POST', '/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel])->assertForbidden();
        $this->as($display, 'POST', '/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel])->assertOk();
    }

    public function test_refresh_keeps_the_device_type_and_revoked_kiosk_loses_access(): void
    {
        [$token, $id] = $this->pair('KIOSK');
        $refreshed = $this->as($token, 'POST', '/api/v1/display/refresh')->assertOk()->assertJsonPath('data.device.device_type', 'KIOSK')->json('data.token');
        $this->as($refreshed, 'GET', '/api/v1/kiosk/config')->assertOk();

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson("/api/v1/display-devices/{$id}/revoke")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->as($refreshed, 'GET', '/api/v1/kiosk/config')->assertUnauthorized();
    }

    public function test_kiosk_requests_are_rate_limited_per_device(): void
    {
        [$token] = $this->pair('KIOSK');
        $statuses = [];
        foreach (range(1, 32) as $i) {
            $statuses[] = $this->as($token, 'POST', '/api/v1/kiosk/queues', ['service_type_id' => $this->service->id, 'customer_name' => "Pelanggan {$i}"])->status();
        }
        $this->assertSame(30, count(array_filter($statuses, fn ($s) => $s === 201)));
        $this->assertSame([429, 429], array_slice($statuses, 30));
    }

    public function test_walk_in_endpoint_requires_an_authorized_staff_member_in_the_same_branch(): void
    {
        $payload = ['branch_id' => $this->branch->id, 'service_type_id' => $this->service->id, 'customer_name' => 'Budi'];
        $this->postJson('/api/v1/queues/walk-in', $payload)->assertUnauthorized();

        Sanctum::actingAs($this->user('Customer Service'));
        $this->postJson('/api/v1/queues/walk-in', $payload)->assertForbidden();

        Sanctum::actingAs($this->user('Admin Cabang', $this->other));
        $this->postJson('/api/v1/queues/walk-in', $payload)->assertStatus(422); // cabang bukan miliknya

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson('/api/v1/queues/walk-in', $payload)->assertCreated()->assertJsonPath('data.queue.queue_number', 'CS-001');
        $this->assertSame(1, Queue::count());
    }
}
