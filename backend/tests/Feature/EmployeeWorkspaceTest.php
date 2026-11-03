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

class EmployeeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ServiceType $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'WS001', 'branch_name' => 'Workspace Branch', 'is_active' => true]);
        $this->service = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
    }

    private function operator(string $name, string $counterCode, ?Branch $branch = null): array
    {
        $branch ??= $this->branch;
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'name' => $name, 'email' => Str::slug($name).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'user_id' => $user->id, 'employee_code' => strtoupper(Str::random(5)), 'employee_name' => $name, 'is_active' => true]);
        $counter = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'counter_code' => $counterCode, 'counter_name' => 'Loket '.$counterCode, 'counter_type' => 'CS', 'is_active' => true]);
        $employee->serviceTypes()->attach($this->service->id);
        $employee->counters()->attach($counter->id);

        return [$user, $employee];
    }

    private function register(string $name): string
    {
        return $this->registerQueue(['branch_id' => $this->branch->id, 'service_type_id' => $this->service->id, 'customer_name' => $name])->assertCreated()->json('data.queue.id');
    }

    public function test_operator_goes_online_gets_waiting_queue_and_completes_full_cycle(): void
    {
        [$user] = $this->operator('Budi', 'CS-01');
        $first = $this->register('Pelanggan Satu');
        $second = $this->register('Pelanggan Dua');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/employee/workspace')->assertOk()->assertJsonPath('data.status', 'OFFLINE')->assertJsonPath('data.active', null)->assertJsonPath('data.waiting_count', 2);

        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk()
            ->assertJsonPath('data.status', 'BUSY')->assertJsonPath('data.active.queue_id', $first)->assertJsonPath('data.active.status', 'ASSIGNED')
            ->assertJsonPath('data.active.counter.counter_code', 'CS-01')->assertJsonPath('data.waiting_count', 1);

        $this->postJson("/api/v1/service/{$first}/start")->assertStatus(409);
        $this->postJson("/api/v1/service/{$first}/call")->assertOk()->assertJsonPath('data.active.status', 'CALLED');
        $this->postJson("/api/v1/service/{$first}/recall")->assertOk()->assertJsonPath('data.active.status', 'CALLED');
        $this->assertDatabaseCount('queue_call_logs', 2);
        $this->assertDatabaseHas('queue_call_logs', ['queue_id' => $first, 'call_type' => 'RECALL']);
        $this->postJson("/api/v1/service/{$first}/start")->assertOk()->assertJsonPath('data.active.status', 'SERVING');
        $this->postJson('/api/v1/employee/status', ['status' => 'BREAK'])->assertStatus(409);

        $this->postJson("/api/v1/service/{$first}/complete")->assertOk()
            ->assertJsonPath('data.active.queue_id', $second)->assertJsonPath('data.status', 'BUSY')->assertJsonPath('data.waiting_count', 0);
        $this->assertDatabaseHas('queues', ['id' => $first, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('service_sessions', ['queue_id' => $first, 'outcome' => 'COMPLETED']);
    }

    public function test_skip_releases_operator_and_next_queue_is_assigned(): void
    {
        [$user] = $this->operator('Sari', 'CS-01');
        $first = $this->register('A');
        $second = $this->register('B');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk();

        $this->postJson("/api/v1/service/{$first}/call")->assertOk();
        $this->postJson("/api/v1/service/{$first}/skip")->assertOk()->assertJsonPath('data.active.queue_id', $second);
        $this->assertDatabaseHas('queues', ['id' => $first, 'status' => 'SKIPPED']);
    }

    public function test_heartbeat_does_not_override_break(): void
    {
        [$user] = $this->operator('Dewi', 'CS-01');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk();
        $this->postJson('/api/v1/employee/status', ['status' => 'BREAK'])->assertOk()->assertJsonPath('data.status', 'BREAK');
        $this->postJson('/api/v1/employee/heartbeat')->assertOk()->assertJsonPath('data.status', 'BREAK');
        $register = $this->register('Pelanggan');
        $this->postJson('/api/v1/employee/heartbeat')->assertOk()->assertJsonPath('data.active', null);
        $this->assertDatabaseHas('queues', ['id' => $register, 'status' => 'WAITING']);

        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk()->assertJsonPath('data.active.queue_id', $register);
    }

    public function test_operator_cannot_act_on_queue_assigned_to_colleague(): void
    {
        [$userA] = $this->operator('Andi', 'CS-01');
        [$userB] = $this->operator('Bima', 'CS-02');
        $queue = $this->register('Pelanggan');
        Sanctum::actingAs($userA);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk()->assertJsonPath('data.active.queue_id', $queue);

        Sanctum::actingAs($userB);
        $this->postJson("/api/v1/service/{$queue}/call")->assertForbidden();
        $this->patchJson("/api/v1/queues/{$queue}/status", ['status' => 'CALLED'])->assertForbidden();
        $this->postJson('/api/v1/employee/status', ['status' => 'BREAK'])->assertOk();
        $this->postJson('/api/v1/employees/'.Employee::query()->where('user_id', $userA->id)->value('id').'/status', ['status' => 'OFFLINE'])->assertForbidden();
    }

    public function test_operator_history_is_limited_to_own_branch_and_competent_services(): void
    {
        [$user] = $this->operator('Citra', 'CS-01');
        $tellerService = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'service_name' => 'Teller', 'queue_prefix' => 'TL', 'priority_enabled' => true, 'is_active' => true]);
        $this->register('CS Customer');
        $this->registerQueue(['branch_id' => $this->branch->id, 'service_type_id' => $tellerService->id, 'customer_name' => 'Teller Customer'])->assertCreated();
        Sanctum::actingAs($user);

        $numbers = collect($this->getJson('/api/v1/queues/history')->assertOk()->json('data'))->pluck('queue_number');
        $this->assertSame(['CS-001'], $numbers->all());
    }

    public function test_admin_can_link_counters_to_employee(): void
    {
        [, $employee] = $this->operator('Eka', 'CS-01');
        $counter = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'CS-09', 'counter_name' => 'Loket 9', 'counter_type' => 'CS', 'is_active' => true]);
        $admin = User::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => Hash::make('password123')]);
        $admin->assignRole('Admin Cabang');
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/employees/{$employee->id}/counters", ['counter_ids' => [$counter->id]])->assertOk()->assertJsonPath('data.counters.0.counter_code', 'CS-09');

        $other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'WS002', 'branch_name' => 'Other', 'is_active' => true]);
        $foreign = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $other->id, 'counter_code' => 'X-01', 'counter_name' => 'Foreign', 'counter_type' => 'CS', 'is_active' => true]);
        $this->putJson("/api/v1/employees/{$employee->id}/counters", ['counter_ids' => [$foreign->id]])->assertStatus(422);
    }

    public function test_broadcast_channel_auth_works_with_bearer_token_and_is_branch_scoped(): void
    {
        [$user] = $this->operator('Fajar', 'CS-01');
        $token = $user->createToken('test')->plainTextToken;
        $other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'WS003', 'branch_name' => 'Other', 'is_active' => true]);

        $this->withToken($token)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-branch.'.$this->branch->id])->assertOk()->assertJsonStructure(['auth']);
        $this->withToken($token)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-branch.'.$other->id])->assertForbidden();
    }

    public function test_broadcast_channel_auth_rejects_unauthenticated_request(): void
    {
        $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-branch.'.$this->branch->id])->assertUnauthorized();
    }
}
