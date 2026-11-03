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

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'MD001', 'branch_name' => 'Master Branch', 'is_active' => true]);
        $this->other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'MD002', 'branch_name' => 'Other Branch', 'is_active' => true]);
    }

    private function user(string $role, ?Branch $branch = null, bool $global = false): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $global ? null : ($branch ?? $this->branch)->id, 'name' => $role, 'email' => Str::slug($role).Str::random(5).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    private function service(?Branch $branch = null, string $code = 'CS', string $prefix = 'CS'): ServiceType
    {
        return ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => ($branch ?? $this->branch)->id, 'service_name' => 'Layanan '.$code, 'queue_prefix' => $prefix, 'priority_enabled' => true, 'is_active' => true]);
    }

    private function counter(?Branch $branch = null, string $code = 'CS-01'): Counter
    {
        return Counter::create(['id' => (string) Str::uuid(), 'branch_id' => ($branch ?? $this->branch)->id, 'counter_code' => $code, 'counter_name' => 'Loket '.$code, 'counter_type' => 'CS', 'is_active' => true]);
    }

    /** Petugas CS lengkap dengan akun, layanan, dan loket. */
    private function operator(string $code = 'E1', ?ServiceType $service = null, ?Counter $counter = null): array
    {
        $user = $this->user('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $user->id, 'employee_code' => $code, 'employee_name' => 'Petugas '.$code, 'is_active' => true]);
        $employee->serviceTypes()->attach(($service ?? $this->service())->id);
        $employee->counters()->attach(($counter ?? $this->counter())->id);

        return [$user, $employee];
    }

    private function register(ServiceType $service, string $name = 'Pelanggan'): string
    {
        return $this->postJson('/api/v1/queues/online', ['branch_id' => $service->branch_id, 'service_type_id' => $service->id, 'customer_name' => $name])->assertCreated()->json('data.queue.id');
    }

    public function test_duplicate_codes_are_validation_errors_not_server_errors(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->service(code: 'CS', prefix: 'CS');
        $this->counter(code: 'CS-01');
        Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'employee_code' => 'E1', 'employee_name' => 'Ada', 'is_active' => true]);

        $this->postJson('/api/v1/service-types', ['service_name' => 'Baru', 'queue_prefix' => 'cs'])->assertStatus(422)->assertJsonValidationErrors('queue_prefix');
        $this->postJson('/api/v1/counters', ['counter_code' => 'CS-01', 'counter_name' => 'Baru', 'counter_type' => 'CS'])->assertStatus(422)->assertJsonValidationErrors('counter_code');
        $this->postJson('/api/v1/counters', ['counter_code' => 'CS-02', 'counter_name' => 'Baru', 'counter_type' => 'LAIN'])->assertStatus(422)->assertJsonValidationErrors('counter_type');
        $this->postJson('/api/v1/employees', ['employee_code' => 'E1', 'employee_name' => 'Baru', 'email' => 'baru@example.test', 'password' => 'passwordpanjang1', 'role_name' => 'Teller'])->assertStatus(422)->assertJsonValidationErrors('employee_code');

        // Kode yang sama boleh dipakai di cabang lain.
        Sanctum::actingAs($this->user('Admin Cabang', $this->other));
        $unit = \App\Models\Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $this->other->id, 'unit_code' => 'U1', 'unit_name' => 'Unit Lain', 'is_active' => true]);
        $this->postJson('/api/v1/counters', ['unit_id' => $unit->id, 'counter_code' => 'CS-01', 'counter_name' => 'Cabang Lain', 'counter_type' => 'CS'])->assertCreated();
    }

    public function test_editing_keeps_own_code_and_soft_deleted_codes_stay_reserved(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $counter = $this->counter(code: 'CS-01');
        $this->putJson("/api/v1/counters/{$counter->id}", ['counter_code' => 'CS-01', 'counter_name' => 'Nama Baru', 'counter_type' => 'TL'])->assertOk()->assertJsonPath('data.counter_name', 'Nama Baru');
        $this->putJson("/api/v1/counters/{$counter->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false); // partial update

        $this->deleteJson("/api/v1/counters/{$counter->id}")->assertNoContent();
        $this->postJson('/api/v1/counters', ['counter_code' => 'CS-01', 'counter_name' => 'Pengganti', 'counter_type' => 'CS'])->assertStatus(422)->assertJsonValidationErrors('counter_code');
    }

    public function test_listing_supports_search_filter_sort_and_caps_page_size(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->service(code: 'ALPHA', prefix: 'AL');
        $this->service(code: 'BRAVO', prefix: 'BR')->update(['is_active' => false]);
        $this->service(code: 'CHARLIE', prefix: 'CH');
        $this->service($this->other, 'DELTA', 'DE');

        $names = fn (string $query) => collect($this->getJson('/api/v1/service-types'.$query)->assertOk()->json('data'))->pluck('queue_prefix')->all();
        $this->assertSame(['AL', 'BR', 'CH'], $names(''));
        $this->assertSame(['BR'], $names('?q=brav'));
        $this->assertSame(['AL', 'CH'], $names('?is_active=1'));
        $this->assertSame(['CH', 'BR', 'AL'], $names('?sort=queue_prefix&direction=desc'));
        $this->assertSame(['AL', 'BR', 'CH'], $names('?sort=password;drop&direction=asc'), 'kolom sort di luar whitelist diabaikan');
        $this->assertSame([], $names('?q=%25'), 'karakter wildcard diperlakukan sebagai teks biasa');
        $this->assertSame([], $names('?q=a!b_c'), 'karakter escape tidak merusak query');
        $this->getJson('/api/v1/service-types?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_employee_search_matches_email_and_role_filter_works(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        [$cs, $employee] = $this->operator('E1');
        $teller = $this->user('Teller');
        Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $teller->id, 'employee_code' => 'E2', 'employee_name' => 'Teller Dua', 'is_active' => true]);

        $codes = fn (string $query) => collect($this->getJson('/api/v1/employees'.$query)->assertOk()->json('data'))->pluck('employee_code')->sort()->values()->all();
        $this->assertSame(['E1'], $codes('?q='.urlencode($cs->email)));
        $this->assertSame(['E2'], $codes('?role=Teller'));
        $this->assertSame(['E1', 'E2'], $codes(''));
        $this->assertSame(['Customer Service'], $this->getJson('/api/v1/employees?q=E1')->json('data.0.user.roles'));
    }

    public function test_employee_update_changes_account_fields_role_and_password_and_revokes_sessions(): void
    {
        [$staff, $employee] = $this->operator('E1');
        $staff->createToken('web');
        Sanctum::actingAs($this->user('Admin Cabang'));

        $this->putJson("/api/v1/employees/{$employee->id}", ['employee_name' => 'Nama Baru', 'email' => 'baru@example.test', 'role_name' => 'Teller', 'password' => 'passwordbaru123'])->assertOk()
            ->assertJsonPath('data.employee_name', 'Nama Baru')->assertJsonPath('data.user.email', 'baru@example.test')->assertJsonPath('data.user.roles.0', 'Teller');
        $this->assertTrue(Hash::check('passwordbaru123', $staff->refresh()->password));
        $this->assertSame('Nama Baru', $staff->name);
        $this->assertSame(0, $staff->tokens()->count(), 'reset password mengakhiri sesi login lama');
        $this->putJson("/api/v1/employees/{$employee->id}", ['password' => 'pendek'])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.updated', 'entity_id' => $employee->id]);
    }

    public function test_only_global_admin_can_assign_admin_cabang_role_and_cannot_edit_own_role(): void
    {
        $payload = ['employee_code' => 'ADM1', 'employee_name' => 'Admin Baru', 'email' => 'adm@example.test', 'password' => 'passwordpanjang1', 'role_name' => 'Admin Cabang'];
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson('/api/v1/employees', $payload)->assertStatus(422)->assertJsonValidationErrors('role_name');

        $super = $this->user('Super Admin', global: true);
        Sanctum::actingAs($super);
        $this->postJson('/api/v1/employees', $payload + ['branch_id' => $this->branch->id])->assertCreated()->assertJsonPath('data.user.roles.0', 'Admin Cabang');

        $own = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $super->id, 'employee_code' => 'SA', 'employee_name' => 'Super', 'is_active' => true]);
        $this->putJson("/api/v1/employees/{$own->id}", ['role_name' => 'Teller'])->assertStatus(409);
        $this->putJson("/api/v1/employees/{$own->id}", ['is_active' => false])->assertStatus(409);
    }

    public function test_deactivated_or_deleted_employee_cannot_log_in_and_is_closed(): void
    {
        [$staff, $employee] = $this->operator('E1');
        $staff->createToken('web');
        Sanctum::actingAs($this->user('Admin Cabang'));

        $this->putJson("/api/v1/employees/{$employee->id}", ['is_active' => false])->assertOk();
        $this->assertSame(0, $staff->tokens()->count());
        $this->assertDatabaseHas('employee_status_logs', ['employee_id' => $employee->id, 'to_status' => 'CLOSED']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password123'])->assertForbidden();

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->putJson("/api/v1/employees/{$employee->id}", ['is_active' => true])->assertOk();
        $this->deleteJson("/api/v1/employees/{$employee->id}")->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password123'])->assertForbidden();
    }

    public function test_employee_with_active_assignment_cannot_be_deactivated_or_deleted(): void
    {
        $service = $this->service();
        [$staff, $employee] = $this->operator('E1', $service);
        $this->register($service);
        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk()->assertJsonPath('data.active.status', 'ASSIGNED');

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->putJson("/api/v1/employees/{$employee->id}", ['is_active' => false])->assertStatus(409);
        $this->deleteJson("/api/v1/employees/{$employee->id}")->assertStatus(409);
        $this->assertTrue($employee->refresh()->is_active);
    }

    public function test_counter_in_use_is_protected_and_deleted_counter_is_never_assigned(): void
    {
        $service = $this->service();
        $counter = $this->counter();
        [$staff] = $this->operator('E1', $service, $counter);
        $this->register($service);
        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk();

        $admin = $this->user('Admin Cabang');
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/counters/{$counter->id}", ['is_active' => false])->assertStatus(409);
        $this->deleteJson("/api/v1/counters/{$counter->id}")->assertStatus(409);

        // Setelah dibebaskan, loket dapat dihapus dan tidak lagi dipakai distribusi.
        Sanctum::actingAs($staff);
        $queueId = $this->getJson('/api/v1/employee/workspace')->json('data.active.queue_id');
        foreach (['call', 'start', 'complete'] as $action) {
            $this->postJson("/api/v1/service/{$queueId}/{$action}")->assertOk();
        }
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/counters/{$counter->id}")->assertNoContent();
        $this->assertDatabaseCount('employee_counters', 0);

        $waiting = $this->register($service, 'Berikutnya');
        Sanctum::actingAs($staff);
        $this->postJson('/api/v1/employee/status', ['status' => 'AVAILABLE'])->assertOk()->assertJsonPath('data.active', null);
        $this->assertSame('WAITING', Queue::find($waiting)->status);
    }

    public function test_service_with_active_queues_is_protected_and_deleted_service_is_gone_everywhere(): void
    {
        $service = $this->service();
        [, $employee] = $this->operator('E1', $service);
        $queueId = $this->register($service);
        Sanctum::actingAs($this->user('Admin Cabang'));

        $this->putJson("/api/v1/service-types/{$service->id}", ['is_active' => false])->assertStatus(409);
        $this->deleteJson("/api/v1/service-types/{$service->id}")->assertStatus(409);

        $this->patchJson("/api/v1/queues/{$queueId}/status", ['status' => 'CANCELLED'])->assertForbidden(); // Admin Cabang bukan operator antrian
        Queue::whereKey($queueId)->update(['status' => 'CANCELLED']);
        $this->deleteJson("/api/v1/service-types/{$service->id}")->assertNoContent();
        $this->assertDatabaseCount('employee_services', 0);
        $this->app['auth']->forgetGuards();
        $this->assertSame([], $this->getJson('/api/v1/catalog/service-types?branch_id='.$this->branch->id)->json('data'));
        $this->postJson('/api/v1/queues/online', ['branch_id' => $this->branch->id, 'service_type_id' => $service->id, 'customer_name' => 'X'])->assertStatus(422);
        $this->assertNotNull($employee->refresh());
    }

    public function test_new_branch_gets_defaults_and_only_super_admin_manages_branches(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson('/api/v1/branches', ['branch_code' => 'NEW01', 'branch_name' => 'Cabang Baru'])->assertForbidden();

        Sanctum::actingAs($this->user('Super Admin', global: true));
        $id = $this->postJson('/api/v1/branches', ['branch_code' => 'NEW01', 'branch_name' => 'Cabang Baru', 'phone' => '021-123'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('queue_settings', ['branch_id' => $id, 'stale_heartbeat_seconds' => 90]);
        $this->assertSame(['NORMAL', 'PRIORITY', 'URGENT'], \DB::table('priority_types')->where('branch_id', $id)->orderBy('weight')->pluck('priority_code')->all());
        $this->postJson('/api/v1/branches', ['branch_code' => 'NEW01', 'branch_name' => 'Dobel'])->assertStatus(422)->assertJsonValidationErrors('branch_code');
        $this->putJson("/api/v1/branches/{$id}", ['branch_name' => 'Cabang Baru Diubah'])->assertOk()->assertJsonPath('data.branch_code', 'NEW01');
        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.created', 'entity_id' => $id]);
    }

    public function test_branch_deactivation_and_deletion_guards(): void
    {
        $service = $this->service();
        $queueId = $this->register($service);
        Sanctum::actingAs($this->user('Super Admin', global: true));

        $this->putJson("/api/v1/branches/{$this->branch->id}", ['is_active' => false])->assertStatus(409);
        Queue::whereKey($queueId)->update(['status' => 'COMPLETED']);
        $this->deleteJson("/api/v1/branches/{$this->branch->id}")->assertStatus(409); // masih punya layanan
        $this->putJson("/api/v1/branches/{$this->branch->id}", ['is_active' => false])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/queues/online', ['branch_id' => $this->branch->id, 'service_type_id' => $service->id, 'customer_name' => 'X'])->assertStatus(422);

        Sanctum::actingAs($this->user('Super Admin', global: true));
        $this->deleteJson("/api/v1/branches/{$this->other->id}")->assertNoContent(); // kosong → boleh
        $this->assertSoftDeleted('branches', ['id' => $this->other->id]);
        $this->app['auth']->forgetGuards();
        $this->assertNotContains($this->other->id, collect($this->getJson('/api/v1/catalog/branches')->json('data'))->pluck('id')->all());
    }

    public function test_branch_admin_sees_only_own_branch_and_cannot_touch_other_branch_data(): void
    {
        $foreign = $this->counter($this->other, 'FX-01');
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->branch->id);
        $this->putJson("/api/v1/counters/{$foreign->id}", ['counter_name' => 'Hack'])->assertForbidden();
        $this->deleteJson("/api/v1/counters/{$foreign->id}")->assertForbidden();
        $this->getJson('/api/v1/counters?branch_id='.$this->other->id)->assertStatus(422);
        $this->postJson('/api/v1/counters', ['branch_id' => $this->other->id, 'counter_code' => 'ZZ', 'counter_name' => 'Hack', 'counter_type' => 'CS'])->assertStatus(422);
        $this->assertSame('Loket FX-01', $foreign->refresh()->counter_name);
    }

    public function test_branch_listing_includes_child_counts(): void
    {
        $this->service();
        $this->counter();
        $this->counter(code: 'CS-02');
        Sanctum::actingAs($this->user('Super Admin', global: true));
        $row = collect($this->getJson('/api/v1/branches?q=MD001')->assertOk()->json('data'))->first();
        $this->assertSame(2, $row['counters_count']);
        $this->assertSame(1, $row['service_types_count']);
        $this->assertSame(0, $row['employees_count']);
    }

    public function test_deleted_records_can_be_listed_and_restored(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $counter = $this->counter(code: 'CS-09');
        $this->deleteJson("/api/v1/counters/{$counter->id}")->assertNoContent();

        $this->assertSame([], $this->getJson('/api/v1/counters')->json('data'));
        $deleted = $this->getJson('/api/v1/counters?deleted=1')->assertOk()->json('data');
        $this->assertSame(['CS-09'], array_column($deleted, 'counter_code'));
        $this->assertNotNull($deleted[0]['deleted_at']);

        $this->postJson("/api/v1/counters/{$counter->id}/restore")->assertOk()->assertJsonPath('data.counter_code', 'CS-09')->assertJsonPath('data.deleted_at', null);
        $this->assertSame(['CS-09'], array_column($this->getJson('/api/v1/counters')->json('data'), 'counter_code'));
        $this->assertSame([], $this->getJson('/api/v1/counters?deleted=1')->json('data'));
        $this->postJson("/api/v1/counters/{$counter->id}/restore")->assertStatus(409); // sudah aktif
        $this->assertDatabaseHas('audit_logs', ['action' => 'counter.restored', 'entity_id' => $counter->id]);
    }

    public function test_restored_service_returns_to_catalog_and_restored_employee_can_log_in_but_links_are_not_revived(): void
    {
        $service = $this->service();
        [$staff, $employee] = $this->operator('E1', $service, $this->counter());
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->deleteJson("/api/v1/employees/{$employee->id}")->assertNoContent();
        $this->deleteJson("/api/v1/service-types/{$service->id}")->assertNoContent();

        $this->postJson("/api/v1/employees/{$employee->id}/restore")->assertOk();
        $this->postJson("/api/v1/service-types/{$service->id}/restore")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password123'])->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/catalog/service-types?branch_id='.$this->branch->id)->json('data'));

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->assertSame([], $this->getJson("/api/v1/employees/{$employee->id}")->json('data.service_types'), 'kompetensi harus diatur ulang setelah pulih');
    }

    public function test_restore_is_branch_scoped_and_blocked_while_parent_branch_is_deleted(): void
    {
        $foreign = $this->counter($this->other, 'FX-01');
        $foreign->delete();
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson("/api/v1/counters/{$foreign->id}/restore")->assertForbidden();
        $this->assertTrue($foreign->refresh()->trashed());

        $super = $this->user('Super Admin', global: true);
        Sanctum::actingAs($super);
        $this->other->delete();
        $this->postJson("/api/v1/counters/{$foreign->id}/restore")->assertStatus(409);
        $this->postJson("/api/v1/branches/{$this->other->id}/restore")->assertOk();
        $this->postJson("/api/v1/counters/{$foreign->id}/restore")->assertOk();
        $this->assertContains($this->other->id, collect($this->getJson('/api/v1/branches')->json('data'))->pluck('id')->all());
    }
}
