<?php

namespace App\Events;

class QueueTransferred extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'queue.transferred';
    }
}
