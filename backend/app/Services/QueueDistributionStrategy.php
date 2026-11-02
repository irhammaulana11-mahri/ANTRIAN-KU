<?php

namespace App\Services;

use App\Models\DistributionCursor;
use App\Models\Employee;
use App\Models\Queue;
use Illuminate\Support\Collection;

interface QueueDistributionStrategy
{
    public function chooseQueue(Collection $queues): ?Queue;

    public function chooseEmployee(Collection $employees, ?DistributionCursor $cursor): ?Employee;
}
