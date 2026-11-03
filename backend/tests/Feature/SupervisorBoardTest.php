<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\EmployeeStatusLog;
use App\Models\PriorityType;
use App\Models\Queue;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupervisorBoardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $other;

    private ServiceType $cs;

    private ServiceType $tl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'SV001', 'branch_name' => 'Supervisor Branch', 'is_active' => true]);
        $this->other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'SV002', 'branch_name' => 'Other', 'is_active' => true]);
        $this->cs = $this->service($this->branch, 'CS');
        $this->tl = $this->service($this->branch, 'TL');
    }

    private function service(Branch $branch, string $code): ServiceType
    {
        return ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'service_name' => 'Layanan '.$code, 'queue_prefix' => $code, 'priority_enabled' => true, 'is_active' => true]);
    }

    private function user(string $role, ?Branch $branch = null, bool $global = false): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $global ? null : ($branch ?? $this->branch)->id, 'name' => $role, 'email' => Str::slug($role).Str::random(5).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    /** Petugas CS (akun + layanan + loket); $available mengatur status awal. */
    private function operator(string $code, ServiceType $service, bool $available = true): array
    {
        $user = $this->user('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $user->id, 'employee_code' => $code, 'employee_name' => 'Petugas '.$code, 'is_active' => true]);
        $counter = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'L-'.$code, 'counter_name' => 'Loket '.$code, 'counter_type' => 'CS', 'is_active' => true]);
        $employee->serviceTypes()->attach($service->id);
        $employee->counters()->attach($counter->id);
        if ($available) {
            EmployeeStatusLog::create(['id' => (string) Str::orderedUuid(), 'branch_id' => $this->branch->id, 'employee_id' => $employee->id, 'from_status' => null, 'to_status' => 'AVAILABLE', 'reason' => 'uji', 'heartbeat_at' => now(), 'recorded_at' => now()]);
        }

        return [$user, $employee, $counter];
    }

    private function register(ServiceType $service, string $name = 'Pelanggan'): string
    {
        return $this->registerQueue(['branch_id' => $service->branch_id, 'service_type_id' => $service->id, 'customer_name' => $name])->assertCreated()->json('data.queue.id');
    }

    public function test_board_requires_assign_permission_and_branch(): void
    {
        Sanctum::actingAs($this->user('Customer Service'));
        $this->getJson('/api/v1/supervisor/board')->assertForbidden();
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->getJson('/api/v1/supervisor/board')->assertForbidden(); // admin bukan operator antrian
        Sanctum::actingAs($this->user('Super Admin', global: true));
        $this->getJson('/api/v1/supervisor/board')->assertStatus(422); // wajib memilih cabang
        $this->getJson('/api/v1/supervisor/board?branch_id='.$this->branch->id)->assertOk();
    }

    public function test_board_lists_active_queues_staff_counters_and_options_for_own_branch_only(): void
    {
        [, $employee] = $this->operator('E1', $this->cs, available: true);
        $this->operator('E2', $this->cs, available: false);
        $queueId = $this->register($this->cs, 'Budi');
        $this->register($this->cs, 'Sari');
        $foreign = $this->service($this->other, 'ZZ');
        $this->register($foreign, 'Orang Asing');
        PriorityType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'priority_code' => 'PRIORITY', 'priority_name' => 'Prioritas', 'weight' => 20, 'aging_rate' => 1, 'aging_interval_seconds' => 300, 'verification_required' => true, 'is_active' => true]);
        Queue::query()->where('customer_name', 'Sari')->update(['status' => 'COMPLETED']); // tidak aktif → tidak tampil

        Sanctum::actingAs($this->user('Admin Unit'));
        $board = $this->getJson('/api/v1/supervisor/board')->assertOk()->json('data');

        $this->assertSame(['Budi'], array_column($board['queues'], 'customer_name'));
        $this->assertSame('ASSIGNED', $board['queues'][0]['status']); // E1 tersedia → langsung ter-assign saat daftar
        $this->assertSame('Petugas E1', $board['queues'][0]['employee']['name']);
        $this->assertSame('L-E1', $board['queues'][0]['counter']['code']);
        $this->assertCount(2, $board['employees']);
        $byCode = collect($board['employees'])->keyBy('employee_code');
        $this->assertSame('BUSY', $byCode['E1']['status']);
        $this->assertSame('CS-001', $byCode['E1']['active_queue_number']);
        $this->assertSame('OFFLINE', $byCode['E2']['status']);
        $this->assertFalse($byCode['E2']['is_available']);
        $this->assertSame([$this->cs->id], $byCode['E1']['service_type_ids']);
        $this->assertSame('Petugas E1', collect($board['counters'])->firstWhere('counter_code', 'L-E1')['in_use_by']);
        $this->assertNull(collect($board['counters'])->firstWhere('counter_code', 'L-E2')['in_use_by']);
        $this->assertEqualsCanonicalizing(['CS', 'TL'], array_column($board['services'], 'queue_prefix'));
        $this->assertContains('PRIORITY', array_column($board['priorities'], 'priority_code'));
        $this->assertStringNotContainsString('Orang Asing', json_encode($board), 'data cabang lain tidak boleh muncul');
        $this->assertNotNull($queueId);
    }

    public function test_supervisor_manually_assigns_a_waiting_queue_to_a_chosen_available_employee(): void
    {
        $queueId = $this->register($this->cs, 'Budi'); // belum ada petugas → WAITING
        [, $employee, $counter] = $this->operator('E1', $this->cs);
        [, $busy] = $this->operator('E2', $this->cs, available: false);
        $supervisor = $this->user('Admin Unit');
        Sanctum::actingAs($supervisor);

        $this->postJson("/api/v1/queues/{$queueId}/assign", ['employee_id' => $busy->id, 'counter_id' => $counter->id, 'reason' => 'uji'])->assertStatus(409); // tidak tersedia
        $this->postJson("/api/v1/queues/{$queueId}/assign", ['employee_id' => $employee->id, 'counter_id' => $counter->id])->assertStatus(422); // alasan wajib
        $this->postJson("/api/v1/queues/{$queueId}/assign", ['employee_id' => $employee->id, 'counter_id' => $counter->id, 'reason' => 'Pelanggan lansia perlu segera dilayani'])->assertCreated()->assertJsonPath('data.assignment_type', 'MANUAL');

        $queue = collect($this->getJson('/api/v1/supervisor/board')->json('data.queues'))->firstWhere('id', $queueId);
        $this->assertSame('ASSIGNED', $queue['status']);
        $this->assertSame($employee->id, $queue['employee']['id']);
        $this->assertDatabaseHas('queue_assignments', ['queue_id' => $queueId, 'assignment_type' => 'MANUAL', 'reason' => 'Pelanggan lansia perlu segera dilayani']);
    }

    public function test_transfer_moves_a_waiting_queue_and_assigns_it_immediately_in_the_target_service(): void
    {
        $queueId = $this->register($this->cs, 'Budi');
        $this->operator('T1', $this->tl); // petugas Teller tersedia
        Sanctum::actingAs($this->user('Admin Unit'));

        $this->postJson("/api/v1/queues/{$queueId}/transfer", ['target_service_type_id' => $this->tl->id])->assertStatus(422); // alasan wajib
        $response = $this->postJson("/api/v1/queues/{$queueId}/transfer", ['target_service_type_id' => $this->tl->id, 'reason' => 'Salah loket'])->assertOk();
        $response->assertJsonPath('data.queue_number', 'TL-001')->assertJsonPath('data.status', 'ASSIGNED'); // langsung dicarikan petugas
        $this->assertDatabaseHas('queue_transitions', ['queue_id' => $queueId, 'reason' => 'Salah loket']);
    }

    public function test_transferring_a_queue_being_served_closes_the_session_frees_the_employee_and_reassigns_them(): void
    {
        [$staff, $employee] = $this->operator('E1', $this->cs);
        $first = $this->register($this->cs, 'Sedang Dilayani'); // ter-assign ke E1
        $second = $this->register($this->cs, 'Menunggu'); // WAITING (E1 sibuk)
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/service/{$first}/call")->assertOk();
        $this->postJson("/api/v1/service/{$first}/start")->assertOk();
        $this->assertSame(1, DB::table('service_sessions')->where('queue_id', $first)->whereNull('completed_at')->count());

        Sanctum::actingAs($this->user('Admin Unit'));
        $this->postJson("/api/v1/queues/{$first}/transfer", ['target_service_type_id' => $this->tl->id, 'reason' => 'Butuh Teller'])->assertOk()->assertJsonPath('data.status', 'WAITING');

        $session = DB::table('service_sessions')->where('queue_id', $first)->first();
        $this->assertNotNull($session->completed_at, 'sesi layanan harus ditutup');
        $this->assertSame('TRANSFERRED', $session->outcome);
        $this->assertSame(0, DB::table('queue_assignments')->where('queue_id', $first)->whereNull('ended_at')->count());
        $this->assertSame(1, DB::table('queue_assignments')->where('queue_id', $second)->whereNull('ended_at')->count(), 'petugas langsung mendapat antrian menunggu');
        $this->assertSame($employee->id, DB::table('queue_assignments')->where('queue_id', $second)->whereNull('ended_at')->value('employee_id'));
        $this->assertNull(Queue::find($first)->called_at);
    }

    public function test_transfer_and_assign_are_branch_scoped(): void
    {
        $foreignService = $this->service($this->other, 'ZZ');
        $foreignQueue = $this->register($foreignService, 'Orang Asing');
        $queueId = $this->register($this->cs, 'Budi');
        Sanctum::actingAs($this->user('Admin Unit'));

        $this->postJson("/api/v1/queues/{$foreignQueue}/transfer", ['target_service_type_id' => $this->tl->id, 'reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/queues/{$queueId}/transfer", ['target_service_type_id' => $foreignService->id, 'reason' => 'x'])->assertNotFound(); // layanan cabang lain
        $this->assertSame($this->cs->id, Queue::find($queueId)->service_type_id);

        Sanctum::actingAs($this->user('Customer Service'));
        $this->postJson("/api/v1/queues/{$queueId}/transfer", ['target_service_type_id' => $this->tl->id, 'reason' => 'x'])->assertForbidden();
    }

    public function test_admin_unit_cannot_touch_queue_of_another_unit_in_same_branch(): void
    {
        $unitA = \App\Models\Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_code' => 'UA', 'unit_name' => 'Unit A', 'is_active' => true]);
        $unitB = \App\Models\Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_code' => 'UB', 'unit_name' => 'Unit B', 'is_active' => true]);
        $queueId = $this->register($this->cs, 'Budi');
        Queue::whereKey($queueId)->update(['unit_id' => $unitB->id]);

        $admin = $this->user('Admin Unit');
        $admin->update(['unit_id' => $unitA->id]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/queues/{$queueId}/transfer", ['target_service_type_id' => $this->tl->id, 'reason' => 'pindah unit lain'])->assertForbidden();
        $this->assertSame($this->cs->id, Queue::find($queueId)->service_type_id);

        $admin->update(['unit_id' => $unitB->id]);
        $this->postJson("/api/v1/queues/{$queueId}/transfer", ['target_service_type_id' => $this->tl->id, 'reason' => 'unit sendiri'])->assertOk();
    }

    public function test_supervisor_verifies_priority_and_the_board_reflects_it(): void
    {
        $queueId = $this->register($this->cs, 'Budi');
        $priority = PriorityType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'priority_code' => 'PRIORITY', 'priority_name' => 'Prioritas', 'weight' => 20, 'aging_rate' => 1, 'aging_interval_seconds' => 300, 'verification_required' => true, 'is_active' => true]);
        Sanctum::actingAs($this->user('Admin Unit'));

        $this->postJson("/api/v1/queues/{$queueId}/verify-priority", ['priority_type_id' => $priority->id, 'reason' => 'Lansia 70 tahun'])->assertOk();
        $row = collect($this->getJson('/api/v1/supervisor/board')->json('data.queues'))->firstWhere('id', $queueId);
        $this->assertSame('PRIORITY', $row['priority']['code']);
        $this->assertTrue($row['priority_verified']);
    }
}
