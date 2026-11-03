<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Queue;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportingService
{
    public function __construct(private readonly BranchAuthorizationService $branches) {}

    private const ACTIVE = [Queue::WAITING, Queue::ASSIGNED, Queue::CALLED, Queue::SERVING];

    private const CANCELLED = [Queue::CANCELLED, Queue::NO_SHOW, Queue::SKIPPED];

    public function summary($user, ?string $branchId, string $period = 'today', ?string $from = null, ?string $to = null, ?string $month = null, ?string $unitId = null): array
    {
        [$start, $end] = $this->range($period, $from, $to, $month);
        $resolvedBranch = $this->branches->resolve($user, $branchId);
        $resolvedUnit = $this->branches->resolveUnit($user, $unitId);
        $queues = Queue::query()->with(['serviceType', 'assignments.employee', 'assignments.counter'])
            ->whereBetween('created_at', [$start, $end])
            ->when($resolvedBranch, fn (Builder $query) => $query->where('branch_id', $resolvedBranch))
            ->when($resolvedUnit, fn (Builder $query) => $query->where('unit_id', $resolvedUnit))
            ->get();
        $completed = $queues->where('status', Queue::COMPLETED);
        $count = fn (Collection $items) => [
            'total' => $items->count(),
            'served' => $items->where('status', Queue::COMPLETED)->count(),
            'cancelled' => $items->whereIn('status', self::CANCELLED)->count(),
            'active' => $items->whereIn('status', self::ACTIVE)->count(),
        ];

        $statuses = [Queue::WAITING, Queue::ASSIGNED, Queue::CALLED, Queue::SERVING, Queue::COMPLETED, Queue::SKIPPED, Queue::CANCELLED, Queue::NO_SHOW];
        $unitNames = Unit::withTrashed()->whereIn('id', $queues->pluck('unit_id')->filter()->unique())->pluck('unit_name', 'id');

        $days = [];
        for ($day = $start->startOfDay(); $day <= $end; $day = $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $key] + $count($queues->filter(fn (Queue $queue) => $queue->created_at?->toDateString() === $key));
        }

        return [
            'period' => ['from' => $start->toIso8601String(), 'to' => $end->toIso8601String()],
            'branch_id' => $resolvedBranch,
            'unit_id' => $resolvedUnit,
            'total_queues' => $queues->count(),
            'served_customers' => $completed->count(),
            'waiting_customers' => $queues->whereIn('status', self::ACTIVE)->count(),
            'cancelled_customers' => $queues->whereIn('status', self::CANCELLED)->count(),
            'average_wait_seconds' => $this->averageSeconds($queues, fn (Queue $queue) => $queue->called_at ? $queue->created_at?->diffInSeconds($queue->called_at) : null),
            'average_service_seconds' => $this->averageSeconds($completed, fn (Queue $queue) => $queue->called_at && $queue->completed_at ? $queue->called_at->diffInSeconds($queue->completed_at) : null),
            'by_status' => collect($statuses)->map(fn (string $status) => ['status' => $status, 'total' => $queues->where('status', $status)->count()])->all(),
            'by_channel' => collect(['WALK_IN', 'ONLINE'])->map(fn (string $channel) => ['channel' => $channel, 'total' => $queues->where('registration_channel', $channel)->count()])->all(),
            'by_service' => $queues->groupBy(fn (Queue $queue) => $queue->serviceType?->service_name ?? $queue->service_type_id)->map(fn (Collection $items, string $name) => ['service_name' => $name] + $count($items))->values()->all(),
            'by_unit' => $resolvedUnit ? [] : $queues->groupBy(fn (Queue $queue) => $queue->unit_id ?? '')->map(fn (Collection $items, string $id) => ['unit_id' => $id ?: null, 'unit_name' => $unitNames[$id] ?? 'Belum terikat unit'] + $count($items))->sortByDesc('total')->values()->all(),
            'by_hour' => collect(range(0, 23))->map(fn (int $hour) => ['hour' => $hour, 'total' => $queues->filter(fn (Queue $queue) => (int) $queue->created_at?->format('G') === $hour)->count()])->filter(fn (array $row) => $row['total'] > 0)->values()->all(),
            'daily' => $days,
            'employee_productivity' => $this->employeeProductivity($completed),
            'branch_comparison' => $this->branchComparison($queues),
        ];
    }

    public function audit($user, ?string $branchId, int $perPage = 50)
    {
        $resolved = $this->branches->resolve($user, $branchId);

        return AuditLog::query()->with('actor:id,name')->when($resolved, fn (Builder $query) => $query->where('branch_id', $resolved))->latest('created_at')->paginate($perPage);
    }

    private function range(string $period, ?string $from, ?string $to, ?string $month = null): array
    {
        if ($period === 'monthly' && $month) {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');

            return [$date->startOfMonth(), $date->endOfMonth()];
        }
        if ($period === 'month') {
            $date = CarbonImmutable::now();

            return [$date->startOfMonth(), $date->endOfMonth()];
        }
        if ($period === 'custom' && $from && $to) {
            return [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay()];
        }

        $date = CarbonImmutable::now();

        return [$date->startOfDay(), $date->endOfDay()];
    }

    private function averageSeconds(Collection $items, callable $resolver): ?float
    {
        $values = $items->map($resolver)->filter(fn ($value) => $value !== null && $value >= 0);

        return $values->isEmpty() ? null : round((float) $values->avg(), 2);
    }

    private function employeeProductivity(Collection $completed): array
    {
        return $completed->flatMap(fn (Queue $queue) => $queue->assignments->whereNotNull('employee_id')->map(fn ($assignment) => ['employee_id' => $assignment->employee_id, 'employee_name' => $assignment->employee?->employee_name, 'queue' => $queue]))->groupBy('employee_id')->map(function (Collection $items, string $employeeId): array {
            return ['employee_id' => $employeeId, 'employee_name' => $items->first()['employee_name'], 'served_count' => $items->count(), 'average_service_seconds' => $this->averageSeconds($items, fn (array $item) => $item['queue']->called_at && $item['queue']->completed_at ? $item['queue']->called_at->diffInSeconds($item['queue']->completed_at) : null)];
        })->values()->all();
    }

    private function branchComparison(Collection $queues): array
    {
        return $queues->groupBy('branch_id')->map(fn (Collection $items, string $branchId) => ['branch_id' => $branchId, 'total_queues' => $items->count(), 'served_customers' => $items->where('status', Queue::COMPLETED)->count(), 'average_wait_seconds' => $this->averageSeconds($items, fn (Queue $queue) => $queue->called_at ? $queue->created_at?->diffInSeconds($queue->called_at) : null), 'average_service_seconds' => $this->averageSeconds($items->where('status', Queue::COMPLETED), fn (Queue $queue) => $queue->called_at && $queue->completed_at ? $queue->called_at->diffInSeconds($queue->completed_at) : null)])->values()->all();
    }
}
