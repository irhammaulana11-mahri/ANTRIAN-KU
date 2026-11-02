<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Queue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportingService
{
    public function __construct(private readonly BranchAuthorizationService $branches) {}

    public function summary($user, ?string $branchId, string $period = 'today', ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->range($period, $from, $to);
        $resolvedBranch = $this->branches->resolve($user, $branchId);
        $queues = Queue::query()->with(['serviceType', 'assignments.employee', 'assignments.counter'])->whereBetween('created_at', [$start, $end])->when($resolvedBranch, fn (Builder $query) => $query->where('branch_id', $resolvedBranch))->get();
        $completed = $queues->where('status', Queue::COMPLETED);

        return [
            'period' => ['from' => $start->toIso8601String(), 'to' => $end->toIso8601String()],
            'branch_id' => $resolvedBranch,
            'total_queues' => $queues->count(),
            'served_customers' => $completed->count(),
            'waiting_customers' => $queues->whereIn('status', [Queue::WAITING, Queue::ASSIGNED, Queue::CALLED, Queue::SERVING])->count(),
            'cancelled_customers' => $queues->whereIn('status', [Queue::CANCELLED, Queue::NO_SHOW, Queue::SKIPPED])->count(),
            'average_wait_seconds' => $this->averageSeconds($queues, fn (Queue $queue) => $queue->called_at ? $queue->created_at?->diffInSeconds($queue->called_at) : null),
            'average_service_seconds' => $this->averageSeconds($completed, fn (Queue $queue) => $queue->called_at && $queue->completed_at ? $queue->called_at->diffInSeconds($queue->completed_at) : null),
            'by_service' => $queues->groupBy(fn (Queue $queue) => $queue->serviceType?->service_name ?? $queue->service_type_id)->map(fn (Collection $items, string $name) => ['service_name' => $name, 'total' => $items->count(), 'served' => $items->where('status', Queue::COMPLETED)->count()])->values()->all(),
            'employee_productivity' => $this->employeeProductivity($completed),
            'branch_comparison' => $this->branchComparison($queues),
        ];
    }

    public function audit($user, ?string $branchId, int $perPage = 50)
    {
        $resolved = $this->branches->resolve($user, $branchId);

        return AuditLog::query()->with('actor:id,name')->when($resolved, fn (Builder $query) => $query->where('branch_id', $resolved))->latest('created_at')->paginate($perPage);
    }

    private function range(string $period, ?string $from, ?string $to): array
    {
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
