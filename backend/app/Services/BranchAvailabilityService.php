<?php

namespace App\Services;

use App\Models\Queue;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Status buka/tutup cabang: hari libur dan jam operasional, serta jumlah antrian menunggu.
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
            [$reason, $message] = ['OUTSIDE_HOURS', "Cabang beroperasi pukul {$opensAt}–{$closesAt}. Antrian dibuka pada jam operasional."];
        }

        $waiting = Queue::query()->where('branch_id', $branchId)->whereDate('queue_date', $date)->whereIn('status', [Queue::WAITING, Queue::ASSIGNED])->count();

        return [
            'is_open' => $reason === null,
            'reason' => $reason,
            'message' => $message,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'holiday_name' => $holiday->holiday_name ?? null,
            'waiting_count' => $waiting,
        ];
    }
}
