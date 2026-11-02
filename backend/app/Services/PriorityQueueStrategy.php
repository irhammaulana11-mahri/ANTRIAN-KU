<?php

namespace App\Services;

use App\Models\Queue;
use Illuminate\Support\Collection;

class PriorityQueueStrategy extends RoundRobinStrategy
{
    public function chooseQueue(Collection $queues): ?Queue
    {
        return $queues->sort(function (Queue $left, Queue $right): int {
            $leftScore = $this->score($left);
            $rightScore = $this->score($right);
            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }
            $leftCreated = $left->created_at?->getTimestamp() ?? 0;
            $rightCreated = $right->created_at?->getTimestamp() ?? 0;

            return [$leftCreated, $left->sequence_no] <=> [$rightCreated, $right->sequence_no];
        })->first();
    }

    public function score(Queue $queue): int
    {
        $priority = $queue->priorityType;
        $weight = (int) ($priority?->weight ?? 0);
        $rate = max(0, (int) ($priority?->aging_rate ?? 1));
        $interval = max(1, (int) ($priority?->aging_interval_seconds ?? 300));
        $waitSeconds = max(0, now()->timestamp - ($queue->created_at?->timestamp ?? now()->timestamp));

        return $weight + (int) floor($waitSeconds / $interval) * $rate;
    }
}
