<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DisplayDevice;
use App\Models\Employee;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'AU001', 'branch_name' => 'Auth Branch', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role = 'Admin Unit'): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'name' => 'Pengguna', 'email' => Str::random(6).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    private function login(User $user, string $password = 'password123')
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password, 'device_name' => 'uji']);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function withBearer(string $token, string $method, string $url, array $data = [])
    {
        $response = $this->json($method, $url, $data, ['Authorization' => 'Bearer '.$token]);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    public function test_staff_token_has_an_expiry_and_stops_working_after_it(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $user = $this->user();
        $result = $this->login($user)->assertOk()->assertJsonStructure(['data' => ['token', 'expires_at', 'user']]);
        $token = $result->json('data.token');

        $this->assertEquals(Carbon::now()->addMinutes(720)->toIso8601String(), Carbon::parse($result->json('data.expires_at'))->toIso8601String());
        $this->assertNotNull(PersonalAccessToken::firstOrFail()->expires_at);

        Carbon::setTestNow('2026-11-01 19:59:00'); // 11 jam 59 menit
        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertOk();
        Carbon::setTestNow('2026-11-01 20:01:00'); // lewat 12 jam
        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_lifetime_is_configurable(): void
    {
        config(['sanctum.staff_token_minutes' => 30]);
        Carbon::setTestNow('2026-11-01 08:00:00');
        $token = $this->login($this->user())->json('data.token');
        Carbon::setTestNow('2026-11-01 08:31:00');
        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_refresh_rotates_the_token_and_extends_the_lifetime(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $old = $this->login($this->user())->json('data.token');

        Carbon::setTestNow('2026-11-01 15:00:00'); // 7 jam kemudian, masih berlaku
        $refreshed = $this->withBearer($old, 'POST', '/api/v1/auth/refresh')->assertOk()->assertJsonStructure(['data' => ['token', 'expires_at']]);
        $new = $refreshed->json('data.token');
        $this->assertNotSame($old, $new);
        $this->assertEquals(Carbon::now()->addMinutes(720)->toIso8601String(), Carbon::parse($refreshed->json('data.expires_at'))->toIso8601String());
        $this->assertSame(1, PersonalAccessToken::count(), 'token lama dicabut');

        $this->withBearer($old, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        Carbon::setTestNow('2026-11-02 02:00:00'); // 18 jam setelah login awal, 11 jam setelah refresh
        $this->withBearer($new, 'GET', '/api/v1/auth/me')->assertOk();
    }

    public function test_an_expired_token_cannot_be_refreshed_and_device_tokens_cannot_refresh_staff_sessions(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $token = $this->login($this->user())->json('data.token');
        $device = DisplayDevice::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'device_code' => 'TV1', 'device_name' => 'TV', 'device_type' => 'DISPLAY', 'credential_hash' => hash('sha256', 'x')]);
        $deviceToken = $device->createToken('display', ['display'], now()->addDays(7))->plainTextToken;
        $this->withBearer($deviceToken, 'POST', '/api/v1/auth/refresh')->assertForbidden();

        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->withBearer($token, 'POST', '/api/v1/auth/refresh')->assertUnauthorized();
        $this->withBearer($deviceToken, 'POST', '/api/v1/display/refresh')->assertOk(); // token perangkat 7 hari tidak ikut kedaluwarsa 12 jam
    }

    public function test_successful_login_and_logout_are_audited_without_secrets(): void
    {
        $user = $this->user();
        $token = $this->login($user)->json('data.token');
        $this->withBearer($token, 'POST', '/api/v1/auth/logout')->assertOk();

        $login = DB::table('audit_logs')->where('action', 'auth.login')->first();
        $this->assertSame($user->id, $login->actor_id);
        $this->assertSame($this->branch->id, $login->branch_id);
        $this->assertSame('uji', json_decode($login->metadata, true)['device_name']);
        $this->assertArrayHasKey('ip', json_decode($login->metadata, true));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'actor_id' => $user->id]);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertStringNotContainsString('password123', json_encode(DB::table('audit_logs')->get()));
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_logs')->get()));
    }

    public function test_failed_logins_are_audited_without_storing_the_password(): void
    {
        $user = $this->user();
        $this->login($user, 'salah-banget')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'tidak.ada@example.test', 'password' => 'rahasia-ngawur'])->assertUnauthorized();

        $failed = DB::table('audit_logs')->where('action', 'auth.login_failed')->orderBy('created_at')->get();
        $this->assertCount(2, $failed);
        $this->assertSame('bad_password', json_decode($failed[0]->metadata, true)['reason']);
        $this->assertSame($user->id, $failed[0]->entity_id);
        $this->assertSame('unknown_user', json_decode($failed[1]->metadata, true)['reason']);
        $this->assertNull($failed[1]->entity_id);
        $this->assertSame('tidak.ada@example.test', json_decode($failed[1]->metadata, true)['email']);
        $this->assertStringNotContainsString('salah-banget', json_encode(DB::table('audit_logs')->get()));
        $this->assertStringNotContainsString('rahasia-ngawur', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_blocked_login_of_an_inactive_employee_is_audited(): void
    {
        $user = $this->user('Customer Service');
        Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'user_id' => $user->id, 'employee_code' => 'E1', 'employee_name' => 'Nonaktif', 'is_active' => false]);

        $this->login($user)->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_blocked', 'actor_id' => $user->id]);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_audit_endpoint_shows_login_events_to_super_admin_but_branch_admin_sees_only_own_branch(): void
    {
        $admin = $this->user('Admin Cabang');
        $this->login($admin);
        $this->postJson('/api/v1/auth/login', ['email' => 'asing@example.test', 'password' => 'abcdefgh'])->assertUnauthorized(); // tanpa cabang

        $token = $admin->createToken('uji', ['*'], now()->addHour())->plainTextToken;
        $actions = collect($this->withBearer($token, 'GET', '/api/v1/audit-logs')->assertOk()->json('data'))->pluck('action')->all();
        $this->assertContains('auth.login', $actions);
        $this->assertNotContains('auth.login_failed', $actions, 'kegagalan login tanpa cabang hanya terlihat oleh Super Admin');
    }

    public function test_legacy_tokens_without_expiry_are_given_one_by_the_migration(): void
    {
        $user = $this->user();
        $old = $user->createToken('lama', ['*']); // tanpa expires_at, dibuat 3 hari lalu
        $fresh = $user->createToken('baru', ['*']);
        DB::table('personal_access_tokens')->where('id', $old->accessToken->id)->update(['created_at' => now()->subDays(3)]);
        DB::table('personal_access_tokens')->where('id', $fresh->accessToken->id)->update(['created_at' => now()->subHour()]);
        $device = DisplayDevice::create(['id' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'device_code' => 'TV2', 'device_name' => 'TV', 'device_type' => 'DISPLAY', 'credential_hash' => hash('sha256', 'y')]);
        $device->createToken('display', ['display']); // token perangkat tanpa expiry: tidak disentuh

        (include database_path('migrations/2026_11_01_000010_expire_legacy_staff_tokens.php'))->up();

        $this->assertTrue(PersonalAccessToken::find($old->accessToken->id)->expires_at->isPast(), 'token 3 hari lalu langsung tidak berlaku');
        $this->assertTrue(PersonalAccessToken::find($fresh->accessToken->id)->expires_at->isFuture());
        $this->assertNull(PersonalAccessToken::where('tokenable_type', DisplayDevice::class)->first()->expires_at);
    }

    public function test_api_responses_carry_a_deny_all_content_security_policy(): void
    {
        $csp = $this->getJson('/api/v1/catalog/branches')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.test', 'password' => 'abcdefgh'])->assertHeader('Content-Security-Policy', $csp);
    }

    public function test_swagger_page_gets_a_nonce_based_csp_that_matches_its_inline_script(): void
    {
        config(['app.api_docs' => true]);
        $response = $this->get('/api/docs')->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([^']+)'/", $csp, $nonce);
        $this->assertNotEmpty($nonce, 'CSP harus memuat nonce');
        $this->assertStringContainsString('nonce="'.$nonce[1].'"', $response->getContent());
        $this->assertStringContainsString('https://unpkg.com', $csp);
        $this->assertStringNotContainsString("script-src 'unsafe-inline'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        $second = $this->get('/api/docs')->headers->get('Content-Security-Policy');
        $this->assertNotSame($csp, $second, 'nonce berbeda pada tiap permintaan');
    }
}
