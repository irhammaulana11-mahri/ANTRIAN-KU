<?php

namespace App\Events;

class QueueCompleted extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'queue.completed';
    }
}
