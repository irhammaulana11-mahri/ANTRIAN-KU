<?php

namespace App\Services;

use App\Models\DistributionCursor;
use App\Models\Employee;
use App\Models\Queue;
use Illuminate\Support\Collection;

class RoundRobinStrategy implements QueueDistributionStrategy
{
    public function chooseQueue(Collection $queues): ?Queue
    {
        return $queues->sortBy(fn (Queue $queue) => [$queue->created_at?->getTimestamp() ?? 0, $queue->sequence_no])->first();
    }

    public function chooseEmployee(Collection $employees, ?DistributionCursor $cursor): ?Employee
    {
        if ($employees->isEmpty()) {
            return null;
        }
        if (! $cursor?->last_employee_id) {
            return $employees->first();
        }

        $index = $employees->search(fn (Employee $employee) => $employee->id === $cursor->last_employee_id);

        $nextIndex = $index === false ? 0 : ($index + 1) % $employees->count();

        return $employees->get($nextIndex);
    }
}
