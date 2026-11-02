<?php

namespace App\Events;

class QueueRecalled extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'queue.recalled';
    }
}
