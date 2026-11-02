<?php

namespace App\Events;

class QueueCalled extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'queue.called';
    }
}
