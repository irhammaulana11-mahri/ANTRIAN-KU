<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DisplayDevice;
use App\Models\Queue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DisplayController extends Controller
{
    private const TOKEN_DAYS = 7;

    /** Panggilan lebih tua dari ini tidak boleh diumumkan lewat suara. */
    private const CALL_EXPIRY_SECONDS = 60;

    public function activate(Request $request): JsonResponse
    {
        $credential = (string) $request->validate(['credential' => ['required', 'string', 'max:200']])['credential'];
        [$id, $secret] = array_pad(explode('.', $credential, 2), 2, '');
        $device = preg_match('/^[0-9a-f-]{36}$/i', $id) ? DisplayDevice::query()->with('branch')->find($id) : null;
        if (! $device || $device->isRevoked() || ! $device->branch?->is_active || ! hash_equals($device->credential_hash, hash('sha256', $secret))) {
            return response()->json(['message' => 'Kunci pairing tidak valid atau perangkat dinonaktifkan.'], 401);
        }

        return $this->issueToken($device);
    }

    public function refresh(Request $request): JsonResponse
    {
        return $this->issueToken($request->user()->loadMissing('branch'));
    }

    public function snapshot(Request $request): JsonResponse
    {
        /** @var DisplayDevice $device */
        $device = $request->user()->loadMissing('branch');
        $branchId = $device->branch_id;
        $settings = DB::table('queue_settings')->where('branch_id', $branchId)->first();

        $calls = DB::table('queue_call_logs as l')
            ->join('queues as q', 'q.id', '=', 'l.queue_id')
            ->join('counters as c', 'c.id', '=', 'l.counter_id')
            ->join('service_types as s', 's.id', '=', 'q.service_type_id')
            ->where('l.branch_id', $branchId)->where('l.called_at', '>=', now()->startOfDay())
            ->orderByDesc('l.called_at')->limit(40)
            ->get(['l.event_id', 'l.queue_id', 'l.call_type', 'l.called_at', 'q.queue_number', 'q.customer_name', 'q.status as queue_status', 's.service_name', 'c.counter_code', 'c.counter_name', 'c.counter_type'])
            ->map(fn ($row) => (array) $row + ['called_at_iso' => Carbon::parse($row->called_at)->toIso8601String()])
            ->map(function (array $row): array {
                $row['called_at'] = $row['called_at_iso'];
                unset($row['called_at_iso']);

                return $row;
            })->values();

        $waiting = Queue::query()->join('service_types as s', 's.id', '=', 'queues.service_type_id')->where('queues.branch_id', $branchId)
            ->whereIn('queues.status', [Queue::WAITING, Queue::ASSIGNED])->whereDate('queues.queue_date', now()->toDateString())
            ->groupBy('s.service_name')->orderBy('s.service_name')->selectRaw('s.service_name, count(*) as total')->get();

        return response()->json(['data' => [
            'server_time' => now()->toIso8601String(),
            'branch' => ['id' => $branchId, 'branch_name' => $device->branch?->branch_name],
            'settings' => [
                'announcement' => $settings->display_announcement ?? null,
                'voice_rate' => (float) ($settings->voice_rate ?? 1),
                'voice_volume' => (float) ($settings->voice_volume ?? 1),
                'media_volume' => (float) ($settings->media_volume ?? 0.35),
                'call_expiry_seconds' => self::CALL_EXPIRY_SECONDS,
                'media' => DisplaySettingsController::media($settings->display_media ?? null),
            ],
            'calls' => $calls,
            'waiting' => $waiting,
        ]]);
    }

    private function issueToken(DisplayDevice $device): JsonResponse
    {
        $device->tokens()->delete();
        $expiresAt = now()->addDays(self::TOKEN_DAYS);
        $token = $device->createToken($device->ability(), [$device->ability()], $expiresAt)->plainTextToken;
        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['data' => [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'device' => ['id' => $device->id, 'device_name' => $device->device_name, 'device_type' => $device->device_type],
            'branch' => ['id' => $device->branch_id, 'branch_name' => $device->branch?->branch_name],
        ], 'message' => 'Perangkat display aktif.']);
    }
}
