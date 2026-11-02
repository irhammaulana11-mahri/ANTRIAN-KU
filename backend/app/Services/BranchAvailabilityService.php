<?php

namespace App\Services;

use App\Models\Queue;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan buka/tutup pendaftaran: hari libur, jam operasional, kuota online harian, dan ambang antrian menunggu.
 * Cabang yang belum mengatur jam operasional dianggap buka agar tidak memblokir pendaftaran tanpa sengaja.
 */
class BranchAvailabilityService
{
    public function status(string $branchId, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $date = $at->toDateString();
        $holiday = DB::table('holidays')->where('branch_id', $branchId)->whereDate('holiday_date', $date)->first();
        $hours = DB::table('operating_hours')->where('branch_id', $branchId)->where('weekday', $at->dayOfWeekIso)->first();
        $opensAt = $hours && ! $hours->is_closed ? substr((string) $hours->opens_at, 0, 5) : null;
        $closesAt = $hours && ! $hours->is_closed ? substr((string) $hours->closes_at, 0, 5) : null;

        $reason = null;
        $message = null;
        if ($holiday) {
            [$reason, $message] = ['HOLIDAY', 'Cabang libur: '.$holiday->holiday_name.'.'];
        } elseif ($hours && $hours->is_closed) {
            [$reason, $message] = ['CLOSED_DAY', 'Cabang tidak beroperasi hari ini.'];
        } elseif ($hours && ($at->format('H:i') < $opensAt || $at->format('H:i') >= $closesAt)) {
            [$reason, $message] = ['OUTSIDE_HOURS', "Cabang beroperasi pukul {$opensAt}–{$closesAt}. Pendaftaran online hanya pada jam operasional."];
        }

        $settings = DB::table('queue_settings')->where('branch_id', $branchId)->first();
        $limit = $settings?->online_daily_quota;
        $used = Queue::query()->where('branch_id', $branchId)->whereDate('queue_date', $date)->where('registration_channel', 'ONLINE')->where('status', '!=', Queue::CANCELLED)->count();
        $remaining = $limit === null ? null : max(0, (int) $limit - $used);
        $threshold = $settings?->max_waiting_threshold;
        $waiting = Queue::query()->where('branch_id', $branchId)->whereDate('queue_date', $date)->whereIn('status', [Queue::WAITING, Queue::ASSIGNED])->count();

        $onlineReason = $reason;
        $onlineMessage = $message;
        if ($onlineReason === null && $remaining !== null && $remaining <= 0) {
            [$onlineReason, $onlineMessage] = ['QUOTA_FULL', 'Kuota pendaftaran online hari ini sudah penuh. Silakan datang langsung ke kantor.'];
        } elseif ($onlineReason === null && $threshold !== null && $waiting >= (int) $threshold) {
            [$onlineReason, $onlineMessage] = ['WAITING_FULL', 'Antrian sedang penuh. Silakan coba lagi nanti atau datang langsung ke kantor.'];
        }

        return [
            'is_open' => $reason === null,
            'reason' => $reason,
            'message' => $message,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'holiday_name' => $holiday->holiday_name ?? null,
            'online_available' => $onlineReason === null,
            'online_reason' => $onlineReason,
            'online_message' => $onlineMessage,
            'online_quota' => ['limit' => $limit === null ? null : (int) $limit, 'used' => $used, 'remaining' => $remaining],
            'waiting_count' => $waiting,
        ];
    }

    /** Memblokir transaksi pendaftaran online lain pada cabang yang sama agar kuota tidak terlampaui saat bersamaan. */
    public function lockBranch(string $branchId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['online-registration:'.$branchId]);
        }
    }

    public function assertOnlineAllowed(string $branchId): void
    {
        $status = $this->status($branchId);
        if (! $status['online_available']) {
            throw ValidationException::withMessages(['branch_id' => $status['online_message']]);
        }
    }
}
