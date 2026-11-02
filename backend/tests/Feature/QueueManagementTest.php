<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PriorityType;
use App\Models\Queue;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class QueueManagementTest extends TestCase
{
    use RefreshDatabase;

    private function branchWithServices(): array
    {
        $branch = Branch::create(['id' => fake()->uuid(), 'branch_code' => fake()->unique()->bothify('TST###'), 'branch_name' => 'Test Branch', 'is_active' => true]);
        $cs = ServiceType::create(['id' => fake()->uuid(), 'branch_id' => $branch->id, 'service_code' => 'CS', 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
        $teller = ServiceType::create(['id' => fake()->uuid(), 'branch_id' => $branch->id, 'service_code' => 'TL', 'service_name' => 'Teller', 'queue_prefix' => 'TL', 'priority_enabled' => true, 'is_active' => true]);
        PriorityType::create(['id' => fake()->uuid(), 'branch_id' => $branch->id, 'priority_code' => 'NORMAL', 'priority_name' => 'Normal', 'weight' => 0, 'aging_rate' => 1, 'aging_interval_seconds' => 300, 'verification_required' => false, 'is_active' => true]);
        PriorityType::create(['id' => fake()->uuid(), 'branch_id' => $branch->id, 'priority_code' => 'PRIORITY', 'priority_name' => 'Prioritas', 'weight' => 20, 'aging_rate' => 1, 'aging_interval_seconds' => 300, 'verification_required' => true, 'is_active' => true]);

        return [$branch, $cs, $teller];
    }

    private function staff(string $branchId, array $permissions = ['queues.view', 'queues.cancel', 'queues.assign', 'queues.transfer', 'queues.call', 'queues.start', 'queues.complete']): User
    {
        $user = User::create(['id' => fake()->uuid(), 'branch_id' => $branchId, 'name' => 'Operator', 'email' => fake()->unique()->safeEmail(), 'password' => Hash::make('password123')]);
        $role = Role::firstOrCreate(['name' => 'Queue Test Staff', 'guard_name' => 'web'], ['id' => fake()->uuid()]);
        foreach ($permissions as $permissionName) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web'], ['id' => fake()->uuid()]));
        }
        $user->assignRole($role);

        return $user;
    }

    public function test_walk_in_registration_generates_scoped_number(): void
    {
        [$branch, $service] = $this->branchWithServices();
        $staff = $this->staff($branch->id, ['queues.register']);
        $response = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/queues/walk-in', ['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => 'Budi']);
        $response->assertCreated()->assertJsonPath('data.queue.queue_number', 'CS-001')->assertJsonStructure(['data' => ['queue', 'token']]);
        $this->assertDatabaseHas('queues', ['branch_id' => $branch->id, 'sequence_no' => 1, 'status' => Queue::WAITING]);
    }

    public function test_online_registration_and_secure_tracking_work(): void
    {
        [$branch, $service] = $this->branchWithServices();
        $registration = $this->postJson('/api/v1/queues/online', ['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => 'Sari']);
        $registration->assertCreated();
        $token = $registration->json('data.token');
        $this->assertNotEmpty($token);
        $this->getJson('/api/v1/queues/track/'.$token)->assertOk()->assertJsonPath('data.queue_number', 'CS-001')->assertJsonPath('data.status', Queue::WAITING);
        $this->assertArrayNotHasKey('token', $registration->json('data.queue'));
    }

    public function test_repeated_registration_advances_sequence_without_duplicates(): void
    {
        [$branch, $service] = $this->branchWithServices();
        $payload = ['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => 'Customer'];
        $this->postJson('/api/v1/queues/online', $payload)->assertCreated();
        $this->postJson('/api/v1/queues/online', $payload)->assertCreated();
        $this->assertDatabaseCount('queues', 2);
        $this->assertDatabaseHas('queues', ['queue_number' => 'CS-001']);
        $this->assertDatabaseHas('queues', ['queue_number' => 'CS-002']);
    }

    public function test_customer_can_cancel_with_secure_token(): void
    {
        [$branch, $service] = $this->branchWithServices();
        $registration = $this->postJson('/api/v1/queues/online', ['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => 'Rina']);
        $this->postJson('/api/v1/queues/cancel-by-token', ['token' => $registration->json('data.token'), 'reason' => 'Tidak jadi'])->assertOk()->assertJsonPath('data.status', Queue::CANCELLED);
    }

    public function test_public_customer_cannot_claim_priority_without_verification(): void
    {
        [$branch, $service] = $this->branchWithServices();
        $priority = PriorityType::where('branch_id', $branch->id)->where('priority_code', 'PRIORITY')->firstOrFail();
        $this->postJson('/api/v1/queues/online', ['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => 'Claim', 'priority_type_id' => $priority->id])->assertStatus(422)->assertJsonValidationErrors('priority_type_id');
    }

    public function test_priority_requires_staff_verification_and_is_audited(): void
    {
        [$branch, $service] = $this->branchWithServices();
        $registration = $this->postJson('/api/v1/queues/online', ['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => 'Lansia']);
        $queue = Queue::firstOrFail();
        $priority = PriorityType::where('branch_id', $branch->id)->where('priority_code', 'PRIORITY')->firstOrFail();
        $staff = $this->staff($branch->id);
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/queues/'.$queue->id.'/verify-priority', ['priority_type_id' => $priority->id])->assertOk()->assertJsonPath('data.priority_type_id', $priority->id);
        $this->assertDatabaseHas('queue_transitions', ['queue_id' => $queue->id, 'to_status' => Queue::WAITING]);
    }

    public function test_queue_transfer_assigns_a_new_number_in_the_target_service(): void
    {
        [$branch, $source, $target] = $this->branchWithServices();
        $registration = $this->postJson('/api/v1/queues/online', ['branch_id' => $branch->id, 'service_type_id' => $source->id, 'customer_name' => 'Dewi']);
        $queue = Queue::firstOrFail();
        $staff = $this->staff($branch->id);
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/queues/'.$queue->id.'/transfer', ['target_service_type_id' => $target->id, 'reason' => 'Layanan teller'])->assertOk()->assertJsonPath('data.queue_number', 'TL-001')->assertJsonPath('data.status', Queue::WAITING);
    }

    public function test_status_transition_is_validated_and_recorded(): void
    {
        [$branch, $service] = $this->branchWithServices();
        $registration = $this->postJson('/api/v1/queues/online', ['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => 'Status']);
        $queue = Queue::firstOrFail();
        $staff = $this->staff($branch->id);
        foreach ([Queue::SERVING, Queue::ASSIGNED, Queue::CALLED, Queue::COMPLETED] as $invalid) {
            // ASSIGNED hanya boleh dibuat oleh distribution engine, dan CALLED membutuhkan assignment aktif.
            $this->actingAs($staff, 'sanctum')->patchJson('/api/v1/queues/'.$queue->id.'/status', ['status' => $invalid])->assertStatus(409);
        }
        $this->assertDatabaseHas('queues', ['id' => $queue->id, 'status' => Queue::WAITING]);
        $this->actingAs($staff, 'sanctum')->patchJson('/api/v1/queues/'.$queue->id.'/status', ['status' => Queue::CANCELLED])->assertOk();
        $this->assertDatabaseHas('queue_transitions', ['queue_id' => $queue->id, 'from_status' => Queue::WAITING, 'to_status' => Queue::CANCELLED]);
    }

    public function test_history_is_isolated_by_branch(): void
    {
        [$branch, $service] = $this->branchWithServices();
        $this->postJson('/api/v1/queues/online', ['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => 'History']);
        $staff = $this->staff($branch->id, ['queues.view']);
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/queues/history')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
