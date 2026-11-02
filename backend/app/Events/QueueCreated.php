<?php

namespace App\Events;

class QueueCreated extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'queue.created';
    }
}
