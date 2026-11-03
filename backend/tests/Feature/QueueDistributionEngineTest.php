<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\EmployeeStatusLog;
use App\Models\Permission;
use App\Models\Queue;
use App\Models\QueueAssignment;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueDistributionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function setupBranch(): array
    {
        $branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => fake()->unique()->bothify('DST###'), 'branch_name' => 'Distribution Branch', 'is_active' => true]);
        $service = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
        $counterOne = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'counter_code' => 'CS-01', 'counter_name' => 'Loket 1', 'counter_type' => 'CS', 'is_active' => true]);
        $counterTwo = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'counter_code' => 'CS-02', 'counter_name' => 'Loket 2', 'counter_type' => 'CS', 'is_active' => true]);

        return [$branch, $service, $counterOne, $counterTwo];
    }

    private function employee(string $branchId, string $serviceId, string $counterId, string $status = 'AVAILABLE'): Employee
    {
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $branchId, 'employee_code' => fake()->unique()->bothify('EMP###'), 'employee_name' => 'Petugas '.Str::upper(Str::random(4)), 'is_active' => true]);
        $employee->serviceTypes()->attach($serviceId);
        $employee->counters()->attach($counterId);
        EmployeeStatusLog::create(['id' => (string) Str::orderedUuid(), 'branch_id' => $branchId, 'employee_id' => $employee->id, 'from_status' => null, 'to_status' => $status, 'reason' => 'Test status', 'heartbeat_at' => $status === 'AVAILABLE' ? now() : null, 'recorded_at' => now()]);

        return $employee;
    }

    private function supervisor(string $branchId): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $branchId, 'name' => 'Supervisor', 'email' => fake()->unique()->safeEmail(), 'password' => Hash::make('password123')]);
        $role = Role::firstOrCreate(['name' => 'Distribution Supervisor', 'guard_name' => 'web'], ['id' => (string) Str::uuid()]);
        foreach (['queues.assign', 'queues.view', 'queues.transfer', 'queues.call', 'queues.start', 'queues.complete', 'employees.status'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['id' => (string) Str::uuid()]));
        }
        $user->assignRole($role);

        return $user;
    }

    private function register(Branch $branch, ServiceType $service, string $name): Queue
    {
        $response = $this->registerQueue(['branch_id' => $branch->id, 'service_type_id' => $service->id, 'customer_name' => $name]);
        $response->assertCreated();

        return Queue::query()->where('customer_name', $name)->firstOrFail();
    }

    public function test_round_robin_skips_break_and_busy_and_uses_competent_available_employee(): void
    {
        [$branch, $service, $counterOne, $counterTwo] = $this->setupBranch();
        $available = $this->employee($branch->id, $service->id, $counterOne->id, 'AVAILABLE');
        $this->employee($branch->id, $service->id, $counterTwo->id, 'BREAK');
        $staff = $this->supervisor($branch->id);

        $queue = $this->register($branch, $service, 'Eligible');

        $this->assertDatabaseHas('queue_assignments', ['queue_id' => $queue->id, 'employee_id' => $available->id, 'assignment_type' => 'AUTO', 'ended_at' => null]);
        $this->assertDatabaseHas('employee_status_logs', ['employee_id' => $available->id, 'to_status' => 'BUSY']);
    }

    public function test_round_robin_cursor_moves_to_next_available_employee_after_completion(): void
    {
        [$branch, $service, $counterOne, $counterTwo] = $this->setupBranch();
        $firstEmployee = $this->employee($branch->id, $service->id, $counterOne->id);
        $secondEmployee = $this->employee($branch->id, $service->id, $counterTwo->id, 'BREAK');
        $staff = $this->supervisor($branch->id);
        $firstQueue = $this->register($branch, $service, 'First');
        $secondQueue = $this->register($branch, $service, 'Second');
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/employees/'.$secondEmployee->id.'/status', ['status' => 'AVAILABLE'])->assertOk();

        $this->actingAs($staff, 'sanctum')->patchJson('/api/v1/queues/'.$firstQueue->id.'/status', ['status' => Queue::CALLED])->assertOk();
        $this->actingAs($staff, 'sanctum')->patchJson('/api/v1/queues/'.$firstQueue->id.'/status', ['status' => Queue::SERVING])->assertOk();
        $this->actingAs($staff, 'sanctum')->patchJson('/api/v1/queues/'.$firstQueue->id.'/status', ['status' => Queue::COMPLETED])->assertOk();

        $this->assertDatabaseHas('queue_assignments', ['queue_id' => $firstQueue->id, 'employee_id' => $firstEmployee->id]);
        $this->assertDatabaseHas('queue_assignments', ['queue_id' => $secondQueue->id, 'employee_id' => $secondEmployee->id]);
        $this->assertDatabaseHas('service_sessions', ['queue_id' => $firstQueue->id, 'employee_id' => $firstEmployee->id, 'outcome' => Queue::COMPLETED]);
        $this->assertDatabaseHas('employee_status_logs', ['employee_id' => $firstEmployee->id, 'to_status' => 'AVAILABLE', 'reason' => 'Queue assignment released']);
    }

    public function test_manual_assignment_requires_competency_and_available_counter(): void
    {
        [$branch, $service, $counterOne] = $this->setupBranch();
        $employee = $this->employee($branch->id, $service->id, $counterOne->id, 'BREAK');
        $staff = $this->supervisor($branch->id);
        $queue = $this->register($branch, $service, 'Manual');

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/queues/'.$queue->id.'/assign', ['employee_id' => $employee->id, 'counter_id' => $counterOne->id, 'reason' => 'Supervisor assignment'])->assertStatus(409);
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/employees/'.$employee->id.'/status', ['status' => 'AVAILABLE'])->assertOk();
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/queues/'.$queue->id.'/assign', ['employee_id' => $employee->id, 'counter_id' => $counterOne->id, 'reason' => 'Supervisor assignment'])->assertCreated()->assertJsonPath('data.assignment_type', 'MANUAL');
    }

    public function test_branch_isolation_prevents_cross_branch_assignment(): void
    {
        [$branchA, $serviceA, $counterA] = $this->setupBranch();
        [$branchB, $serviceB, $counterB] = $this->setupBranch();
        $employeeA = $this->employee($branchA->id, $serviceA->id, $counterA->id);
        $this->employee($branchB->id, $serviceB->id, $counterB->id);
        $queueA = $this->register($branchA, $serviceA, 'Branch A');

        $this->assertDatabaseHas('queue_assignments', ['queue_id' => $queueA->id, 'employee_id' => $employeeA->id, 'branch_id' => $branchA->id]);
        $this->assertDatabaseMissing('queue_assignments', ['queue_id' => $queueA->id, 'branch_id' => $branchB->id]);
    }

    public function test_one_employee_cannot_have_two_active_assignments_or_sessions(): void
    {
        [$branch, $service, $counterOne] = $this->setupBranch();
        $employee = $this->employee($branch->id, $service->id, $counterOne->id);
        $firstQueue = $this->register($branch, $service, 'One');
        $secondQueue = Queue::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'service_type_id' => $service->id, 'queue_date' => now()->toDateString(), 'sequence_no' => 99, 'queue_number' => 'CS-099', 'customer_name' => 'Two', 'registration_channel' => 'ONLINE', 'status' => Queue::WAITING, 'token_hash' => hash('sha256', Str::random(48))]);

        $this->assertDatabaseCount('queue_assignments', 1);
        $this->assertDatabaseMissing('queue_assignments', ['queue_id' => $secondQueue->id, 'employee_id' => $employee->id, 'ended_at' => null]);
        ServiceSession::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'queue_id' => $firstQueue->id, 'employee_id' => $employee->id, 'counter_id' => $counterOne->id, 'started_at' => now()]);
        $this->expectException(QueryException::class);
        ServiceSession::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'queue_id' => $secondQueue->id, 'employee_id' => $employee->id, 'counter_id' => $counterOne->id, 'started_at' => now()]);
    }

    public function test_database_guard_rejects_a_second_active_assignment_for_same_employee(): void
    {
        [$branch, $service, $counterOne] = $this->setupBranch();
        $employee = $this->employee($branch->id, $service->id, $counterOne->id);
        $firstQueue = $this->register($branch, $service, 'Assignment One');
        $secondQueue = Queue::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'service_type_id' => $service->id, 'queue_date' => now()->toDateString(), 'sequence_no' => 98, 'queue_number' => 'CS-098', 'customer_name' => 'Assignment Two', 'registration_channel' => 'ONLINE', 'status' => Queue::WAITING, 'token_hash' => hash('sha256', Str::random(48))]);

        $this->expectException(QueryException::class);
        QueueAssignment::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'queue_id' => $secondQueue->id, 'employee_id' => $employee->id, 'counter_id' => $counterOne->id, 'assignment_type' => 'AUTO', 'assigned_at' => now()]);
        $this->assertDatabaseHas('queue_assignments', ['queue_id' => $firstQueue->id, 'employee_id' => $employee->id]);
    }
}
