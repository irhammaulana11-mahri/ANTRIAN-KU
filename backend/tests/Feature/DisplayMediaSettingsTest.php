<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DisplayDevice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DisplayMediaSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->branch = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'MD001', 'branch_name' => 'Media Branch', 'is_active' => true]);
        $this->other = Branch::create(['id' => (string) Str::uuid(), 'branch_code' => 'MD002', 'branch_name' => 'Other', 'is_active' => true]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => ($branch ?? $this->branch)->id, 'name' => $role, 'email' => Str::slug($role).Str::random(5).'@example.test', 'password' => Hash::make('password123')]);
        $user->assignRole($role);

        return $user;
    }

    private function snapshotFor(Branch $branch): array
    {
        $device = DisplayDevice::create(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'device_code' => 'TV-'.Str::random(4), 'device_name' => 'TV', 'device_type' => 'DISPLAY', 'credential_hash' => hash('sha256', 'x')]);
        $token = $device->createToken('display', ['display'], now()->addDay())->plainTextToken;
        $this->app['auth']->forgetGuards(); // lepaskan user dari Sanctum::actingAs agar token perangkat yang dipakai
        $response = $this->json('GET', '/api/v1/display/snapshot', [], ['Authorization' => 'Bearer '.$token]);
        $this->app['auth']->forgetGuards();

        return $response->assertOk()->json('data.settings');
    }

    public function test_admin_saves_an_ordered_list_of_iptv_and_youtube_sources_and_the_display_receives_it(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $response = $this->putJson('/api/v1/settings/display', ['display_media' => [
            ['type' => 'STREAM', 'url' => 'http://192.168.1.10:8080/iptv/channel1.m3u8', 'label' => '  IPTV Lobi  '],
            ['type' => 'YOUTUBE', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5s', 'label' => 'Cadangan'],
            ['type' => 'YOUTUBE', 'url' => 'https://www.youtube.com/playlist?list=PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf'],
        ]])->assertOk();

        $media = $response->json('data.display_media');
        $this->assertSame(['STREAM', 'YOUTUBE', 'YOUTUBE'], array_column($media, 'type'));
        $this->assertSame('IPTV Lobi', $media[0]['label']);
        $this->assertSame(['video', 'dQw4w9WgXcQ'], [$media[1]['youtube_kind'], $media[1]['youtube_id']]);
        $this->assertSame(['playlist', 'PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf'], [$media[2]['youtube_kind'], $media[2]['youtube_id']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.display_updated', 'branch_id' => $this->branch->id]);

        $this->assertSame($media, $this->getJson('/api/v1/settings/display')->json('data.display_media'));
        $this->assertSame($media, $this->snapshotFor($this->branch)['media'], 'display menerima daftar yang sama, dengan urutan prioritas');
        $this->assertSame([], $this->snapshotFor($this->other)['media'], 'cabang lain tidak ikut menerima');
    }

    public function test_a_channel_id_link_is_stored_as_the_channels_uploads_playlist(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $media = $this->putJson('/api/v1/settings/display', ['display_media' => [['type' => 'YOUTUBE', 'url' => 'https://www.youtube.com/channel/UCfeOWH5zFA4c8GDQrOZyoPg/videos', 'label' => 'PLN']]])->assertOk()->json('data.display_media');
        $this->assertSame(['playlist', 'UUfeOWH5zFA4c8GDQrOZyoPg'], [$media[0]['youtube_kind'], $media[0]['youtube_id']]);
    }

    public function test_sending_an_empty_list_clears_the_media_and_omitting_the_field_keeps_it(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->putJson('/api/v1/settings/display', ['display_media' => [['type' => 'STREAM', 'url' => 'https://iptv.example.test/live.m3u8']]])->assertOk();
        $this->putJson('/api/v1/settings/display', ['display_announcement' => 'Halo'])->assertOk()->assertJsonCount(1, 'data.display_media');
        $this->putJson('/api/v1/settings/display', ['display_media' => []])->assertOk()->assertJsonPath('data.display_media', []);
        $this->assertSame([], $this->snapshotFor($this->branch)['media']);
    }

    public function test_invalid_sources_are_rejected_with_field_level_messages(): void
    {
        Sanctum::actingAs($this->user('Admin Cabang'));
        $reject = fn (array $source, string $field = 'display_media.0.url') => $this->putJson('/api/v1/settings/display', ['display_media' => [$source]])->assertStatus(422)->assertJsonValidationErrors($field);

        $reject(['type' => 'YOUTUBE', 'url' => 'https://vimeo.com/123456']);                       // bukan YouTube
        $reject(['type' => 'YOUTUBE', 'url' => 'https://www.youtube.com/@kantorpusat']);           // kanal @nama
        $message = $this->putJson('/api/v1/settings/display', ['display_media' => [['type' => 'YOUTUBE', 'url' => 'https://www.youtube.com/@PTPLNPersero/videos']]])->assertStatus(422)->json('errors')['display_media.0.url'][0];
        $this->assertStringContainsString('/channel/UC', $message); // pesan memandu admin ke format yang bisa dipakai
        $reject(['type' => 'YOUTUBE', 'url' => 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ']); // domain tiruan
        $reject(['type' => 'STREAM', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);     // YouTube harus bertipe YouTube
        $reject(['type' => 'STREAM', 'url' => 'javascript:alert(1)']);
        $reject(['type' => 'STREAM', 'url' => 'data:text/html,<script>alert(1)</script>']);
        $reject(['type' => 'STREAM', 'url' => 'ftp://iptv.example.test/live.m3u8']);
        $reject(['type' => 'STREAM', 'url' => 'http://admin:rahasia@iptv.example.test/live.m3u8']);  // kredensial tidak boleh ikut ke perangkat
        $reject(['type' => 'STREAM', 'url' => 'bukan-url']);
        $reject(['type' => 'STREAM', 'url' => ''], 'display_media.0.url');
        $reject(['type' => 'RADIO', 'url' => 'https://iptv.example.test/live'], 'display_media.0.type');

        $six = array_map(fn ($i) => ['type' => 'STREAM', 'url' => "https://iptv.example.test/{$i}.m3u8"], range(1, 6));
        $this->putJson('/api/v1/settings/display', ['display_media' => $six])->assertStatus(422)->assertJsonValidationErrors('display_media');
        $this->assertSame([], $this->getJson('/api/v1/settings/display')->json('data.display_media'), 'tidak ada yang tersimpan saat validasi gagal');
    }

    public function test_only_users_with_settings_permission_can_change_media_and_only_for_their_branch(): void
    {
        $payload = ['display_media' => [['type' => 'STREAM', 'url' => 'https://iptv.example.test/live.m3u8']]];
        Sanctum::actingAs($this->user('Customer Service'));
        $this->putJson('/api/v1/settings/display', $payload)->assertForbidden();

        Sanctum::actingAs($this->user('Admin Cabang'));
        $this->putJson('/api/v1/settings/display', $payload + ['branch_id' => $this->other->id])->assertStatus(422); // cabang lain ditolak
        $this->assertSame([], $this->snapshotFor($this->other)['media']);
    }
}
