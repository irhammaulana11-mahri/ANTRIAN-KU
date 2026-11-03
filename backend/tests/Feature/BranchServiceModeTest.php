<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\DisplayDevice;
use App\Models\Employee;
use App\Models\Queue;
use App\Models\ServiceType;
use App\Models\Unit;
use App\Models\User;
use App\Services\QueueDistributionEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Cabang opsional membuka layanan; nomor urut dan distribusi mengikuti titik layanan (unit atau cabang). */
class BranchServiceModeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ServiceType $service;

    private string $unitA;

    private string $unitB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'SM001', 'branch_name' => 'Mode Branch', 'is_active' => true]);
        $this->service = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
        $this->unitA = $this->unit('UA');
        $this->unitB = $this->unit('UB');
    }

    private function unit(string $code): string
    {
        return Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_code' => $code, 'unit_name' => 'Unit '.$code, 'is_active' => true])->id;
    }

    private function user(string $role): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'name' => $role, 'email' => Str::slug($role).Str::random(5).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    private function serveDirectly(bool $value): void
    {
        DB::table('queue_settings')->updateOrInsert(['branch_id' => $this->branch->id], ['id' => (string) Str::uuid(), 'branch_serves_directly' => $value, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function register(?string $unit): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/queues/walk-in', array_filter(['branch_id' => $this->branch->id, 'unit_id' => $unit, 'service_type_id' => $this->service->id, 'customer_name' => 'Tamu '.Str::random(4)]));
    }

    /** Petugas AVAILABLE di titik layanan (unit atau cabang) dengan satu loket. */
    private function operator(?string $unit, string $code): Employee
    {
        $user = $this->user('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_id' => $unit, 'user_id' => $user->id, 'employee_code' => $code, 'employee_name' => $code, 'is_active' => true]);
        $employee->serviceTypes()->attach($this->service->id);
        $employee->counters()->attach(Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_id' => $unit, 'counter_code' => $code, 'counter_name' => 'Loket '.$code, 'counter_type' => 'CS', 'is_active' => true])->id);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk();

        return $employee;
    }

    public function test_each_service_point_has_its_own_numbering(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->assertSame('CS-001', $this->register($this->unitA)->assertCreated()->json('data.queue.queue_number'));
        $this->assertSame('CS-001', $this->register($this->unitB)->assertCreated()->json('data.queue.queue_number'));
        $this->assertSame('CS-001', $this->register(null)->assertCreated()->json('data.queue.queue_number'));
        $this->assertSame('CS-002', $this->register($this->unitA)->assertCreated()->json('data.queue.queue_number'));
        $this->assertSame('CS-002', $this->register(null)->assertCreated()->json('data.queue.queue_number'));
        $this->assertSame(2, Queue::query()->where('unit_id', $this->unitA)->count());
        $this->assertSame(2, Queue::query()->whereNull('unit_id')->count());
    }

    public function test_registration_without_unit_is_rejected_when_branch_does_not_serve_directly(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->register(null)->assertCreated();
        $this->serveDirectly(false);
        $this->register(null)->assertStatus(422)->assertJsonValidationErrors('unit_id');
        $this->register($this->unitA)->assertCreated();
    }

    public function test_queues_are_assigned_only_to_staff_of_the_same_service_point(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $unitQueue = $this->register($this->unitA)->assertCreated()->json('data.queue.id');
        $branchQueue = $this->register(null)->assertCreated()->json('data.queue.id');
        $this->app['auth']->forgetGuards();

        $this->operator($this->unitB, 'OB'); // petugas unit lain: tidak boleh menerima apa pun
        $engine = app(QueueDistributionEngine::class);
        $this->assertNull($engine->assignNext($this->branch->id, $this->service->id));
        $this->assertSame(Queue::WAITING, Queue::find($unitQueue)->status);

        $this->app['auth']->forgetGuards();
        $this->operator($this->unitA, 'OA');
        $engine->assignNext($this->branch->id, $this->service->id);
        $this->assertSame(Queue::ASSIGNED, Queue::find($unitQueue)->status);
        $this->assertSame(Queue::WAITING, Queue::find($branchQueue)->status);

        $this->app['auth']->forgetGuards();
        $this->operator(null, 'OC'); // petugas cabang melayani antrian cabang
        $engine->assignNext($this->branch->id, $this->service->id);
        $this->assertSame(Queue::ASSIGNED, Queue::find($branchQueue)->status);
    }

    public function test_branch_display_shows_only_branch_queues_when_serving_directly(): void
    {
        $counter = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'C1', 'counter_name' => 'Loket', 'counter_type' => 'CS', 'is_active' => true]);
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'employee_code' => 'E1', 'employee_name' => 'Budi', 'is_active' => true]);
        foreach ([[null, 'CS-001'], [$this->unitA, 'CS-002']] as $i => [$unit, $number]) {
            $queue = Queue::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_id' => $unit, 'service_type_id' => $this->service->id, 'queue_date' => now()->toDateString(), 'sequence_no' => $i + 1, 'queue_number' => $number, 'customer_name' => 'P'.$i, 'registration_channel' => 'ONLINE', 'status' => Queue::WAITING, 'token_hash' => hash('sha256', Str::random(48))]);
            DB::table('queue_call_logs')->insert(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'queue_id' => $queue->id, 'employee_id' => $employee->id, 'counter_id' => $counter->id, 'call_type' => 'INITIAL', 'event_id' => (string) Str::uuid(), 'called_at' => now()]);
        }
        $device = DisplayDevice::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'device_code' => 'TV-CAB', 'device_name' => 'TV Cabang', 'device_type' => 'DISPLAY', 'credential_hash' => hash('sha256', 'ABCD2345')]);
        $token = $this->postJson('/api/v1/display/activate', ['credential' => 'ABCD2345'])->assertOk()->json('data.token');

        $this->app['auth']->forgetGuards();
        $data = $this->withToken($token)->getJson('/api/v1/display/snapshot')->assertOk()->assertJsonPath('data.branch_only', true)->json('data');
        $this->assertSame(['CS-001'], array_column($data['calls'], 'queue_number'));

        // Cabang tidak melayani langsung: TV cabang menjadi ringkasan semua unit.
        $this->serveDirectly(false);
        $this->app['auth']->forgetGuards();
        $data = $this->withToken($token)->getJson('/api/v1/display/snapshot')->assertOk()->assertJsonPath('data.branch_only', false)->json('data');
        $this->assertEqualsCanonicalizing(['CS-001', 'CS-002'], array_column($data['calls'], 'queue_number'));
        $this->assertNotNull($device->id);
    }

    public function test_kiosk_without_unit_is_blocked_when_branch_does_not_serve_directly(): void
    {
        DisplayDevice::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'device_code' => 'KS-CAB', 'device_name' => 'Kiosk', 'device_type' => 'KIOSK', 'credential_hash' => hash('sha256', 'KIOS2345')]);
        $token = $this->postJson('/api/v1/display/activate', ['credential' => 'KIOS2345'])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->serveDirectly(false);

        $this->withToken($token)->getJson('/api/v1/kiosk/config')->assertOk()->assertJsonPath('data.availability.is_open', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/kiosk/queues', ['service_type_id' => $this->service->id, 'customer_name' => 'Tamu'])->assertStatus(422);
    }

    public function test_admin_cabang_can_toggle_branch_serves_directly_and_dropdown_reports_it(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->getJson('/api/v1/settings/queue')->assertOk()->assertJsonPath('data.branch_serves_directly', true);
        $this->putJson('/api/v1/settings/queue', ['branch_serves_directly' => false])->assertOk()->assertJsonPath('data.branch_serves_directly', false);
        $this->getJson('/api/v1/units/dropdown')->assertOk()->assertJsonPath('meta.branch_serves_directly', false);

        // Loket wajib unit saat cabang tidak membuka layanan; boleh kosong saat membuka.
        $this->postJson('/api/v1/counters', ['counter_code' => 'LK-1', 'counter_name' => 'Loket', 'counter_type' => 'CS'])->assertStatus(422)->assertJsonValidationErrors('unit_id');
        $this->putJson('/api/v1/settings/queue', ['branch_serves_directly' => true])->assertOk();
        $this->postJson('/api/v1/counters', ['counter_code' => 'LK-1', 'counter_name' => 'Loket', 'counter_type' => 'CS'])->assertCreated();
    }

    public function test_walk_in_unit_must_be_an_active_unit_of_the_same_branch(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'SM002', 'branch_name' => 'Cabang Lain', 'is_active' => true]);
        $foreignUnit = Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $other->id, 'unit_code' => 'UF', 'unit_name' => 'Unit Lain', 'is_active' => true])->id;
        $this->register($foreignUnit)->assertStatus(422)->assertJsonValidationErrors('unit_id');
        Unit::query()->whereKey($this->unitB)->update(['is_active' => false]);
        $this->register($this->unitB)->assertStatus(422)->assertJsonValidationErrors('unit_id');
        $this->register($this->unitA)->assertCreated();
    }
}
