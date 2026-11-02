<?php

namespace App\Services;

use App\Models\DistributionCursor;
use App\Models\Employee;
use App\Models\Queue;
use Illuminate\Support\Collection;

class ManualAssignmentStrategy implements QueueDistributionStrategy
{
    public function chooseQueue(Collection $queues): ?Queue
    {
        return $queues->first();
    }

    public function chooseEmployee(Collection $employees, ?DistributionCursor $cursor): ?Employee
    {
        return $employees->first();
    }
}
