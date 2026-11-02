<?php

namespace App\Events;

class QueueStarted extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'queue.started';
    }
}
