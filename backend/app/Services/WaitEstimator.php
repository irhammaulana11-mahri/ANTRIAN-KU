<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Queue;
use Illuminate\Support\Facades\DB;

/** Posisi dan estimasi waktu tunggu yang konsisten dengan urutan distribusi (prioritas + aging). */
class WaitEstimator
{
    private const DEFAULT_SERVICE_SECONDS = 300;

    public function __construct(private readonly EmployeeStatusService $statuses) {}

    /** @return array{position: ?int, ahead: int, estimated_wait_seconds: ?int, servers_online: int, avg_service_seconds: int} */
    public function forQueue(Queue $queue): array
    {
        $average = $this->averageServiceSeconds($queue->service_type_id);
        if ($queue->status !== Queue::WAITING) {
            return ['position' => null, 'ahead' => 0, 'estimated_wait_seconds' => in_array($queue->status, [Queue::ASSIGNED, Queue::CALLED, Queue::SERVING], true) ? 0 : null, 'servers_online' => 0, 'avg_service_seconds' => $average];
        }

        $strategy = new PriorityQueueStrategy;
        $queue->loadMissing('priorityType');
        $mine = $strategy->score($queue);
        $ahead = Queue::query()->with('priorityType')->where('branch_id', $queue->branch_id)->where('service_type_id', $queue->service_type_id)
            ->whereDate('queue_date', $queue->queue_date->toDateString())->where('status', Queue::WAITING)->where('id', '!=', $queue->id)->get()
            ->filter(function (Queue $other) use ($strategy, $mine, $queue): bool {
                $score = $strategy->score($other);

                return $score > $mine || ($score === $mine && [$other->created_at?->getTimestamp(), $other->sequence_no] < [$queue->created_at?->getTimestamp(), $queue->sequence_no]);
            })->count();

        [$servers, $busy] = $this->servers($queue);
        $estimate = $servers === 0 ? null : (int) (ceil($ahead / $servers) * $average + ($busy > 0 ? $average / 2 : 0));

        return ['position' => $ahead + 1, 'ahead' => $ahead, 'estimated_wait_seconds' => $estimate, 'servers_online' => $servers, 'avg_service_seconds' => $average];
    }

    /** Rata-rata durasi layanan 30 hari terakhir; dibatasi 1–60 menit agar data ekstrem tidak menyesatkan. */
    public function averageServiceSeconds(string $serviceTypeId): int
    {
        $rows = DB::table('service_sessions as ss')->join('queues as q', 'q.id', '=', 'ss.queue_id')
            ->where('q.service_type_id', $serviceTypeId)->where('ss.outcome', 'COMPLETED')->whereNotNull('ss.completed_at')
            ->where('ss.started_at', '>=', now()->subDays(30))->orderByDesc('ss.started_at')->limit(300)->get(['ss.started_at', 'ss.completed_at']);
        if ($rows->isEmpty()) {
            return self::DEFAULT_SERVICE_SECONDS;
        }
        $seconds = $rows->avg(fn ($row) => max(0, strtotime((string) $row->completed_at) - strtotime((string) $row->started_at)));

        return (int) min(3600, max(60, round($seconds)));
    }

    /** @return array{0: int, 1: int} [petugas online, di antaranya yang sedang melayani] */
    private function servers(Queue $queue): array
    {
        $employees = Employee::query()->where('branch_id', $queue->branch_id)->where('is_active', true)
            ->whereHas('serviceTypes', fn ($q) => $q->whereKey($queue->service_type_id))->get();
        $online = 0;
        $busy = 0;
        foreach ($employees as $employee) {
            if ($this->statuses->current($employee) === EmployeeStatusService::BUSY) {
                $online++;
                $busy++;
            } elseif ($this->statuses->isAvailable($employee)) {
                $online++;
            }
        }

        return [$online, $busy];
    }
}
