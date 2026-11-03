<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\PriorityType;
use App\Models\Queue;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationRulesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ServiceType $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Rabu 4 November 2026, 10:00 WIB.
        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', config('app.timezone')));
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'RG001', 'branch_name' => 'Registration Branch', 'is_active' => true]);
        $this->service = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hours(string $opens = '08:00', string $closes = '16:00', bool $closed = false, int $weekday = 3): void
    {
        DB::table('operating_hours')->insert(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'weekday' => $weekday, 'opens_at' => $opens.':00+07', 'closes_at' => $closes.':00+07', 'is_closed' => $closed, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function settings(array $values): void
    {
        DB::table('queue_settings')->updateOrInsert(['branch_id' => $this->branch->id], $values + ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Walk-in kini hanya untuk staf terautentikasi (atau kiosk); token dikirim per-permintaan agar tes lain tetap anonim. */
    private function walkIn(array $payload, string $role = 'Admin Cabang'): \Illuminate\Testing\TestResponse
    {
        $token = $this->user($role)->createToken('uji')->plainTextToken;
        $response = $this->postJson('/api/v1/queues/walk-in', $payload, ['Authorization' => 'Bearer '.$token]);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function payload(array $extra = []): array
    {
        return ['branch_id' => $this->branch->id, 'service_type_id' => $this->service->id, 'customer_name' => 'Pelanggan '.Str::random(3)] + $extra;
    }

    private function user(string $role): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'name' => $role, 'email' => Str::slug($role).Str::random(4).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    public function test_registration_works_when_hours_are_not_configured(): void
    {
        $this->walkIn($this->payload())->assertCreated();
    }

    public function test_walk_in_is_not_blocked_by_operating_hours_but_availability_reports_them(): void
    {
        $this->hours('08:00', '16:00');
        $this->walkIn($this->payload())->assertCreated();
        $this->assertTrue(app(\App\Services\BranchAvailabilityService::class)->status($this->branch->id)['is_open']);

        Carbon::setTestNow(Carbon::parse('2026-11-04 16:00:00', config('app.timezone')));
        $this->walkIn($this->payload())->assertCreated();
        $status = app(\App\Services\BranchAvailabilityService::class)->status($this->branch->id);
        $this->assertFalse($status['is_open']);
        $this->assertSame('OUTSIDE_HOURS', $status['reason']);
    }

    public function test_closed_weekday_and_holiday_are_reported_by_availability(): void
    {
        $service = app(\App\Services\BranchAvailabilityService::class);
        $this->hours(closed: true);
        $this->assertSame('Cabang tidak beroperasi hari ini.', $service->status($this->branch->id)['message']);

        DB::table('operating_hours')->delete();
        DB::table('holidays')->insert(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'holiday_date' => '2026-11-04', 'holiday_name' => 'Cuti Bersama', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('Cabang libur: Cuti Bersama.', $service->status($this->branch->id)['message']);
    }

    public function test_same_phone_cannot_hold_two_active_queues_for_a_service(): void
    {
        $this->walkIn($this->payload(['customer_phone' => '0812-3456-7890']))->assertCreated();
        $this->walkIn($this->payload(['customer_phone' => '0812 3456 7890']))->assertStatus(422)->assertJsonValidationErrors('customer_phone');
        $this->assertDatabaseHas('queues', ['customer_phone' => '081234567890']);
    }

    public function test_public_catalog_exposes_availability_without_internal_fields(): void
    {
        $this->hours('08:00', '16:00');
        $this->walkIn($this->payload())->assertCreated();

        $branch = collect($this->getJson('/api/v1/catalog/branches')->assertOk()->json('data'))->firstWhere('id', $this->branch->id);
        $this->assertTrue($branch['availability']['is_open']);
        $this->assertSame('08:00', $branch['availability']['opens_at']);
        $this->assertSame(1, $branch['availability']['waiting_count']);
        $this->assertArrayNotHasKey('is_active', $branch);
    }

    public function test_tracking_shows_priority_aware_position_estimate_and_counter(): void
    {
        $priority = PriorityType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'priority_code' => 'PRIORITY', 'priority_name' => 'Prioritas', 'weight' => 100, 'aging_rate' => 1, 'aging_interval_seconds' => 300, 'verification_required' => true, 'is_active' => true]);
        $normal = $this->walkIn($this->payload())->json('data');
        $second = $this->walkIn($this->payload())->json('data');
        $this->walkIn($this->payload(['priority_type_id' => $priority->id]))->assertStatus(422); // pelanggan tidak boleh klaim prioritas tanpa verifikasi petugas
        $this->postJson('/api/v1/queues/walk-in', $this->payload(['priority_type_id' => $priority->id]))->assertUnauthorized(); // walk-in tanpa login ditolak
        $urgent = $this->walkIn($this->payload(['priority_type_id' => $priority->id]), 'Admin Unit')->assertCreated()->json('data');

        $track = fn (string $token) => $this->getJson('/api/v1/queues/track/'.$token)->assertOk()->json('data');
        $this->assertSame(1, $track($urgent['token'])['position']);
        $this->assertSame(2, $track($normal['token'])['position']);
        $this->assertSame(3, $track($second['token'])['position']);
        $this->assertNull($track($second['token'])['estimated_wait_seconds'], 'tanpa petugas online estimasi tidak diketahui');

        // Satu petugas tersedia → estimasi = jumlah di depan x rata-rata layanan (default 300 dtk).
        $user = $this->user('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $user->id, 'employee_code' => 'E1', 'employee_name' => 'Budi', 'is_active' => true]);
        $counter = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'CS-01', 'counter_name' => 'Loket 1', 'counter_type' => 'CS', 'is_active' => true]);
        $employee->serviceTypes()->attach($this->service->id);
        $employee->counters()->attach($counter->id);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame('ASSIGNED', $track($urgent['token'])['status']); // prioritas dilayani lebih dulu
        $this->assertSame('CS-01', $track($urgent['token'])['counter']['counter_code']);
        $this->assertSame(0, $track($urgent['token'])['estimated_wait_seconds']);
        $waiting = $track($second['token']);
        $this->assertSame(1, $waiting['ahead']); // tinggal 1 antrian di depan; prioritas sudah ter-assign
        $this->assertSame(1 * 300 + 150, $waiting['estimated_wait_seconds']); // 1 di depan x 5 mnt + separuh layanan yang sedang berjalan
        $this->assertArrayNotHasKey('customer_phone', $waiting);
    }

    public function test_settings_api_validates_saves_and_is_branch_scoped(): void
    {
        $admin = $this->user('Admin Cabang');
        Sanctum::actingAs($admin);
        $hours = collect(range(1, 7))->map(fn (int $day) => ['weekday' => $day, 'is_closed' => $day >= 6, 'opens_at' => '08:00', 'closes_at' => '15:30'])->all();

        $this->putJson('/api/v1/settings/queue', ['operating_hours' => $hours])->assertOk()
            ->assertJsonPath('data.operating_hours.2.closes_at', '15:30')->assertJsonPath('data.operating_hours.5.is_closed', true)
            ->assertJsonPath('data.availability.is_open', true);

        $bad = $hours; $bad[0]['closes_at'] = '07:00';
        $this->putJson('/api/v1/settings/queue', ['operating_hours' => $bad])->assertStatus(422)->assertJsonValidationErrors('operating_hours.0.closes_at');

        $this->postJson('/api/v1/settings/holidays', ['holiday_date' => '2026-11-04', 'holiday_name' => 'Libur Uji'])->assertCreated();
        $holidayId = $this->getJson('/api/v1/settings/queue')->json('data.holidays.0.id');
        $this->getJson('/api/v1/settings/queue')->assertJsonPath('data.availability.reason', 'HOLIDAY');
        $this->postJson('/api/v1/settings/holidays', ['holiday_date' => '2020-01-01', 'holiday_name' => 'Lampau'])->assertStatus(422);
        $this->deleteJson('/api/v1/settings/holidays/'.$holidayId)->assertOk();
        $this->getJson('/api/v1/settings/queue')->assertJsonPath('data.availability.reason', null);

        $other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'RG002', 'branch_name' => 'Other', 'is_active' => true]);
        $this->getJson('/api/v1/settings/queue?branch_id='.$other->id)->assertStatus(422); // cabang lain ditolak

        Sanctum::actingAs($this->user('Customer Service'));
        $this->putJson('/api/v1/settings/queue', ['branch_serves_directly' => false])->assertForbidden();
        $this->assertSame(0, Queue::count());
    }

    public function test_priority_types_endpoint_is_for_verifying_staff_only(): void
    {
        PriorityType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'priority_code' => 'NORMAL', 'priority_name' => 'Normal', 'weight' => 0, 'aging_rate' => 1, 'aging_interval_seconds' => 300, 'verification_required' => false, 'is_active' => true]);
        Sanctum::actingAs($this->user('Admin Unit'));
        $this->getJson('/api/v1/priority-types')->assertOk()->assertJsonPath('data.0.priority_code', 'NORMAL');
        Sanctum::actingAs($this->user('Customer Service'));
        $this->getJson('/api/v1/priority-types')->assertForbidden();
    }
}
