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
        $this->service = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'service_name' => 'Customer Service', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => ($branch ?? $this->branch)->id, 'name' => $role, 'email' => Str::slug($role).Str::random(4).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    private function unitOf(Branch $branch): string
    {
        return \App\Models\Unit::query()->where('branch_id', $branch->id)->value('id')
            ?? \App\Models\Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'unit_code' => 'U'.Str::random(4), 'unit_name' => 'Unit Uji', 'is_active' => true])->id;
    }

    /** @return array{0: string, 1: string, 2: string} [credential, token, device id] */
    private function pairedDevice(?Branch $branch = null): array
    {
        $branch ??= $this->branch;
        Sanctum::actingAs($this->user('Admin Cabang', $branch));
        $created = $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-'.Str::random(4), 'device_name' => 'TV Lobby', 'branch_id' => $branch->id, 'unit_id' => $this->unitOf($branch)])->assertCreated();
        $credential = $created->json('credential');
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/display/activate', ['credential' => $credential])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        return [$credential, $token, $created->json('data.id')];
    }

    public function test_admin_pairs_device_and_credential_is_not_stored_in_plain_text(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $response = $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-01', 'device_name' => 'TV Lobby', 'unit_id' => $this->unitOf($this->branch)])->assertCreated();
        $credential = $response->json('credential');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $credential);
        $this->assertStringNotContainsString($credential, json_encode($response->json('data')));
        $this->assertDatabaseMissing('display_devices', ['credential_hash' => $credential]);
        $this->assertDatabaseHas('display_devices', ['id' => $response->json('data.id'), 'credential_hash' => hash('sha256', $credential)]);
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-01', 'device_name' => 'Duplikat', 'unit_id' => $this->unitOf($this->branch)])->assertStatus(422);
    }

    public function test_customer_service_cannot_manage_devices_and_admin_cannot_touch_other_branch(): void
    {
        Sanctum::actingAs($this->user('Customer Service'));
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-X', 'device_name' => 'X', 'unit_id' => $this->unitOf($this->branch)])->assertForbidden();

        [, , $deviceId] = $this->pairedDevice($this->other);
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson("/api/v1/display-devices/{$deviceId}/revoke")->assertForbidden();
    }

    public function test_activation_rejects_wrong_or_revoked_credentials(): void
    {
        [$credential, , $id] = $this->pairedDevice();
        $this->postJson('/api/v1/display/activate', ['credential' => $id.'.salah'])->assertUnauthorized();
        $this->postJson('/api/v1/display/activate', ['credential' => 'ZZZZZZZZ'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/display/activate', ['credential' => strtolower($credential)])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/display/activate', ['credential' => 'bukan-kredensial'])->assertUnauthorized();

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson("/api/v1/display-devices/{$id}/revoke")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/display/activate', ['credential' => $credential])->assertUnauthorized();
    }

    public function test_device_token_expires_and_is_killed_by_revoke_and_rotate(): void
    {
        [, $token, $id] = $this->pairedDevice();
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'display']);
        $this->assertTrue(\App\Models\PersonalAccessToken::query()->where('name', 'display')->whereNotNull('expires_at')->exists());

        $this->withToken($token)->getJson('/api/v1/display/snapshot')->assertOk();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson("/api/v1/display-devices/{$id}/rotate")->assertOk()->assertJsonStructure(['credential']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_snapshot_returns_only_own_branch_calls_with_counter_and_settings(): void
    {
        [, $token] = $this->pairedDevice();
        $counter = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_id' => $this->unitOf($this->branch), 'counter_code' => 'CS-01', 'counter_name' => 'Loket CS 1', 'counter_type' => 'CS', 'is_active' => true]);
        $user = $this->user('Customer Service');
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_id' => $this->unitOf($this->branch), 'user_id' => $user->id, 'employee_code' => 'E1', 'employee_name' => 'Budi', 'is_active' => true]);
        $employee->serviceTypes()->attach($this->service->id);
        $employee->counters()->attach($counter->id);
        $queueId = $this->registerQueue(['branch_id' => $this->branch->id, 'service_type_id' => $this->service->id, 'unit_id' => $this->unitOf($this->branch), 'customer_name' => 'Pelanggan Rahasia'])->json('data.queue.id');
        $otherService = ServiceType::create(['id' => (string) Str::uuid(), 'branch_id' => $this->other->id, 'service_name' => 'CS Lain', 'queue_prefix' => 'CS', 'priority_enabled' => true, 'is_active' => true]);
        $this->registerQueue(['branch_id' => $this->other->id, 'service_type_id' => $otherService->id, 'customer_name' => 'Cabang Lain'])->assertCreated();

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
        // Perangkat berunit: channel cabang ditolak, hanya channel unitnya sendiri yang boleh.
        $unit = $this->unitOf($this->branch);
        $this->withToken($token)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-branch.'.$this->branch->id])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-branch.'.$this->branch->id.'.unit.'.$unit])->assertOk()->assertJsonStructure(['auth']);
        $this->app['auth']->forgetGuards();
        $otherUnit = \App\Models\Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_code' => 'UX', 'unit_name' => 'Unit X', 'is_active' => true])->id;
        $this->withToken($token)->postJson('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-branch.'.$this->branch->id.'.unit.'.$otherUnit])->assertForbidden();
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

    public function test_device_is_scoped_to_unit_for_admin_unit_and_unit_is_required_for_admin_cabang(): void
    {
        $unitA = $this->unitOf($this->branch);
        $unitB = \App\Models\Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_code' => 'UB', 'unit_name' => 'Unit B', 'is_active' => true])->id;
        $unitOther = $this->unitOf($this->other);

        $cabang = $this->user('Admin Cabang');
        Sanctum::actingAs($cabang);
        // Cabang tidak membuka layanan langsung: unit wajib. (Bila membuka, kosong = perangkat cabang.)
        \DB::table('queue_settings')->insert(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'branch_serves_directly' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/display-devices', ['device_code' => 'NOUNIT', 'device_name' => 'Tanpa unit'])->assertStatus(422)->assertJsonValidationErrors('unit_id');
        $this->postJson('/api/v1/display-devices', ['device_code' => 'XUNIT', 'device_name' => 'Unit cabang lain', 'unit_id' => $unitOther])->assertStatus(422)->assertJsonValidationErrors('unit_id');
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-A', 'device_name' => 'TV A', 'unit_id' => $unitA])->assertCreated()->assertJsonPath('data.unit_id', $unitA);
        $idB = $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-B', 'device_name' => 'TV B', 'unit_id' => $unitB])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/display-devices')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/display-devices?unit_id='.$unitB)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.unit_name', 'Unit B');

        $adminUnit = $this->user('Admin Unit');
        $adminUnit->forceFill(['unit_id' => $unitA])->save();
        Sanctum::actingAs($adminUnit);
        $this->getJson('/api/v1/display-devices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.unit_id', $unitA);
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-C', 'device_name' => 'TV C'])->assertCreated()->assertJsonPath('data.unit_id', $unitA);
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-D', 'device_name' => 'TV D', 'unit_id' => $unitB])->assertStatus(422);
        $this->postJson("/api/v1/display-devices/{$idB}/revoke")->assertForbidden();
        $this->postJson("/api/v1/display-devices/{$idB}/rotate")->assertForbidden();
    }

    public function test_unit_device_snapshot_and_realtime_payload_only_cover_its_own_unit(): void
    {
        $unitA = $this->unitOf($this->branch);
        $unitB = \App\Models\Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_code' => 'UB', 'unit_name' => 'Unit B', 'is_active' => true])->id;
        $counter = Counter::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'counter_code' => 'CS-01', 'counter_name' => 'Loket 1', 'counter_type' => 'CS', 'is_active' => true]);
        $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'employee_code' => 'E1', 'employee_name' => 'Budi', 'is_active' => true]);
        foreach ([[$unitA, 'CS-001'], [$unitB, 'CS-002']] as $i => [$unit, $number]) {
            $queue = \App\Models\Queue::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_id' => $unit, 'service_type_id' => $this->service->id, 'queue_date' => now()->toDateString(), 'sequence_no' => $i + 1, 'queue_number' => $number, 'customer_name' => 'P'.$i, 'registration_channel' => 'ONLINE', 'status' => \App\Models\Queue::WAITING, 'token_hash' => hash('sha256', Str::random(48))]);
            \DB::table('queue_call_logs')->insert(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'queue_id' => $queue->id, 'employee_id' => $employee->id, 'counter_id' => $counter->id, 'call_type' => 'INITIAL', 'event_id' => (string) Str::uuid(), 'called_at' => now()]);
        }

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-UA', 'device_name' => 'TV A', 'unit_id' => $unitA])->assertCreated();
        $credentialA = \App\Models\DisplayDevice::query()->where('device_code', 'TV-UA')->firstOrFail();
        $code = 'ABCD2345';
        $credentialA->forceFill(['credential_hash' => hash('sha256', $code)])->save();
        $this->postJson('/api/v1/display-devices', ['device_code' => 'TV-ALL', 'device_name' => 'TV Semua', 'unit_id' => $unitB])->assertCreated();
        $this->app['auth']->forgetGuards();

        $token = $this->postJson('/api/v1/display/activate', ['credential' => $code])->assertOk()->assertJsonPath('data.unit.id', $unitA)->json('data.token');
        $this->app['auth']->forgetGuards();
        $data = $this->withToken($token)->getJson('/api/v1/display/snapshot')->assertOk()->assertJsonPath('data.unit.unit_name', 'Unit Uji')->json('data');
        $this->assertSame(['CS-001'], array_column($data['calls'], 'queue_number'));
        $this->assertSame(1, (int) $data['waiting'][0]['total']);
    }

    public function test_unit_can_be_assigned_once_to_a_device_without_unit(): void
    {
        $unit = $this->unitOf($this->branch);
        $unitOther = $this->unitOf($this->other);
        [, , $id] = $this->pairedDevice();
        \App\Models\DisplayDevice::query()->whereKey($id)->update(['unit_id' => null]); // perangkat lama

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->putJson("/api/v1/display-devices/{$id}/unit", [])->assertStatus(422);
        $this->putJson("/api/v1/display-devices/{$id}/unit", ['unit_id' => $unitOther])->assertStatus(422);
        $this->putJson("/api/v1/display-devices/{$id}/unit", ['unit_id' => $unit])->assertOk()->assertJsonPath('data.unit_id', $unit);
        $this->putJson("/api/v1/display-devices/{$id}/unit", ['unit_id' => $unit])->assertStatus(422);

        Sanctum::actingAs($this->user('Customer Service'));
        $this->putJson("/api/v1/display-devices/{$id}/unit", ['unit_id' => $unit])->assertForbidden();
    }

    public function test_deleting_a_device_revokes_its_token_and_frees_the_code(): void
    {
        [$credential, $token, $id] = $this->pairedDevice();
        $code = \App\Models\DisplayDevice::query()->whereKey($id)->value('device_code');

        Sanctum::actingAs($this->user('Customer Service'));
        $this->deleteJson("/api/v1/display-devices/{$id}")->assertForbidden();

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->deleteJson("/api/v1/display-devices/{$id}")->assertOk();
        $this->assertSoftDeleted('display_devices', ['id' => $id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->getJson('/api/v1/display-devices')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/display-devices', ['device_code' => $code, 'device_name' => 'Pengganti', 'unit_id' => $this->unitOf($this->branch)])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/display/activate', ['credential' => $credential])->assertUnauthorized();
    }

    public function test_admin_cabang_edits_own_branch_profile_and_admin_unit_edits_own_unit_profile(): void
    {
        $unit = $this->unitOf($this->branch);
        $otherUnit = \App\Models\Unit::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'unit_code' => 'UX', 'unit_name' => 'Unit X', 'is_active' => true])->id;
        $other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'DP009', 'branch_name' => 'Cabang Lain', 'is_active' => true]);

        // Admin Cabang: hanya nama, alamat, telepon cabangnya; kode dan status diabaikan; cabang lain dan hapus ditolak.
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->putJson("/api/v1/branches/{$this->branch->id}", ['branch_code' => 'HACK', 'branch_name' => 'Nama Baru', 'address' => 'Jl. Baru 1', 'phone' => '0321', 'is_active' => false])->assertOk()->assertJsonPath('data.branch_name', 'Nama Baru');
        $this->assertDatabaseHas('branches', ['id' => $this->branch->id, 'branch_code' => 'DP001', 'branch_name' => 'Nama Baru', 'address' => 'Jl. Baru 1', 'is_active' => true]);
        $this->putJson("/api/v1/branches/{$other->id}", ['branch_name' => 'Curang'])->assertForbidden();
        $this->deleteJson("/api/v1/branches/{$this->branch->id}")->assertForbidden();
        $this->postJson('/api/v1/branches', ['branch_code' => 'NEW', 'branch_name' => 'Baru'])->assertForbidden();

        // Admin Unit: hanya profil unitnya sendiri.
        $adminUnit = $this->user('Admin Unit');
        $adminUnit->forceFill(['unit_id' => $unit])->save();
        Sanctum::actingAs($adminUnit);
        $this->putJson("/api/v1/units/{$unit}", ['unit_code' => 'HACK', 'unit_name' => 'Unit Baru', 'address' => 'Jl. Unit 2', 'phone' => '0322', 'is_active' => false])->assertOk()->assertJsonPath('data.unit_name', 'Unit Baru');
        $this->assertDatabaseHas('units', ['id' => $unit, 'unit_name' => 'Unit Baru', 'address' => 'Jl. Unit 2', 'is_active' => true]);
        $this->assertDatabaseMissing('units', ['id' => $unit, 'unit_code' => 'HACK']);
        $this->putJson("/api/v1/units/{$otherUnit}", ['unit_code' => 'UX', 'unit_name' => 'Curang'])->assertForbidden();
        $this->deleteJson("/api/v1/units/{$unit}")->assertForbidden();
        $this->putJson("/api/v1/branches/{$this->branch->id}", ['branch_name' => 'Curang'])->assertForbidden();

        Sanctum::actingAs($this->user('Customer Service'));
        $this->putJson("/api/v1/branches/{$this->branch->id}", ['branch_name' => 'Curang'])->assertForbidden();
        $this->putJson("/api/v1/units/{$unit}", ['unit_code' => 'U1', 'unit_name' => 'Curang'])->assertForbidden();
    }
}
