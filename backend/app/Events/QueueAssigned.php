<?php

namespace App\Events;

class QueueAssigned extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'queue.assigned';
    }
}
