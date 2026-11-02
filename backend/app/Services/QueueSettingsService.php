<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueSettingsService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function get(string $branchId): array
    {
        $settings = DB::table('queue_settings')->where('branch_id', $branchId)->first();
        $hours = DB::table('operating_hours')->where('branch_id', $branchId)->get()->keyBy('weekday');

        return [
            'branch_id' => $branchId,
            'online_daily_quota' => $settings?->online_daily_quota,
            'max_waiting_threshold' => $settings?->max_waiting_threshold,
            // Senin(1)–Minggu(7). Hari tanpa baris dianggap belum diatur (cabang dianggap buka).
            'operating_hours' => collect(range(1, 7))->map(fn (int $day): array => [
                'weekday' => $day,
                'configured' => $hours->has($day),
                'is_closed' => $hours->has($day) ? (bool) $hours[$day]->is_closed : false,
                'opens_at' => $hours->has($day) ? substr((string) $hours[$day]->opens_at, 0, 5) : '08:00',
                'closes_at' => $hours->has($day) ? substr((string) $hours[$day]->closes_at, 0, 5) : '16:00',
            ])->values()->all(),
            'holidays' => DB::table('holidays')->where('branch_id', $branchId)->where('holiday_date', '>=', now()->subDays(30)->toDateString())->orderBy('holiday_date')->get(['id', 'holiday_date', 'holiday_name'])->all(),
        ];
    }

    public function update(string $branchId, array $data, string $actorId): array
    {
        $before = $this->get($branchId);
        DB::transaction(function () use ($branchId, $data): void {
            $now = now();
            $values = array_intersect_key($data, array_flip(['online_daily_quota', 'max_waiting_threshold']));
            if ($values !== []) {
                DB::table('queue_settings')->updateOrInsert(['branch_id' => $branchId], $values + ['id' => (string) (DB::table('queue_settings')->where('branch_id', $branchId)->value('id') ?? Str::uuid()), 'updated_at' => $now, 'created_at' => $now]);
            }
            $offset = $now->format('P'); // zona waktu aplikasi, mis. +07:00
            foreach ($data['operating_hours'] ?? [] as $row) {
                DB::table('operating_hours')->updateOrInsert(['branch_id' => $branchId, 'weekday' => $row['weekday']], [
                    'id' => (string) (DB::table('operating_hours')->where(['branch_id' => $branchId, 'weekday' => $row['weekday']])->value('id') ?? Str::uuid()),
                    'opens_at' => ($row['opens_at'] ?? '08:00').':00'.$offset, 'closes_at' => ($row['closes_at'] ?? '16:00').':00'.$offset,
                    'is_closed' => (bool) $row['is_closed'], 'updated_at' => $now, 'created_at' => $now,
                ]);
            }
        });
        $after = $this->get($branchId);
        $this->audit->record('settings.queue_updated', 'queue_settings', null, $branchId, $actorId, $before, $after);

        return $after;
    }

    public function addHoliday(string $branchId, string $date, string $name, string $actorId): void
    {
        DB::table('holidays')->updateOrInsert(['branch_id' => $branchId, 'holiday_date' => $date], ['id' => (string) (DB::table('holidays')->where(['branch_id' => $branchId, 'holiday_date' => $date])->value('id') ?? Str::uuid()), 'holiday_name' => $name, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('settings.holiday_added', 'holidays', null, $branchId, $actorId, null, ['holiday_date' => $date, 'holiday_name' => $name]);
    }

    public function removeHoliday(string $branchId, string $holidayId, string $actorId): bool
    {
        $holiday = DB::table('holidays')->where('branch_id', $branchId)->where('id', $holidayId)->first();
        if (! $holiday) {
            return false;
        }
        DB::table('holidays')->where('id', $holidayId)->delete();
        $this->audit->record('settings.holiday_removed', 'holidays', $holidayId, $branchId, $actorId, ['holiday_date' => $holiday->holiday_date, 'holiday_name' => $holiday->holiday_name]);

        return true;
    }
}
