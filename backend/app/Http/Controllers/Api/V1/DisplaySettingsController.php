<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisplaySettingsRequest;
use App\Services\AuditLogService;
use App\Support\DisplayMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DisplaySettingsController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage') || $request->user()->can('displays.manage'), 403);

        return response()->json(['data' => $this->current($this->branchId($request))]);
    }

    public function update(DisplaySettingsRequest $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $before = $this->current($branchId);
        $values = array_intersect_key($request->validated(), array_flip(['display_announcement', 'voice_rate', 'voice_volume', 'media_volume']));
        if (array_key_exists('display_media', $request->validated())) {
            $media = DisplayMedia::normalize($request->validated('display_media') ?? []);
            $values['display_media'] = $media === [] ? null : json_encode($media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        DB::table('queue_settings')->updateOrInsert(['branch_id' => $branchId], $values + ['id' => (string) (DB::table('queue_settings')->where('branch_id', $branchId)->value('id') ?? Str::uuid()), 'updated_at' => now(), 'created_at' => now()]);
        $after = $this->current($branchId);
        $this->audit->record('settings.display_updated', 'queue_settings', null, $branchId, $request->user()->id, $before, $after);

        return response()->json(['data' => $after, 'message' => 'Pengaturan display disimpan.']);
    }

    private function branchId(Request $request): string
    {
        $branchId = $request->attributes->get('branch_id');
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');

        return $branchId;
    }

    private function current(string $branchId): array
    {
        $row = DB::table('queue_settings')->where('branch_id', $branchId)->first();

        return ['branch_id' => $branchId, 'display_announcement' => $row->display_announcement ?? null, 'voice_rate' => (float) ($row->voice_rate ?? 1), 'voice_volume' => (float) ($row->voice_volume ?? 1), 'media_volume' => (float) ($row->media_volume ?? 0.35), 'display_media' => self::media($row->display_media ?? null)];
    }

    /** @return list<array<string, mixed>> */
    public static function media(?string $json): array
    {
        $decoded = $json ? json_decode($json, true) : null;

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
