<?php

namespace Tests\Unit;

use App\Events\QueueCalled;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\TestCase;

class BroadcastingEventContractTest extends TestCase
{
    public function test_queue_events_are_dispatched_after_commit(): void
    {
        $event = new QueueCalled('branch-1', ['queue_id' => 'queue-1', 'status' => 'CALLED']);

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame('queue.called', $event->broadcastAs());
        $this->assertSame('private-branch.branch-1', $event->broadcastOn()[0]->name);
    }
}
