<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\EmployeeStatusLog;
use App\Models\Queue;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\QueueDistributionEngine;
use App\Services\QueueManagementService;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Parallel;
use Tests\TestCase;

/**
 * Tes race condition sungguhan: berjalan di PostgreSQL (schema *_test) dengan proses PHP terpisah.
 * Dilewati otomatis di SQLite karena SQLite tidak mendukung row locking / advisory lock.
 *
 * Jalankan: php vendor/bin/phpunit --configuration phpunit.pgsql.xml --group=concurrency
 */
#[Group('concurrency')]
class ConcurrencyTest extends TestCase
{
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Tes concurrency membutuhkan PostgreSQL (php vendor/bin/phpunit --configuration phpunit.pgsql.xml --group=concurrency).');
        }
        // Data harus ter-commit agar terlihat oleh proses anak, jadi tidak memakai RefreshDatabase (transaksi).
        $this->artisan('migrate', ['--force' => true]);
        $this->wipe();
        // Hindari pengiriman event ke Reverb sungguhan dari proses uji (parent dan anak).
        foreach (['BROADCAST_CONNECTION' => 'null'] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        config(['broadcasting.default' => 'null']);
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'CC001', 'branch_name' => 'Concurrency Branch', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->wipe();
        }
        parent::tearDown();
    }

    /** Mengosongkan seluruh tabel di schema uji (bukan schema public). */
    private function wipe(): void
    {
        $schema = DB::selectOne('select current_schema() as s')->s;
        $this->assertStringEndsWith('_test', $schema, 'wipe hanya boleh pada schema *_test');
        $tables = collect(DB::select('select tablename from pg_tables where schemaname = ?', [$schema]))->pluck('tablename')->reject(fn ($t) => $t === 'migrations')->map(fn ($t) => '"'.$schema.'"."'.$t.'"');
        if ($tables->isNotEmpty()) {
            DB::statement('TRUNCATE '.$tables->implode(', ').' RESTART IDENTITY CASCADE');
        }
    }

    // ------------------------------------------------------------------ data helpers

    private function service(string $code = 'CS', ?string $prefix = null): ServiceType
    {
        return ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'service_code' => $code, 'service_name' => 'Layanan '.$code, 'queue_prefix' => $prefix ?? $code, 'priority_enabled' => true, 'is_active' => true]);
    }

    /** Petugas tersedia (AVAILABLE, heartbeat segar) lengkap dengan akun, kompetensi, dan loket. */
    private function employee(string $code, ServiceType $service, ?Counter $counter = null): array
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'name' => 'Petugas '.$code, 'email' => strtolower($code).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $user->id, 'employee_code' => $code, 'employee_name' => 'Petugas '.$code, 'is_active' => true]);
        $counter ??= Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'L-'.$code, 'counter_name' => 'Loket '.$code, 'counter_type' => 'CS', 'is_active' => true]);
        $employee->serviceTypes()->attach($service->id);
        $employee->counters()->attach($counter->id);
        EmployeeStatusLog::create(['id' => (string) Str::orderedUuid(), 'branch_id' => $this->branch->id, 'employee_id' => $employee->id, 'from_status' => null, 'to_status' => 'AVAILABLE', 'reason' => 'uji', 'heartbeat_at' => now(), 'recorded_at' => now()]);

        return [$user, $employee, $counter];
    }

    private function waitingQueues(ServiceType $service, int $count): array
    {
        $manager = app(QueueManagementService::class);

        return collect(range(1, $count))->map(fn ($i) => $manager->register(['branch_id' => $this->branch->id, 'service_type_id' => $service->id, 'customer_name' => "Pelanggan {$i}"], 'WALK_IN')['queue']->id)->all();
    }

    private function assertNoDoubleBookings(): void
    {
        $dupes = fn (string $column) => DB::table('queue_assignments')->whereNull('ended_at')->groupBy($column)->havingRaw('count(*) > 1')->pluck($column)->all();
        $this->assertSame([], $dupes('queue_id'), 'satu antrian memiliki lebih dari satu assignment aktif');
        $this->assertSame([], $dupes('employee_id'), 'satu petugas memiliki lebih dari satu assignment aktif');
        $this->assertSame([], $dupes('counter_id'), 'satu loket melayani lebih dari satu antrian');
    }

    private function assertNoErrors(array $results): void
    {
        $errors = collect($results)->where('ok', false)->pluck('error')->all();
        $this->assertSame([], $errors, 'proses paralel tidak boleh gagal dengan error tak terduga');
    }

    // ------------------------------------------------------------------ pabrik task (statis, hanya menangkap ID skalar)

    private static function register(string $branchId, string $serviceId, string $channel, int $n, ?string $phone = null): Closure
    {
        return static function () use ($branchId, $serviceId, $channel, $n, $phone): array {
            $queue = app(QueueManagementService::class)->register(['branch_id' => $branchId, 'service_type_id' => $serviceId, 'customer_name' => "Pelanggan {$n}", 'customer_phone' => $phone], $channel)['queue'];

            return ['id' => $queue->id, 'number' => $queue->queue_number];
        };
    }

    private static function assignNext(string $branchId, string $serviceId): Closure
    {
        return static function () use ($branchId, $serviceId): ?array {
            $assignment = app(QueueDistributionEngine::class)->assignNext($branchId, $serviceId);

            return $assignment ? ['queue_id' => $assignment->queue_id, 'employee_id' => $assignment->employee_id] : null;
        };
    }

    private static function complete(string $queueId, string $userId, string $branchId): Closure
    {
        return static function () use ($queueId, $userId, $branchId): string {
            // Sama seperti controller: selesaikan layanan lalu jalankan auto-assign.
            app(QueueManagementService::class)->changeStatus(Queue::find($queueId), 'COMPLETED', User::find($userId));
            app(QueueDistributionEngine::class)->assignPending($branchId);

            return 'done';
        };
    }

    private static function callQueue(string $queueId, string $userId): Closure
    {
        return static fn (): string => app(QueueManagementService::class)->changeStatus(Queue::find($queueId), 'CALLED', User::find($userId))->status;
    }

    private static function cancelQueue(string $queueId, string $userId): Closure
    {
        return static fn (): string => app(QueueManagementService::class)->cancel(Queue::find($queueId), User::find($userId), null, 'batal')->status;
    }

    // ------------------------------------------------------------------ penomoran

    public function test_simultaneous_registrations_get_unique_gapless_numbers_per_service(): void
    {
        $cs = $this->service('CS')->id;
        $tl = $this->service('TL')->id;
        $tasks = [];
        foreach (range(1, 12) as $i) {
            $tasks[] = self::register($this->branch->id, $i % 2 === 0 ? $cs : $tl, $i % 3 === 0 ? 'ONLINE' : 'WALK_IN', $i);
        }

        $results = Parallel::run($tasks);

        $this->assertNoErrors($results);
        $numbers = collect($results)->pluck('value.number')->sort()->values()->all();
        $this->assertSame(['CS-001', 'CS-002', 'CS-003', 'CS-004', 'CS-005', 'CS-006', 'TL-001', 'TL-002', 'TL-003', 'TL-004', 'TL-005', 'TL-006'], $numbers);
        $this->assertSame(12, Queue::count());
        $this->assertSame(6, (int) DB::table('queue_sequences')->where('service_type_id', $cs)->value('last_number'));
    }

    public function test_online_quota_is_never_exceeded_under_concurrency(): void
    {
        $serviceId = $this->service()->id;
        DB::table('queue_settings')->insert(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'online_daily_quota' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $tasks = array_map(fn (int $i) => self::register($this->branch->id, $serviceId, 'ONLINE', $i), range(1, 10));

        $results = Parallel::run($tasks);

        $this->assertSame(3, collect($results)->where('ok', true)->count(), 'tepat sesuai kuota yang boleh berhasil: '.json_encode($results));
        $this->assertSame(7, collect($results)->where('ok', false)->filter(fn ($r) => str_contains($r['error'], 'ValidationException'))->count(), 'sisanya ditolak oleh validasi kuota, bukan error lain');
        $this->assertSame(3, Queue::where('registration_channel', 'ONLINE')->count());
    }

    public function test_same_phone_cannot_register_twice_even_simultaneously(): void
    {
        $serviceId = $this->service()->id;
        $tasks = array_map(fn (int $i) => self::register($this->branch->id, $serviceId, 'ONLINE', $i, '081234567890'), range(1, 6));

        $results = Parallel::run($tasks);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(1, Queue::where('customer_phone', '081234567890')->count());
    }

    public function test_same_phone_cannot_walk_in_twice_simultaneously(): void
    {
        $serviceId = $this->service()->id;
        $tasks = array_map(fn (int $i) => self::register($this->branch->id, $serviceId, 'WALK_IN', $i, '081299990000'), range(1, 6));

        $results = Parallel::run($tasks);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(1, Queue::where('customer_phone', '081299990000')->count());
    }

    // ------------------------------------------------------------------ assignment

    public function test_one_queue_never_gets_two_assignments(): void
    {
        $service = $this->service();
        foreach (['A', 'B', 'C', 'D'] as $code) {
            $this->employee($code, $service);
        }
        [$queueId] = $this->waitingQueues($service, 1);
        $tasks = array_map(fn () => self::assignNext($this->branch->id, $service->id), range(1, 8));

        $results = Parallel::run($tasks);

        $this->assertNoErrors($results);
        $this->assertSame(1, collect($results)->pluck('value')->filter()->count(), 'hanya satu proses yang berhasil meng-assign');
        $this->assertSame(1, DB::table('queue_assignments')->where('queue_id', $queueId)->whereNull('ended_at')->count());
        $this->assertSame('ASSIGNED', Queue::find($queueId)->status);
        $this->assertNoDoubleBookings();
    }

    public function test_one_employee_never_serves_two_customers(): void
    {
        $service = $this->service();
        [, $employee] = $this->employee('A', $service);
        $this->waitingQueues($service, 6);
        $tasks = array_map(fn () => self::assignNext($this->branch->id, $service->id), range(1, 8));

        $results = Parallel::run($tasks);

        $this->assertNoErrors($results);
        $this->assertSame(1, DB::table('queue_assignments')->where('employee_id', $employee->id)->whereNull('ended_at')->count());
        $this->assertSame(5, Queue::where('status', 'WAITING')->count());
        $this->assertNoDoubleBookings();
    }

    public function test_one_counter_never_serves_two_customers_at_once(): void
    {
        $service = $this->service();
        $shared = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'SHARED', 'counter_name' => 'Loket Bersama', 'counter_type' => 'CS', 'is_active' => true]);
        foreach (['A', 'B', 'C'] as $code) {
            $this->employee($code, $service, $shared);
        }
        $this->waitingQueues($service, 3);
        $tasks = array_map(fn () => self::assignNext($this->branch->id, $service->id), range(1, 8));

        $results = Parallel::run($tasks);

        $this->assertNoErrors($results);
        $this->assertSame(1, DB::table('queue_assignments')->where('counter_id', $shared->id)->whereNull('ended_at')->count());
        $this->assertSame(2, Queue::where('status', 'WAITING')->count());
        $this->assertNoDoubleBookings();
    }

    public function test_round_robin_spreads_simultaneous_assignments_across_employees(): void
    {
        $service = $this->service();
        $employees = collect(['A', 'B', 'C'])->map(fn ($code) => $this->employee($code, $service)[1]->id);
        $this->waitingQueues($service, 3);
        $tasks = array_map(fn () => self::assignNext($this->branch->id, $service->id), range(1, 6));

        $results = Parallel::run($tasks);

        $this->assertNoErrors($results);
        $assigned = collect($results)->pluck('value.employee_id')->filter()->all();
        $this->assertCount(3, $assigned);
        $this->assertEqualsCanonicalizing($employees->all(), $assigned, 'setiap petugas mendapat tepat satu antrian');
        $this->assertNoDoubleBookings();
    }

    // ------------------------------------------------------------------ penyelesaian pelayanan

    /** Menyiapkan petugas yang sedang SERVING satu antrian, ditambah antrian yang masih menunggu. */
    private function servingSetup(int $employees, int $waiting): array
    {
        $service = $this->service();
        $staff = collect(range(1, $employees))->map(fn ($i) => $this->employee("E{$i}", $service));
        $serving = $this->waitingQueues($service, $employees);
        $engine = app(QueueDistributionEngine::class);
        $manager = app(QueueManagementService::class);
        foreach ($serving as $_) {
            $engine->assignNext($this->branch->id, $service->id);
        }
        foreach (DB::table('queue_assignments')->whereNull('ended_at')->get() as $assignment) {
            $actor = User::find(Employee::find($assignment->employee_id)->user_id);
            $manager->changeStatus(Queue::find($assignment->queue_id), 'CALLED', $actor);
            $manager->changeStatus(Queue::find($assignment->queue_id), 'SERVING', $actor);
        }
        $waitingIds = $this->waitingQueues($service, $waiting);

        return [$service, $staff, $waitingIds];
    }

    public function test_two_employees_completing_at_the_same_time_each_get_exactly_one_new_queue(): void
    {
        [, , $waitingIds] = $this->servingSetup(2, 2);
        $tasks = [];
        foreach (DB::table('queue_assignments')->whereNull('ended_at')->get() as $assignment) {
            $tasks[] = self::complete($assignment->queue_id, Employee::find($assignment->employee_id)->user_id, $this->branch->id);
        }

        $results = Parallel::run($tasks);

        $this->assertNoErrors($results);
        $this->assertSame(2, Queue::where('status', 'COMPLETED')->count());
        $active = DB::table('queue_assignments')->whereNull('ended_at')->get();
        $this->assertCount(2, $active, 'kedua antrian menunggu ter-assign');
        $this->assertEqualsCanonicalizing($waitingIds, $active->pluck('queue_id')->all());
        $this->assertCount(2, $active->pluck('employee_id')->unique(), 'masing-masing ke petugas berbeda');
        $this->assertSame(2, DB::table('service_sessions')->whereNotNull('completed_at')->count());
        $this->assertNoDoubleBookings();
    }

    public function test_completing_the_same_queue_concurrently_succeeds_once(): void
    {
        [, , $waitingIds] = $this->servingSetup(1, 3);
        $assignment = DB::table('queue_assignments')->whereNull('ended_at')->first();
        $userId = Employee::find($assignment->employee_id)->user_id;
        $tasks = array_map(fn () => self::complete($assignment->queue_id, $userId, $this->branch->id), range(1, 4));

        $results = Parallel::run($tasks);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), 'klik ganda: hanya satu yang berhasil');
        $rejected = collect($results)->where('ok', false)->pluck('status')->all();
        $this->assertCount(3, $rejected);
        $this->assertEmpty(array_diff($rejected, [403, 409]), 'sisanya ditolak (antrian sudah selesai/tidak lagi dialokasikan), bukan error server: '.json_encode($results));
        $this->assertSame(1, DB::table('queue_assignments')->whereNull('ended_at')->count(), 'hanya satu antrian baru yang ter-assign');
        $this->assertSame(2, Queue::where('status', 'WAITING')->count());
        $this->assertSame(1, DB::table('service_sessions')->where('queue_id', $assignment->queue_id)->count());
        $this->assertContains(DB::table('queue_assignments')->whereNull('ended_at')->value('queue_id'), $waitingIds);
        $this->assertNoDoubleBookings();
    }

    public function test_customer_cancel_racing_with_staff_call_leaves_consistent_state(): void
    {
        $service = $this->service();
        [$user, $employee] = $this->employee('A', $service);
        $user->assignRole('Supervisor'); // boleh membatalkan sebagai staf
        [$queueId] = $this->waitingQueues($service, 1);
        app(QueueDistributionEngine::class)->assignNext($this->branch->id, $service->id);

        $results = Parallel::run([self::callQueue($queueId, $user->id), self::cancelQueue($queueId, $user->id), self::callQueue($queueId, $user->id), self::cancelQueue($queueId, $user->id)]);

        $final = Queue::find($queueId)->status;
        $this->assertContains($final, ['CANCELLED', 'CALLED']);
        $this->assertEmpty(collect($results)->where('ok', false)->reject(fn ($r) => $r['status'] === 409)->all(), 'kegagalan hanya boleh berupa konflik status 409: '.json_encode($results));
        if ($final === 'CANCELLED') {
            $this->assertSame(0, DB::table('queue_assignments')->whereNull('ended_at')->count(), 'antrian batal tidak boleh menyisakan assignment aktif');
            $this->assertSame('AVAILABLE', app(\App\Services\EmployeeStatusService::class)->current($employee->refresh()), 'petugas kembali tersedia');
        }
        $this->assertNoDoubleBookings();
    }
}
