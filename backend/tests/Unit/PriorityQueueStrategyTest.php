<?php

namespace Tests\Unit;

use App\Models\PriorityType;
use App\Models\Queue;
use App\Services\PriorityQueueStrategy;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PriorityQueueStrategyTest extends TestCase
{
    public function test_weighted_priority_wins_when_waiting_time_is_equal(): void
    {
        $normal = $this->queue('NORMAL', 0, now()->subSeconds(60));
        $priority = $this->queue('PRIORITY', 20, now()->subSeconds(60));

        $selected = (new PriorityQueueStrategy)->chooseQueue(new Collection([$normal, $priority]));

        $this->assertSame('PRIORITY', $selected->priorityType->priority_code);
    }

    public function test_aging_increases_the_score_of_a_waiting_normal_queue(): void
    {
        $strategy = new PriorityQueueStrategy;
        $fresh = $this->queue('NORMAL', 0, now()->subSeconds(10));
        $aged = $this->queue('NORMAL', 0, now()->subSeconds(601));

        $this->assertGreaterThan($strategy->score($fresh), $strategy->score($aged));
    }

    private function queue(string $code, int $weight, mixed $createdAt): Queue
    {
        $priority = new PriorityType(['priority_code' => $code, 'weight' => $weight, 'aging_rate' => 1, 'aging_interval_seconds' => 300]);
        $queue = new Queue(['sequence_no' => 1]);
        $queue->created_at = $createdAt;
        $queue->setRelation('priorityType', $priority);

        return $queue;
    }
}
